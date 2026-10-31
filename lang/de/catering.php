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
        'registration_required' => 'Bestätige zuerst deine Anmeldung',
        'item_limit' => 'Du hast das Limit von :limit Essens-Positionen erreicht.',
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
        'menu_price' => 'Preis (EUR)',
        'menu_add' => 'Option hinzufügen',
    ],

    'page' => [
        'title' => 'Essensbestellung',
        'empty' => 'Für dieses Event gibt es aktuell keine Essensbestellung.',
        'window_open' => 'Bestellung geöffnet',
        'window_closed' => 'Bestellung geschlossen',
        'opens_at' => 'Öffnet am',
        'closes_at' => 'Schließt am',
        'menu' => 'Menü',
        'order' => 'Bestellen',
        'note_placeholder' => 'Anmerkung (optional)',
        'my_order' => 'Meine Bestellung',
        'my_order_empty' => 'Du hast noch nichts bestellt.',
        'cancel' => 'Stornieren',
        'my_total' => 'Meine Summe',
        'registration_required' => 'Bestätige zuerst deine Anmeldung zum Event, dann kannst du Essen bestellen.',
        'register_link' => 'Zur Anmeldung',
    ],

    'notifications' => [
        'food_ready' => [
            'title' => 'Essen ist da',
            'body' => 'Das Essen für „:order“ ist da – komm vorbei!',
        ],
    ],

    'validation' => [
        'price_format' => 'Bitte einen Preis in Euro eingeben, z. B. 8,50.',
        'price_max' => 'Der Preis darf höchstens :max € betragen.',
        'menu_key_duplicate' => 'Zwei Menü-Positionen haben denselben Schlüssel.',
        'no_nul_bytes' => 'Ungültige Zeichen im Text.',
    ],

    'admin' => [
        'item_model_label' => 'Position',
        'item_plural_model_label' => 'Positionen',
        'items_empty' => 'Noch keine Positionen',
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
