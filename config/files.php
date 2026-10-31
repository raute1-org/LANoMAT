<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LAN file-sharing
    |--------------------------------------------------------------------------
    |
    | Files module (roadmap 7.3): per-event per-user storage quota and
    | single-upload size cap in megabytes. Raising FILES_MAX_UPLOAD_MB also
    | needs PHP upload_max_filesize/post_max_size (docker/frankenphp/php.ini,
    | 1G today), the proxy request timeout and any body limit in front raised
    | (docs/infra/file-host.md, "Upload size").
    |
    */

    'per_user_quota_mb' => env('FILES_PER_USER_QUOTA_MB', 500),

    'max_upload_mb' => env('FILES_MAX_UPLOAD_MB', 200),

    /*
    |--------------------------------------------------------------------------
    | Blocked file types
    |--------------------------------------------------------------------------
    |
    | Everything may be shared (.exe, .msi, .iso, ...) EXCEPT types a browser
    | could execute from our own origin. Safety for the rest comes from the
    | moderation gate (new files stay pending until orga approves) and from
    | delivering every download as an opaque attachment with
    | X-Content-Type-Options: nosniff. A file is refused when its extension,
    | its sniffed MIME or its client-sent MIME is listed.
    |
    */

    'blocked_extensions' => ['html', 'htm', 'xhtml', 'xht', 'shtml', 'svg', 'svgz', 'js', 'mjs'],

    'blocked_mimes' => [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'text/javascript',
        'application/javascript',
        'application/x-javascript',
        'text/ecmascript',
        'application/ecmascript',
    ],

    /*
    |--------------------------------------------------------------------------
    | Where new uploads go
    |--------------------------------------------------------------------------
    |
    | `local` (default): a Laravel disk, streamed by the app. `host`: forwarded
    | over SFTP to a registered Hosts-module RemoteHost (the LanCache machine)
    | and downloaded from its nginx through a short-lived signed redirect.
    | Each row remembers its store (shared_files.disk), so switching never
    | strands old files. See docs/infra/file-host.md.
    |
    */

    'store' => env('FILES_STORE', 'local'),

    'host' => [
        // Id of the RemoteHost (admin: Hosts) that receives the files.
        'id' => env('FILES_HOST_ID'),
        // Directory on that host, as the SFTP account sees it (inside its chroot).
        'path' => env('FILES_HOST_PATH', '/data'),
        // Public base URL of the nginx file host, e.g. https://files.<LAN_DOMAIN>
        'url' => env('FILES_HOST_URL'),
        // Shared with the nginx config; >= 32 chars of [A-Za-z0-9_-]. Never in URLs or logs.
        'secret' => env('FILES_HOST_SECRET'),
        // How long an issued download link is valid (it only has to start the download).
        'url_ttl_seconds' => (int) env('FILES_URL_TTL_SECONDS', 300),
    ],

];
