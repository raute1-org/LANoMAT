// Thin discord.js gateway sidecar for LANoMAT (spec:
// docs/superpowers/specs/2026-07-21-discord-gateway-bot-design.md).
// Pure transport: holds the connection, sets presence, forwards every event
// to the Laravel ingress. No domain logic, no DB. discord.js handles
// heartbeat/resume/reconnect/rate-limits (Discord's own recommendation).
import { Client, Events, GatewayIntentBits, ActivityType } from 'discord.js';

const {
  DISCORD_BOT_TOKEN,
  DISCORD_GATEWAY_INGRESS_URL = 'http://app/internal/discord/gateway',
  DISCORD_GATEWAY_BRIDGE_SECRET,
  DISCORD_PRESENCE_STATUS = 'online',
  DISCORD_PRESENCE_ACTIVITY_TYPE = 'Watching',
  DISCORD_PRESENCE_ACTIVITY_NAME = 'LANoMAT',
} = process.env;

if (!DISCORD_BOT_TOKEN || !DISCORD_GATEWAY_BRIDGE_SECRET) {
  console.error('discord-gateway: DISCORD_BOT_TOKEN and DISCORD_GATEWAY_BRIDGE_SECRET are required');
  process.exit(1);
}

const client = new Client({
  intents: [
    GatewayIntentBits.Guilds,
    GatewayIntentBits.GuildVoiceStates,
    GatewayIntentBits.GuildMembers,      // privileged — enable in the portal
    GatewayIntentBits.GuildMessages,
    GatewayIntentBits.GuildMessageReactions,
  ],
  presence: {
    status: DISCORD_PRESENCE_STATUS,
    activities: [{ name: DISCORD_PRESENCE_ACTIVITY_NAME, type: ActivityType[DISCORD_PRESENCE_ACTIVITY_TYPE] ?? ActivityType.Watching }],
  },
});

async function postToIngress(type, data) {
  for (let attempt = 1; attempt <= 3; attempt++) {
    try {
      const res = await fetch(DISCORD_GATEWAY_INGRESS_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Gateway-Secret': DISCORD_GATEWAY_BRIDGE_SECRET },
        body: JSON.stringify({ type, data }),
        signal: AbortSignal.timeout(5000),
      });
      if (res.ok) return;
      console.error(`discord-gateway: ingress ${type} -> HTTP ${res.status}`);
    } catch (err) {
      console.error(`discord-gateway: ingress ${type} attempt ${attempt} failed: ${err}`);
    }
    await new Promise((r) => setTimeout(r, attempt * 500));
  }
  console.error(`discord-gateway: dropped ${type} after retries`);
}

client.once(Events.ClientReady, (c) => console.log(`discord-gateway: logged in as ${c.user.tag}`));

client.on(Events.InteractionCreate, async (interaction) => {
  if (!interaction.isChatInputCommand()) return;
  try {
    await interaction.deferReply();
    await postToIngress('interaction', {
      type: 2,
      token: interaction.token,
      application_id: interaction.applicationId,
      member: { user: { id: interaction.user.id } },
      user: { id: interaction.user.id },
      data: { name: interaction.commandName, options: interaction.options.data },
    });
  } catch (err) {
    console.error(`discord-gateway: interaction handling failed: ${err}`);
  }
});

client.on(Events.VoiceStateUpdate, (_old, ns) =>
  postToIngress('voice_state', { guild_id: ns.guild.id, user_id: ns.id, channel_id: ns.channelId, channel_name: ns.channel?.name ?? null }));
client.on(Events.GuildMemberAdd, (m) => postToIngress('member_add', { guild_id: m.guild.id, user_id: m.id }));
client.on(Events.GuildMemberRemove, (m) => postToIngress('member_remove', { guild_id: m.guild.id, user_id: m.id }));
client.on(Events.MessageCreate, (msg) => { if (msg.author.bot) return; postToIngress('message_create', { channel_id: msg.channelId, author_id: msg.author.id, message_id: msg.id }); });
client.on(Events.MessageReactionAdd, (r, u) => postToIngress('reaction', { message_id: r.message.id, channel_id: r.message.channelId, user_id: u.id, emoji: r.emoji.name, added: true }));
client.on(Events.MessageReactionRemove, (r, u) => postToIngress('reaction', { message_id: r.message.id, channel_id: r.message.channelId, user_id: u.id, emoji: r.emoji.name, added: false }));

client.login(DISCORD_BOT_TOKEN);
