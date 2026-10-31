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
    'validation' => [
        'ends_after_starts' => 'Das Ende darf nicht vor dem Beginn liegen.',
    ],
    'type' => [
        'custom' => 'Programmpunkt',
        'tournament' => 'Turnier',
        'catering' => 'Essen',
        'break' => 'Pause',
    ],
    'page' => [
        'title' => 'Zeitplan',
        'now' => 'Jetzt',
        'next' => 'Gleich',
        'empty' => 'Noch kein Zeitplan',
        'favorite' => 'Merken',
        'unfavorite' => 'Nicht mehr merken',
        'only_mine' => 'Nur meine Favoriten',
        'all_items' => 'Alle Programmpunkte',
        'no_favorites' => 'Du hast noch keine Programmpunkte gemerkt. Tippe auf den Stern, um einen Punkt zu merken.',
        'add_to_calendar' => 'Zum Kalender hinzufügen (.ics)',
        'add_favorites_to_calendar' => 'Nur Favoriten zum Kalender (.ics)',
        'ends_next_day' => 'am Folgetag',
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
        'modified' => [
            'title' => 'Programmpunkt geändert',
            'body' => ':title wurde geändert (:what). Beginn: :time Uhr.',
            'discord' => ':title wurde geändert (:what). Beginn: :time Uhr.',
        ],
        'removed' => [
            'title' => 'Programmpunkt entfällt',
            'body' => ':title entfällt.',
            'discord' => ':title entfällt.',
        ],
        'field' => [
            'starts_at' => 'Beginn',
            'ends_at' => 'Ende',
            'title' => 'Titel',
            'location' => 'Ort',
        ],
    ],
];
