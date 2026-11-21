// Thin discord.js gateway sidecar for LANoMAT (spec:
// docs/superpowers/specs/2026-07-21-discord-gateway-bot-design.md).
//
// The sidecar holds the persistent Gateway connection and forwards every event
// to the Laravel ingress. Transport stays in PHP for every normal event.
//
// The one exception is the helpful guest assistant (`/ask`, @mentions and DMs):
// it runs next to the Gateway because it needs the discord.js reply path, a
// short-lived `pi` process and the MCP bearer token. Everything it says is
// either pi's answer (MCP tools only) or the deterministic MCP fallback in
// agent.mjs — no invented ids, prices or links.
import { createServer } from 'node:http';
import { Client, Events, GatewayIntentBits, Partials, ActivityType } from 'discord.js';
import {
    DEFAULT_MAX_CHARS,
    DEFAULT_RATE_LIMIT,
    DEFAULT_RATE_WINDOW_MS,
    RateLimiter,
    classifyMessage,
    createAgent,
    isEnabled,
    splitMessage,
} from './agent.mjs';

const {
    DISCORD_BOT_TOKEN,
    DISCORD_GATEWAY_INGRESS_URL = 'http://app/internal/discord/gateway',
    DISCORD_GATEWAY_BRIDGE_SECRET,
    DISCORD_PRESENCE_STATUS = 'online',
    DISCORD_PRESENCE_ACTIVITY_TYPE = 'Watching',
    DISCORD_PRESENCE_ACTIVITY_NAME = 'LANoMAT',
    DISCORD_GATEWAY_HEALTH_PORT = '3000',
    DISCORD_AGENT_ENABLED = '',
    DISCORD_AGENT_GUILD_ID = '',
    DISCORD_AGENT_CHANNEL_ID = '',
    DISCORD_AGENT_RATE_LIMIT = '',
    DISCORD_AGENT_RATE_WINDOW_MS = '',
    DISCORD_AGENT_MAX_CHARS = '',
    DISCORD_GUILD_ID = '',
} = process.env;

if (!DISCORD_BOT_TOKEN || !DISCORD_GATEWAY_BRIDGE_SECRET) {
    console.error('discord-gateway: DISCORD_BOT_TOKEN and DISCORD_GATEWAY_BRIDGE_SECRET are required');
    process.exit(1);
}

const AGENT_ENABLED = isEnabled(DISCORD_AGENT_ENABLED);
const AGENT_GUILD_ID = DISCORD_AGENT_GUILD_ID || DISCORD_GUILD_ID;
const AGENT_CHANNEL_ID = DISCORD_AGENT_CHANNEL_ID;
const AGENT_MAX_CHARS = Math.max(200, Math.min(2000, Number(DISCORD_AGENT_MAX_CHARS) || DEFAULT_MAX_CHARS));
const agent = AGENT_ENABLED ? createAgent() : null;
const limiter = new RateLimiter({
    limit: Number(DISCORD_AGENT_RATE_LIMIT) || DEFAULT_RATE_LIMIT,
    windowMs: Number(DISCORD_AGENT_RATE_WINDOW_MS) || DEFAULT_RATE_WINDOW_MS,
});

const client = new Client({
    intents: [
        GatewayIntentBits.Guilds,
        GatewayIntentBits.GuildVoiceStates,
        GatewayIntentBits.GuildMembers,      // privileged — enable in the portal
        GatewayIntentBits.GuildMessages,
        GatewayIntentBits.GuildMessageReactions,
        GatewayIntentBits.DirectMessages,    // DMs to the bot
        GatewayIntentBits.MessageContent,    // privileged — enable in the portal
    ],
    // DMs arrive as partial channels; message/reaction partials keep older
    // cached targets usable (see README's reaction caveat).
    partials: [Partials.Channel, Partials.Message, Partials.Reaction],
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

            if (res.ok) {
                return;
            }

            console.error(`discord-gateway: ingress ${type} -> HTTP ${res.status}`);
        } catch (err) {
            console.error(`discord-gateway: ingress ${type} attempt ${attempt} failed: ${err}`);
        }

        await new Promise((r) => setTimeout(r, attempt * 500));
    }

    console.error(`discord-gateway: dropped ${type} after retries`);
}

/** Start Discord's typing indicator while the agent thinks. Returns a stop function. */
function startTyping(channel) {
    const send = () => channel?.sendTyping?.()?.catch?.(() => {});

    send();

    const timer = setInterval(send, 8000);

    timer.unref?.();

    return () => clearInterval(timer);
}

function allowedMentions() {
    // The answer is model/MCP text: never let it ping @everyone or roles.
    return { parse: [], repliedUser: false };
}

async function sendAnswerToMessage(message, text) {
    const chunks = splitMessage(text, AGENT_MAX_CHARS);

    if (chunks.length === 0) {
        return;
    }

    await message.reply({ content: chunks[0], allowedMentions: allowedMentions() });

    for (const chunk of chunks.slice(1)) {
        await message.channel.send({ content: chunk, allowedMentions: allowedMentions() });
    }
}

async function answerMessage(message, question, isDM) {
    const stopTyping = startTyping(message.channel);

    try {
        const answer = await agent.answer({
            text: question,
            author: message.author?.globalName || message.author?.username,
            isDM,
            channelName: message.channel?.name,
        });

        if (answer.source !== 'pi') {
            console.error(`discord-gateway: agent fallback for message ${message.id} (${answer.source}${answer.error ? `: ${answer.error}` : ''})`);
        }

        await sendAnswerToMessage(message, answer.text);
    } catch (error) {
        console.error(`discord-gateway: agent reply failed for message ${message.id}: ${error}`);
    } finally {
        stopTyping();
    }
}

async function handleAskInteraction(interaction) {
    if (!agent) {
        await interaction.reply({ content: 'Der LANoMAT-Assistent ist auf diesem Server nicht aktiviert.', ephemeral: true });

        return;
    }

    if ((AGENT_GUILD_ID && interaction.guildId !== AGENT_GUILD_ID)
        || (AGENT_CHANNEL_ID && interaction.channelId !== AGENT_CHANNEL_ID)) {
        const where = AGENT_CHANNEL_ID ? ` in <#${AGENT_CHANNEL_ID}>` : '';
        await interaction.reply({ content: `Frag mich${where}.`, ephemeral: true });

        return;
    }

    const limited = limiter.take(interaction.user.id);

    if (!limited.allowed) {
        const seconds = Math.ceil(limited.retryAfterMs / 1000);
        await interaction.reply({ content: `Zu viele Fragen. Bitte in ${seconds} s erneut versuchen.`, ephemeral: true });

        return;
    }

    await interaction.deferReply();

    const question = interaction.options.getString('frage', true);
    const stopTyping = startTyping(interaction.channel);

    try {
        const answer = await agent.answer({
            text: question,
            author: interaction.user.globalName || interaction.user.username,
            isDM: false,
            channelName: interaction.channel?.name,
        });

        if (answer.source !== 'pi') {
            console.error(`discord-gateway: agent fallback for /ask (${answer.source}${answer.error ? `: ${answer.error}` : ''})`);
        }

        const chunks = splitMessage(answer.text, AGENT_MAX_CHARS);

        await interaction.editReply({ content: chunks[0] ?? 'Keine Antwort erhalten.', allowedMentions: allowedMentions() });

        for (const chunk of chunks.slice(1)) {
            await interaction.followUp({ content: chunk, allowedMentions: allowedMentions() });
        }
    } catch (error) {
        console.error(`discord-gateway: /ask failed: ${error}`);

        await interaction.editReply({ content: 'Ich konnte LANoMAT gerade nicht erreichen. Bitte später erneut versuchen.' }).catch(() => {});
    } finally {
        stopTyping();
    }
}

/**
 * `/ask` is registered guild-only (not in PHP's global CommandDefinitions), so
 * it exists exactly in the configured guild and disappears when the agent is
 * turned off.
 */
async function registerAgentCommand(readyClient) {
    if (!agent || !AGENT_GUILD_ID) {
        return;
    }

    try {
        const guild = await readyClient.guilds.fetch(AGENT_GUILD_ID);
        const commands = await guild.commands.fetch();
        const definition = {
            name: 'ask',
            description: 'Frag den LANoMAT-Assistenten',
            options: [{
                type: 3, // STRING
                name: 'frage',
                description: 'Deine Frage zu Spielen, Installation, Servern oder Events',
                required: true,
            }],
        };
        const existing = commands.find((command) => command.name === 'ask');

        if (existing) {
            await existing.edit(definition);
        } else {
            await guild.commands.create(definition);
        }

        console.log(`discord-gateway: /ask registered in guild ${AGENT_GUILD_ID}`);
    } catch (error) {
        console.error(`discord-gateway: could not register /ask: ${error}`);
    }
}

const seenMessages = new Set();

function firstTime(messageId) {
    if (seenMessages.has(messageId)) {
        return false;
    }

    seenMessages.add(messageId);

    if (seenMessages.size > 1000) {
        seenMessages.delete(seenMessages.values().next().value);
    }

    return true;
}

client.once(Events.ClientReady, async (c) => {
    console.log(`discord-gateway: logged in as ${c.user.tag}`);

    if (agent) {
        console.log(`discord-gateway: agent enabled (guild ${AGENT_GUILD_ID || 'any'}, channel ${AGENT_CHANNEL_ID || 'any'}, model ${process.env.PI_MODEL || 'pi default'})`);
        await registerAgentCommand(c);
    }
});

client.on(Events.InteractionCreate, async (interaction) => {
    if (!interaction.isChatInputCommand() || interaction.user.bot) {
        return;
    }

    if (interaction.commandName === 'ask') {
        try {
            await handleAskInteraction(interaction);
        } catch (err) {
            console.error(`discord-gateway: /ask handling failed: ${err}`);
        }

        return;
    }

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

client.on(Events.MessageCreate, (msg) => {
    if (msg.author.bot || msg.system || msg.webhookId) {
        return;
    }

    if (!firstTime(msg.id)) {
        return;
    }

    const hit = classifyMessage(msg, {
        botId: client.user?.id,
        guildId: AGENT_GUILD_ID,
        channelId: AGENT_CHANNEL_ID,
    });
    const payload = { channel_id: msg.channelId, author_id: msg.author.id, message_id: msg.id };

    // Message content is privileged and only forwarded for messages addressed
    // to the assistant; every other message keeps the old ids-only envelope.
    if (hit.match) {
        Object.assign(payload, {
            content: msg.content,
            author: {
                id: msg.author.id,
                username: msg.author.username,
                global_name: msg.author.globalName ?? null,
                bot: false,
            },
            channel: {
                id: msg.channelId,
                name: msg.channel?.name ?? null,
                type: msg.channel?.type ?? null,
            },
            guild: msg.guild ? { id: msg.guild.id, name: msg.guild.name } : null,
            mentions: msg.mentions.users.map((user) => ({ id: user.id, username: user.username })),
            isDM: hit.isDM,
        });
    }

    void postToIngress('message_create', payload);

    if (!agent || !hit.match) {
        return;
    }

    const limited = limiter.take(msg.author.id);

    if (!limited.allowed) {
        const seconds = Math.ceil(limited.retryAfterMs / 1000);

        msg.reply({ content: `Zu viele Fragen. Bitte in ${seconds} s erneut versuchen.`, allowedMentions: allowedMentions() })
            .catch((error) => console.error(`discord-gateway: rate-limit reply failed: ${error}`));

        return;
    }

    if (hit.question === '') {
        msg.reply({ content: 'Schreib mir eine Frage, z. B. „Installiere UT2004“ oder „Welche Server gibt es?“.', allowedMentions: allowedMentions() })
            .catch((error) => console.error(`discord-gateway: empty-question reply failed: ${error}`));

        return;
    }

    void answerMessage(msg, hit.question, hit.isDM);
});

client.on(Events.MessageReactionAdd, (r, u) => postToIngress('reaction', { message_id: r.message.id, channel_id: r.message.channelId, user_id: u.id, emoji: r.emoji.name, added: true }));
client.on(Events.MessageReactionRemove, (r, u) => postToIngress('reaction', { message_id: r.message.id, channel_id: r.message.channelId, user_id: u.id, emoji: r.emoji.name, added: false }));

// Health for the app's preflight: 200 only while the Gateway connection is up, 503 otherwise
// (a process that runs but is cut off from Discord must not look healthy).
createServer((req, res) => {
    if (req.url !== '/healthz') {
        res.writeHead(404).end();

        return;
    }

    const ready = client.isReady();
    res.writeHead(ready ? 200 : 503, { 'Content-Type': 'text/plain' }).end(ready ? 'ok' : 'not connected');
}).listen(Number(DISCORD_GATEWAY_HEALTH_PORT));

client.login(DISCORD_BOT_TOKEN);
