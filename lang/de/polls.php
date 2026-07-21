<?php

return [
    'status' => [
        'draft' => 'Entwurf',
        'open' => 'Offen',
        'closed' => 'Geschlossen',
    ],

    'kind' => [
        'standard' => 'Standard',
        'mvp' => 'Spieler:in des Abends',
    ],

    'mvp' => [
        'question' => 'Wer war Spieler:in des Abends?',
        'reveal_title' => 'Spieler:in des Abends',
        'reveal_action' => 'Ergebnis auf dem Beamer zeigen',
        'revealed' => 'Spieler:in des Abends wurde auf dem Beamer enthüllt.',
    ],

    'errors' => [
        'not_open' => 'Diese Umfrage ist derzeit nicht für Abstimmungen geöffnet.',
        'already_voted' => 'Du hast bei dieser Umfrage bereits abgestimmt.',
        'option_not_in_poll' => 'Die gewählte Option gehört nicht zu dieser Umfrage.',
        'already_open' => 'Diese Umfrage ist bereits geöffnet.',
        'not_open_yet' => 'Diese Umfrage wurde noch nicht geöffnet.',
        'already_closed' => 'Diese Umfrage ist bereits geschlossen.',
        'mvp_poll_exists' => 'Für dieses Event gibt es bereits eine Abstimmung zur Spieler:in des Abends.',
        'not_closed_mvp_poll' => 'Diese Abstimmung ist nicht die geschlossene Spieler:in-des-Abends-Abstimmung dieses Events.',
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

    'page' => [
        'title' => 'Abstimmung',
        'index_title' => 'Abstimmungen',
        'no_polls' => 'Für dieses Event gibt es aktuell keine Abstimmungen.',
        'back_to_index' => 'Zurück zu allen Abstimmungen',
        'vote' => 'Abstimmen',
        'you_voted' => 'Du hast abgestimmt.',
        'closed' => 'Diese Abstimmung ist geschlossen.',
        'total_votes' => 'Stimmen gesamt',
    ],
];
