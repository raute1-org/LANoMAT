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
        'favorite' => 'Merken',
        'unfavorite' => 'Nicht mehr merken',
    ],
    'notify' => [
        'starting_soon' => [
            'title' => 'Gleich geht es los',
            'body' => ':title beginnt um :time Uhr.',
            'discord' => ':title beginnt um :time Uhr.',
        ],
        'changed' => [
            'title' => 'Programmpunkt verschoben',
            'body' => ':title wurde auf :time Uhr verschoben.',
            'discord' => ':title wurde auf :time Uhr verschoben.',
        ],
    ],
];
