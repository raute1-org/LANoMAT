<?php

return [
    'resource' => [
        'label' => 'Custom-Server',
        'plural_label' => 'Custom-Server',
    ],

    'status' => [
        'stopped' => 'Gestoppt',
        'starting' => 'Startet',
        'running' => 'Läuft',
        'failed' => 'Fehlgeschlagen',
    ],

    'fields' => [
        'name' => 'Name',
        'host' => 'Remote-Host',
        'event' => 'Event',
        'image' => 'Docker-Image',
        'command' => 'Befehl',
        'ports' => 'Ports',
        'env' => 'Umgebungsvariablen',
        'container_name' => 'Container-Name',
        'status' => 'Status',
        'last_output' => 'Letzte Ausgabe',
    ],

    'actions' => [
        'start' => 'Starten',
        'stop' => 'Stoppen',
        'probe' => 'Prüfen',
        'started' => 'Server gestartet.',
        'stopped' => 'Server gestoppt.',
        'start_failed' => 'Server konnte nicht gestartet werden.',
        'stop_failed' => 'Server konnte nicht gestoppt werden.',
    ],
];
