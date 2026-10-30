<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Display / input timezone
    |--------------------------------------------------------------------------
    |
    | Storage stays UTC (`app.timezone`) so existing rows never shift. Events
    | are German, so everything a person reads or types — arrival days, Filament
    | date-time inputs/columns, Vue date formatting, notification times — uses
    | this zone instead. Use `App\Support\DisplayTimezone` in PHP.
    |
    */

    'timezone' => env('APP_DISPLAY_TIMEZONE', 'Europe/Berlin'),

    /*
    |--------------------------------------------------------------------------
    | Request body size limits
    |--------------------------------------------------------------------------
    |
    | Per-route-class caps on the request body, in MiB. Enforced twice: by
    | Traefik's `buffering` middleware (docker/traefik/dynamic.yml, routers in
    | compose.yml; the primary layer in production) and by
    | `App\Http\Middleware\LimitRequestBodySize` (Content-Length check, for
    | deployments/dev where Traefik is bypassed). PHP's own post_max_size
    | (docker/frankenphp/php.ini, 1G) is only the global ceiling for the
    | largest class below. A Pest test keeps these numbers and the Traefik
    | file in sync.
    |
    | `routes` maps a route NAME to the tier that applies; every other route
    | gets `default_mb`.
    |
    */

    'limits' => [
        'default_mb' => (int) env('LIMIT_BODY_DEFAULT_MB', 10),
        // Gallery: up to 25 MB per photo (gallery.max_upload_mb), several per request.
        'gallery_mb' => (int) env('LIMIT_BODY_GALLERY_MB', 100),
        // Shared files: files.max_upload_mb (200) plus multipart overhead.
        'files_mb' => (int) env('LIMIT_BODY_FILES_MB', 210),
        // Livewire temporary uploads (every Filament FileUpload, incl. voice
        // installers): livewire.temporary_file_upload.rules max 1 GB plus overhead.
        'large_mb' => (int) env('LIMIT_BODY_LARGE_MB', 1032),
        'routes' => [
            'gallery.store' => 'gallery_mb',
            'files.store' => 'files_mb',
            'livewire.upload-file' => 'large_mb',
        ],
    ],

];
