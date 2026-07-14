<?php

return [
    'resource' => [
        'label' => 'Event',
        'plural_label' => 'Events',
    ],
    'fields' => [
        'name' => 'Name',
        'location' => 'Ort',
        'starts_at' => 'Beginn',
        'ends_at' => 'Ende',
        'max_participants' => 'Max. Teilnehmer',
        'status' => 'Status',
    ],
    'status' => [
        'draft' => 'Entwurf',
        'announced' => 'Angekündigt',
        'registration' => 'Anmeldung offen',
        'live' => 'Live',
        'finished' => 'Beendet',
        'archived' => 'Archiviert',
    ],
    'transition' => [
        'announced' => 'Ankündigen',
        'registration' => 'Anmeldung öffnen',
        'live' => 'Event starten',
        'finished' => 'Event beenden',
        'archived' => 'Archivieren',
        'done' => 'Status geändert auf: :status',
    ],

    'page' => [
        'title' => 'LAN-Party',
        'no_current_event' => 'Aktuell ist keine LAN angekündigt.',
        'when' => 'Wann',
        'where' => 'Wo',
        'archive_title' => 'Vergangene LANs',
        'archive_empty' => 'Noch keine vergangenen Events.',
        'to_archive' => 'Zum Archiv',
        'cta' => [
            'announced' => 'Bald geht die Anmeldung los',
            'registration' => 'Jetzt anmelden',
            'live' => 'Event läuft',
        ],
    ],
];
