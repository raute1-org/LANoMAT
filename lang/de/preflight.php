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
    ],
    'messages' => [
        'not_configured' => 'Nicht konfiguriert.',
        'storage_mismatch' => 'Schreibprobe stimmte nicht überein.',
        'failed_jobs' => ':count fehlgeschlagene(r) Job(s).',
        'stale' => 'Kein aktuelles Lebenszeichen.',
    ],
];
