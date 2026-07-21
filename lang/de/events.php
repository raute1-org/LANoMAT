<?php

return [
    'resource' => [
        'label' => 'Event',
        'plural_label' => 'Events',
    ],
    'fields' => [
        'name' => 'Name',
        'location' => 'Ort',
        'arrival_info' => 'Anreise-Infos',
        'starts_at' => 'Beginn',
        'ends_at' => 'Ende',
        'max_participants' => 'Max. Teilnehmer',
        'status' => 'Status',
        'public_url' => 'Öffentlicher Link',
        'url_copied' => 'Link kopiert',
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

    'notifications' => [
        'registration_opened' => [
            'title' => 'Anmeldung geöffnet',
            'body' => 'Die Anmeldung für :event ist jetzt geöffnet!',
        ],
    ],

    'page' => [
        'title' => 'LAN-Party',
        'no_current_event' => 'Aktuell ist keine LAN angekündigt.',
        'when' => 'Wann',
        'where' => 'Wo',
        'archive_title' => 'Vergangene LANs',
        'archive_empty' => 'Noch keine vergangenen Events.',
        'to_archive' => 'Zum Archiv',
        'to_presence' => 'Wer ist da?',
        'to_jukebox' => 'Zur Jukebox',
        'to_gallery' => 'Zur Galerie',
        'news_heading' => 'Neuigkeiten',
        'cta' => [
            'announced' => 'Bald geht die Anmeldung los',
            'registration' => 'Jetzt anmelden',
            'live' => 'Event läuft',
        ],
        'login_to_register' => 'Zum Anmelden einloggen',
        'countdown' => [
            'heading' => 'Countdown',
            'running' => 'Countdown läuft',
            'days' => 'Tage',
            'hours' => 'Std',
            'minutes' => 'Min',
            'seconds' => 'Sek',
        ],
        'who_is_coming' => 'Wer kommt',
        'who_is_coming_count' => 'Anmeldungen',
        'active_poll_teaser' => 'Es läuft eine Abstimmung',
        'to_poll' => 'Zur Abstimmung',
        'arrival_heading' => 'Anreise',
    ],
];
