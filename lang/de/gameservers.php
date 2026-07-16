<?php

return [
    'server_link_status' => [
        'pending' => 'Ausstehend',
        'provisioning' => 'Wird angelegt',
        'ready' => 'Bereit',
        'failed' => 'Fehlgeschlagen',
        'stopped' => 'Gestoppt',
    ],
    'errors' => [
        'no_pelican_egg' => 'Für dieses Spiel ist kein Pelican-Egg hinterlegt.',
        'provisioning_exhausted' => 'Der Spielserver wurde nicht rechtzeitig bereit.',
    ],
    'match_page' => [
        'heading' => 'Spielserver',
        'connect' => 'Verbinden',
        'copy' => 'Kopieren',
        'copied' => 'Kopiert!',
        'address_label' => 'Adresse',
        'port_label' => 'Port',
    ],
    'resource' => [
        'label' => 'Spielserver',
        'plural_label' => 'Spielserver',
    ],
    'fields' => [
        'match_or_tournament' => 'Match / Turnier',
        'match_label' => 'Match #:id',
        'tournament_label' => 'Turnier #:id',
        'pelican_server_id' => 'Pelican-Server-ID',
        'status' => 'Status',
        'address' => 'Adresse',
        'port' => 'Port',
        'password' => 'Passwort',
        'connect_string' => 'Verbindungsstring',
        'manual' => 'Manuell gepflegt',
        'pelican_panel' => 'Pelican-Panel',
        'open_in_pelican' => 'Im Pelican-Panel öffnen',
    ],
    'actions' => [
        'start' => 'Starten',
        'stop' => 'Stoppen',
        'restart' => 'Neustarten',
        'deprovision' => 'Deprovisionieren',
        'deprovisioned' => 'Server wurde gestoppt und entfernt.',
        'power_sent' => 'Befehl wurde gesendet.',
        'power_failed' => 'Der Befehl konnte nicht gesendet werden.',
    ],
];
