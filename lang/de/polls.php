<?php

return [
    'status' => [
        'draft' => 'Entwurf',
        'open' => 'Offen',
        'closed' => 'Geschlossen',
    ],

    'errors' => [
        'not_open' => 'Diese Umfrage ist derzeit nicht für Abstimmungen geöffnet.',
        'already_voted' => 'Du hast bei dieser Umfrage bereits abgestimmt.',
        'option_not_in_poll' => 'Die gewählte Option gehört nicht zu dieser Umfrage.',
        'already_open' => 'Diese Umfrage ist bereits geöffnet.',
        'not_open_yet' => 'Diese Umfrage wurde noch nicht geöffnet.',
        'already_closed' => 'Diese Umfrage ist bereits geschlossen.',
    ],

    'resource' => [
        'label' => 'Umfrage',
        'plural_label' => 'Umfragen',
    ],

    'fields' => [
        'event' => 'Event',
        'question' => 'Frage',
        'status' => 'Status',
        'closes_at' => 'Schließt am',
        'votes_count' => 'Stimmen',
        'options' => 'Optionen',
        'option_label' => 'Bezeichnung',
        'option_sort' => 'Reihenfolge',
        'option_add' => 'Option hinzufügen',
    ],

    'admin' => [
        'actions' => [
            'open' => 'Öffnen',
            'opened' => 'Umfrage wurde geöffnet.',
            'close' => 'Schließen',
            'closed' => 'Umfrage wurde geschlossen.',
        ],
    ],
];
