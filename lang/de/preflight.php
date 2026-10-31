<?php

return [
    'checks' => [
        'database' => 'Datenbank',
        'redis' => 'Redis',
        'storage' => 'Dateispeicher',
        'failed_jobs' => 'Fehlgeschlagene Jobs',
        'reverb' => 'Reverb (Echtzeit)',
        'scheduler' => 'Scheduler',
        'queue_worker' => 'Queue-Worker',
        'discord_api' => 'Discord-API',
        'discord_gateway' => 'Discord-Gateway-Bot',
        'mumble' => 'Mumble',
        'teamspeak' => 'TeamSpeak',
        'pelican' => 'Pelican (Gameserver)',
        'music_assistant' => 'Music Assistant',
        'backup' => 'Backup',
    ],
    'messages' => [
        'not_configured' => 'Nicht konfiguriert.',
        'gateway_not_connected' => 'Der Bot ist nicht mit Discord verbunden (HTTP :status).',
        'storage_mismatch' => 'Schreibprobe stimmte nicht überein.',
        'failed_jobs' => ':count fehlgeschlagene(r) Job(s).',
        'stale' => 'Kein aktuelles Lebenszeichen.',
        'backup_missing' => 'Kein Backup-Marker gefunden. Läuft das Backup-Skript?',
        'backup_unreadable' => 'Backup-Marker ist unlesbar.',
        'backup_age' => 'Letztes erfolgreiches Backup vor :hours Stunden.',
    ],
    'status' => [
        'ok' => 'OK',
        'warn' => 'Warnung',
        'down' => 'Ausfall',
        'skipped' => 'Übersprungen',
    ],
    'bell' => [
        'title' => 'Systemwarnung',
        'body' => 'Diese Systeme brauchen Aufmerksamkeit: :systems.',
    ],
];
