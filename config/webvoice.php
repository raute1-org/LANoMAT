<?php

/*
| Browser voice chat (WebVoice module, self-hosted LiveKit SFU). Design note:
| docs/superpowers/specs/2026-10-31-web-voice-design.md, runbook:
| docs/infra/livekit-web-voice.md. Every value is empty by default; real keys
| live only in the deployment's .env.
*/
return [
    // Browser-facing signaling URL, wss:// on a trusted certificate (a page served over
    // https cannot open ws://, and getUserMedia needs a secure context anyway).
    'url' => env('LIVEKIT_URL'),

    // Server-to-server base URL of the SFU's HTTP API (token-signed Twirp calls). Internal only.
    'api_url' => env('LIVEKIT_API_URL', 'http://livekit:7880'),

    'api_key' => env('LIVEKIT_API_KEY'),
    'api_secret' => env('LIVEKIT_API_SECRET'),

    // A join token is only used while connecting, so it lives for minutes, not hours.
    'token_ttl' => (int) env('LIVEKIT_TOKEN_TTL', 600),

    // Prefix of the LiveKit room name (`<prefix><channel id>`); the id is the only variable part.
    'room_prefix' => env('LIVEKIT_ROOM_PREFIX', 'lanomat-'),

    // Server API calls must fail fast: they never sit in the media path.
    'timeout' => (int) env('LIVEKIT_TIMEOUT', 3),

    'chat' => [
        'max_length' => 500,
        'history' => 50,
        // Messages per user per window, enforced by the `web-voice-chat` rate limiter.
        'rate_limit' => 10,
        'rate_window' => 10,
    ],

    'lifecycle' => [
        // While someone is still in a closing room, look again after this many minutes.
        'recheck_minutes' => 2,
        // Archived match channels (with their messages) are deleted after this many days.
        'prune_after_days' => 7,
    ],

    // Audio publish defaults sent to the browser with every token. Rationale for each number:
    // the "Latency choices" table of the design note.
    'audio' => [
        'bitrate' => 24000,      // AudioPresets.speech: Opus mono speech, small packets
        'dtx' => true,           // do not send silence
        'red' => true,           // redundant audio hides single losses without a NACK round trip
        'echo_cancellation' => true,
        'noise_suppression' => true,
        'auto_gain_control' => true,
    ],
];
