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
        'announce_channel_id' => env('DISCORD_ANNOUNCE_CHANNEL_ID'),
        'match_category_id' => env('DISCORD_MATCH_CATEGORY_ID'),
        'match_channel_cleanup_delay_minutes' => env('DISCORD_MATCH_CHANNEL_CLEANUP_DELAY_MINUTES', 30),
        'public_key' => env('DISCORD_PUBLIC_KEY'),
        'application_id' => env('DISCORD_APPLICATION_ID'),
    ],

    'mumble' => [
        'host' => env('MUMBLE_HOST', 'localhost'),
        'port' => env('MUMBLE_PORT', 64738),
        'rest_url' => env('MUMBLE_ADMIN_REST_URL', 'http://mumble-admin:8000'),
        'ice_secret' => env('MUMBLE_ICE_SECRET'),
        'server_password' => env('MUMBLE_SERVER_PASSWORD'),
    ],

    'pelican' => [
        'panel_url' => env('PELICAN_PANEL_URL'),
        'application_token' => env('PELICAN_APPLICATION_TOKEN'),
        'client_token' => env('PELICAN_CLIENT_TOKEN'),
        'node_id' => env('PELICAN_NODE_ID'),
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
    ],

    'hosts' => [
        'connect_timeout' => (int) env('HOSTS_SSH_TIMEOUT', 10),
        'strict_host_key' => (bool) env('HOSTS_STRICT_HOST_KEY', true),
    ],

];
