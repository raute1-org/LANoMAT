<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | CSP_MODE:
    |   report-only  send `Content-Security-Policy-Report-Only` (never blocks;
    |                violations are POSTed to /csp-report and logged to
    |                storage/logs/csp.log) — the default
    |   enforce      send the enforcing `Content-Security-Policy` header
    |   off          send nothing
    | Any other value behaves like report-only (fail safe: never enforce by
    | accident). See docs/deploy-lan-raute1.md, "Security headers".
    |
    */
    'csp' => [
        'mode' => env('CSP_MODE', 'report-only'),

        'report_path' => '/csp-report',

        // Hosts the browser may navigate to when a form is submitted (OAuth
        // redirects after a POST). `form-action` otherwise is 'self' only.
        'form_action_hosts' => [
            'https://discord.com',
            'https://steamcommunity.com',
            'https://id.twitch.tv',
        ],
    ],

];
