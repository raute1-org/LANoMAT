<?php

return [
    'resource' => [
        'label' => 'Programmpunkt',
        'plural_label' => 'Zeitplan',
    ],
    'fields' => [
        'event' => 'Event',
        'type' => 'Typ',
        'title' => 'Titel',
        'description' => 'Beschreibung',
        'starts_at' => 'Beginn',
        'ends_at' => 'Ende',
        'location' => 'Ort',
        'sort' => 'Reihenfolge',
    ],
    'admin' => [
        'nav_group' => 'Event-Planung',
    ],
    'type' => [
        'custom' => 'Programmpunkt',
        'tournament' => 'Turnier',
        'catering' => 'Essen',
        'break' => 'Pause',
    ],
    'page' => [
        'title' => 'Programm',
        'now' => 'Jetzt',
        'next' => 'Gleich',
        'empty' => 'Noch kein Programm',
    ],
];
