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
];
