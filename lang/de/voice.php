<?php

declare(strict_types=1);

return [
    'join' => [
        'heading' => 'Voice beitreten',
        'default_hint' => 'Empfohlen für dieses Team',
        'occupants_label' => 'Personen im Kanal',
    ],

    'setup' => [
        'title' => 'Voice einrichten',
        'intro' => 'Verbinde dich mit dem LAN-Voice-Server und lade dir bei Bedarf den passenden Client herunter.',
        'connect' => 'Verbinden',
        'server_address' => 'Serveradresse',
        'installers_heading' => 'Client herunterladen',
        'installers_empty' => 'Für diese Plattform ist noch kein Installer hinterlegt — frag die Orga oder lade den offiziellen Client direkt beim Anbieter herunter.',
        'download' => 'Herunterladen',
        'version_label' => 'Version',
        'teamspeak_eula_note' => 'Der TeamSpeak-Client unterliegt der TeamSpeak-EULA. Für die Weitergabe im privaten LAN-Kreis ist das unkritisch, wird hier aber bewusst vermerkt.',
        'load_error' => 'Die Voice-Einrichtung konnte nicht geladen werden.',
        'empty' => 'Aktuell ist kein Voice-Anbieter aktiv.',
    ],

    'resource' => [
        'label' => 'Voice-Client-Installer',
        'plural_label' => 'Voice-Client-Installer',
        'fields' => [
            'provider' => 'Anbieter',
            'platform' => 'Plattform',
            'version' => 'Version',
            'original_name' => 'Dateiname',
            'installer_upload' => 'Installer-Datei',
            'installer_upload_help' => 'Beim Bearbeiten leer lassen, um die vorhandene Datei zu behalten.',
            'is_current' => 'Aktuelle Version',
            'is_current_help' => 'Markiert diesen Installer als aktuelle Version für Anbieter + Plattform; eine zuvor aktuelle Version wird automatisch deaktiviert.',
        ],
        'actions' => [
            'make_current' => 'Als aktuell markieren',
        ],
    ],
];
