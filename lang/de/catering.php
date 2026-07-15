<?php

return [
    'status' => [
        'draft' => 'Entwurf',
        'open' => 'Offen',
        'closed' => 'Geschlossen',
    ],

    'errors' => [
        'not_open' => 'Die Bestellung ist derzeit nicht geöffnet.',
        'unknown_option' => 'Die gewählte Option ist nicht Teil dieser Bestellung.',
        'invalid_transition' => 'Ungültiger Statuswechsel.',
    ],

    'resource' => [
        'label' => 'Essensbestellung',
        'plural_label' => 'Essensbestellungen',
    ],

    'fields' => [
        'event' => 'Event',
        'title' => 'Titel',
        'status' => 'Status',
        'opens_at' => 'Öffnet am',
        'closes_at' => 'Schließt am',
        'menu' => 'Menü',
        'menu_key' => 'Schlüssel',
        'menu_name' => 'Bezeichnung',
        'menu_price_cents' => 'Preis (Cent)',
        'menu_add' => 'Option hinzufügen',
    ],

    'admin' => [
        'actions' => [
            'open' => 'Öffnen',
            'opened' => 'Bestellung wurde geöffnet.',
            'close' => 'Schließen',
            'closed' => 'Bestellung wurde geschlossen.',
        ],
        'items_title' => 'Bestellte Positionen',
        'participant' => 'Teilnehmer',
        'option' => 'Option',
        'price' => 'Preis',
        'paid' => 'Bezahlt',
        'toggle_paid' => 'Bezahlt umschalten',
        'totals' => 'Summen',
        'grand_total' => 'Gesamtsumme',
        'close_modal' => 'Schließen',
    ],
];
