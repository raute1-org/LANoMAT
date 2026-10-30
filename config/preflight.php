<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup freshness marker
    |--------------------------------------------------------------------------
    |
    | scripts/ops/backup.sh writes a Unix timestamp to this file after a fully
    | successful run. The app container sees it through a read-only bind mount
    | of /var/backups/lanomat (compose.yml, prod `app` service).
    |
    */
    'backup_marker_path' => env('BACKUP_MARKER_PATH', '/var/backups/lanomat/.last-success'),

];
