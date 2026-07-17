<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LAN file-sharing caps
    |--------------------------------------------------------------------------
    |
    | Per-event per-user storage quota and single-upload size cap for the
    | Files module (roadmap 7.3), both in megabytes, plus the mime allowlist
    | enforced by UploadSharedFile in addition to the request-level
    | validation rule.
    |
    */

    'per_user_quota_mb' => env('FILES_PER_USER_QUOTA_MB', 500),

    'max_upload_mb' => env('FILES_MAX_UPLOAD_MB', 200),

    'allowed_mimes' => [
        'application/zip',
        'application/x-zip-compressed',
        'application/x-7z-compressed',
        'application/x-rar-compressed',
        'application/vnd.rar',
        'application/gzip',
        'application/x-tar',
        'application/json',
        'text/plain',
        'text/xml',
        'application/xml',
        'application/octet-stream',
    ],

];
