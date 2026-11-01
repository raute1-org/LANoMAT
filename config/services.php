<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'discord' => [
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        'redirect' => env('DISCORD_REDIRECT_URI', env('APP_URL').'/auth/discord/callback'),
        'bot_token' => env('DISCORD_BOT_TOKEN'),
        'guild_id' => env('DISCORD_GUILD_ID'),
        // Login gate: when set, only members of this guild may log in (checked on
        // every login, admins exempt). Dedicated key on purpose: no fallback to
        // DISCORD_GUILD_ID. Empty = no restriction.
        'login_guild_id' => env('DISCORD_LOGIN_GUILD_ID'),
        'announce_channel_id' => env('DISCORD_ANNOUNCE_CHANNEL_ID'),
        'match_category_id' => env('DISCORD_MATCH_CATEGORY_ID'),
        'match_channel_cleanup_delay_minutes' => env('DISCORD_MATCH_CHANNEL_CLEANUP_DELAY_MINUTES', 30),
        'public_key' => env('DISCORD_PUBLIC_KEY'),
        'application_id' => env('DISCORD_APPLICATION_ID'),
        'gateway_bridge_secret' => env('DISCORD_GATEWAY_BRIDGE_SECRET'),
        'gateway_health_url' => env('DISCORD_GATEWAY_HEALTH_URL'),
    ],

    // Linked-account providers (M9, Identity+): Steam (OpenID, no client
    // secret required by the protocol itself, but SocialiteProviders' Steam
    // driver needs a Web API key passed as client_secret) and Twitch (OAuth2
    // with a token refresh lifecycle). See LinkedAccountProvider::linkable().
    'steam' => [
        'client_id' => null,
        'client_secret' => env('STEAM_API_KEY'),
        'redirect' => env('STEAM_REDIRECT_URI', env('APP_URL').'/settings/connections/steam/callback'),
        'allowed_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('STEAM_ALLOWED_HOSTS', '')),
        ))),
    ],

    'twitch' => [
        'client_id' => env('TWITCH_CLIENT_ID'),
        'client_secret' => env('TWITCH_CLIENT_SECRET'),
        'redirect' => env('TWITCH_REDIRECT_URI', env('APP_URL').'/settings/connections/twitch/callback'),
    ],

    'voice' => [
        'default_provider' => env('VOICE_DEFAULT_PROVIDER', 'mumble'),
        'providers' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('VOICE_PROVIDERS', 'mumble')),
        ))),
    ],

    'mumble' => [
        'host' => env('MUMBLE_HOST', 'localhost'),
        'port' => env('MUMBLE_PORT', 64738),
        'rest_url' => env('MUMBLE_ADMIN_REST_URL', 'http://mumble-admin:8000'),
        'ice_secret' => env('MUMBLE_ICE_SECRET'),
        'server_password' => env('MUMBLE_SERVER_PASSWORD'),
    ],

    'teamspeak' => [
        'host' => env('TEAMSPEAK_HOST', 'localhost'),
        'port' => env('TEAMSPEAK_PORT', 9987),
        'rest_url' => env('TEAMSPEAK_ADMIN_REST_URL', 'http://teamspeak-admin:8000'),
        'token' => env('TEAMSPEAK_ADMIN_TOKEN'),
    ],

    'pelican' => [
        // Panel base URL. `panel_url` is the original key (still read by the
        // preflight probe and the panel deep-link); `url` is the same value
        // under the name the rest of the config uses. Either env name works.
        'url' => env('PELICAN_URL', env('PELICAN_PANEL_URL')),
        'panel_url' => env('PELICAN_PANEL_URL', env('PELICAN_URL')),
        // Application API key (`papp_...`) for /api/application/*, and a
        // Client API key (`pacc_...`) of a NON-admin service user for
        // /api/client/* (see docs/gameservers-pelican.md).
        'application_token' => env('PELICAN_APPLICATION_TOKEN'),
        'client_token' => env('PELICAN_CLIENT_TOKEN'),
        // Panel user id that owns the servers LANoMAT creates — must be the
        // user whose client key is configured above.
        'owner_user_id' => env('PELICAN_OWNER_USER_ID'),
        // Node new servers are placed on (first free allocation is used).
        // `PELICAN_NODE_ID` is the legacy name of the same setting.
        'default_node_id' => env('PELICAN_DEFAULT_NODE_ID', env('PELICAN_NODE_ID')),
        'timeout' => (int) env('PELICAN_TIMEOUT', 10),
        'connect_timeout' => (int) env('PELICAN_CONNECT_TIMEOUT', 5),
        // Fallback limits for servers whose preset does not set them.
        'default_disk_mb' => (int) env('PELICAN_DEFAULT_DISK_MB', 5120),
        'default_cpu_percent' => (int) env('PELICAN_DEFAULT_CPU_PERCENT', 0),
        // Resource guardrails (roadmap 6.7): hard caps enforced by
        // GuardrailPolicy in the provisioning Job/Action, not only the UI —
        // a misconfigured preset/upload must never be able to freeze the
        // host box or let one user spawn an unbounded number of servers.
        'max_ram_mb' => (int) env('PELICAN_MAX_RAM_MB', 4096),
        'max_slots' => (int) env('PELICAN_MAX_SLOTS', 64),
        'max_servers_per_user' => (int) env('PELICAN_MAX_SERVERS_PER_USER', 3),
        // Node-wide cap on total running servers, across every requester —
        // bounds the AUTOMATIC provisioning path (ProvisionMatchServerJob,
        // requester=null), which max_servers_per_user cannot reach. Left
        // unset (null) by default: unlike the other caps, there is no safe
        // one-size-fits-all node capacity, so this is opt-in per deployment.
        'max_running_servers' => env('PELICAN_MAX_RUNNING_SERVERS') !== null
            ? (int) env('PELICAN_MAX_RUNNING_SERVERS')
            : null,
        // Ports LANoMAT may hand to game servers: a comma separated list of ports and
        // `from-to` ranges. Servers only ever get a free panel allocation inside it.
        'port_pool' => env('PELICAN_PORT_POOL', '27015-27050'),
        // Optional: create missing pool allocations through the Application API when
        // the pool runs dry. Needs a key with write scope on allocations (a plain key
        // gets 403 and provisioning then reports "no free allocation"). Never required.
        'create_allocations' => (bool) env('PELICAN_CREATE_ALLOCATIONS', false),
        // Address for created allocations; defaults to the address of the node's
        // existing allocations.
        'allocation_ip' => env('PELICAN_ALLOCATION_IP'),
        // Secrets handed to presets as hidden egg variables, never stored in a preset:
        // Steam Web API key (-authkey, Workshop collections) and one Game Server Login
        // Token (a GSLT is valid for one server at a time).
        'steam_web_api_key' => env('PELICAN_STEAM_WEB_API_KEY'),
        'gslt' => env('PELICAN_GSLT'),
        // Seconds to wait for a started server to answer its A2S query (presets can override).
        'readiness_timeout' => (int) env('PELICAN_READINESS_TIMEOUT', 180),
        // Seconds a preset server may take to install (and reinstall once) before it counts as failed.
        'install_timeout' => (int) env('PELICAN_INSTALL_TIMEOUT', 900),
    ],

    'hosts' => [
        'connect_timeout' => (int) env('HOSTS_SSH_TIMEOUT', 10),
        'strict_host_key' => (bool) env('HOSTS_STRICT_HOST_KEY', true),
    ],

    // Music Assistant (M11, Jukebox). MUSIC_ASSISTANT_TOKEN is a long-lived
    // bearer token for MA's HTTP/JSON-RPC surface at MUSIC_ASSISTANT_URL
    // (default port 8095); MUSIC_ASSISTANT_PLAYER_ID is the MA player/queue
    // id LANoMAT's single configured jukebox player controls.
    'room_audio' => [
        'rest_url' => env('DENON_ADMIN_REST_URL', 'http://denon-admin:8000'),
        'token' => env('DENON_ADMIN_TOKEN'),
    ],

    'music_assistant' => [
        'base_url' => env('MUSIC_ASSISTANT_URL', 'http://music-assistant:8095'),
        'token' => env('MUSIC_ASSISTANT_TOKEN'),
        'player_id' => env('MUSIC_ASSISTANT_PLAYER_ID'),
    ],

    // LanCache (M7, roadmap 7.5): parametrizes the `docker run` bootstrap
    // ApplyLancacheSetup builds and runs over SSH on a role=lancache
    // RemoteHost — see docs/lancache-setup.md for the exact command shape.
    // Defaults here mirror ApplyLancacheSetup's own inline fallbacks
    // (config('services.lancache.X', <default>)), duplicated as the actual
    // config values so they are env-overridable rather than only ever
    // falling back silently.
    'lancache' => [
        'image' => env('LANCACHE_IMAGE', 'lancachenet/monolithic:latest'),
        'upstream_dns' => env('LANCACHE_UPSTREAM_DNS', '8.8.8.8'),
        'cache_volume' => env('LANCACHE_CACHE_VOLUME', 'lancache_data'),
    ],

];
