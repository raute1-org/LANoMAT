<?php

return [
    'resource' => [
        'label' => 'Remote-Host',
        'plural_label' => 'Remote-Hosts',
    ],

    'host_role' => [
        'lancache' => 'LanCache',
        'gameserver' => 'Spielserver',
        'generic' => 'Allgemein',
    ],

    'host_status' => [
        'unknown' => 'Unbekannt',
        'reachable' => 'Erreichbar',
        'unreachable' => 'Nicht erreichbar',
    ],

    'fields' => [
        'name' => 'Name',
        'hostname' => 'Hostname / IP',
        'ssh_port' => 'SSH-Port',
        'ssh_user' => 'SSH-Benutzer',
        'ssh_private_key' => 'SSH-Private-Key',
        'ssh_private_key_placeholder' => 'hinterlegt',
        'ssh_private_key_help' => 'Wird verschlüsselt gespeichert und nie wieder im Klartext angezeigt. Beim Bearbeiten leer lassen, um den bestehenden Schlüssel zu behalten.',
        'host_fingerprint' => 'Host-Fingerprint',
        'role' => 'Rolle',
        'event' => 'Event',
        'status' => 'Status',
        'last_probed_at' => 'Zuletzt geprüft',
    ],

    'errors' => [
        'invalid_private_key' => 'Der angegebene SSH-Private-Key ist leer oder ungültig formatiert.',
        'connect_failed' => 'Die Verbindung zum Remote-Host ist fehlgeschlagen.',
        'fingerprint_mismatch' => 'Der Host-Fingerprint stimmt nicht mit dem hinterlegten Fingerprint überein.',
        'command_failed' => 'Der Befehl konnte auf dem Remote-Host nicht ausgeführt werden.',
    ],
];
