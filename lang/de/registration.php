<?php

return [
    'status' => [
        'pending' => 'Ausstehend',
        'confirmed' => 'Bestätigt',
        'cancelled' => 'Storniert',
    ],

    'tickets' => [
        'standard' => 'Standard',
    ],

    'page' => [
        'title' => 'Zum Event anmelden',
        'choose_ticket' => 'Ticket wählen',
        'register' => 'Jetzt anmelden',
        'my_registration' => 'Meine Anmeldung',
        'ticket' => 'Ticket',
        'status' => 'Zahlungsstatus',
        'qr_hint' => 'Zeige diesen Code beim Check-in vor Ort.',
        'paid' => 'Bezahlt',
        'unpaid' => 'Zahlung offen',
        'checked_in' => 'Eingecheckt',
        'cancel' => 'Anmeldung stornieren',
        'cancel_confirm' => 'Anmeldung wirklich stornieren?',
        'closed' => 'Die Anmeldung ist derzeit nicht geöffnet.',
        'full' => 'Ausgebucht — alle Plätze sind vergeben. Bitte wende dich an die Orga.',
        'free_spots' => 'Noch :count Plätze frei.',
    ],

    'errors' => [
        'already_registered' => 'Du bist für dieses Event bereits angemeldet.',
        'full' => 'Das Event hat die maximale Teilnehmerzahl erreicht.',
        'invalid_ticket' => 'Der gewählte Ticket-Typ ist ungültig.',
        'event_not_open' => 'Die Anmeldung für dieses Event ist derzeit nicht geöffnet.',
    ],

    'admin' => [
        'title' => 'Anmeldungen',
        'participant' => 'Teilnehmer',
        'ticket' => 'Ticket',
        'status' => 'Status',
        'paid' => 'Bezahlt',
        'checked_in' => 'Eingecheckt',
        'toggle_paid' => 'Bezahlt umschalten',
        'export' => 'CSV-Export',
    ],

    'notifications' => [
        'checkin_opened' => [
            'title' => 'Check-in geöffnet',
            'body' => 'Der Check-in für :event ist jetzt geöffnet.',
        ],
    ],

    'checkin' => [
        'title' => 'Check-in',
        'scan' => 'QR-Code scannen',
        'manual' => 'Token manuell eingeben',
        'submit' => 'Einchecken',
        'done' => ':name eingecheckt.',
        'errors' => [
            'unknown_token' => 'Kein Ticket mit diesem QR-Code für dieses Event gefunden.',
            'already_checked_in' => 'Dieser Teilnehmer ist bereits eingecheckt.',
            'not_confirmed' => 'Diese Anmeldung ist nicht aktiv.',
        ],
    ],

    'self_checkin' => [
        'title' => 'Einchecken',
        'greeting' => 'Hallo :name',
        'submit' => 'Einchecken',
        'ready' => 'Du bist angemeldet. Tippe auf „Einchecken“, um deine Ankunft zu bestätigen.',
        'done' => 'Du bist eingecheckt.',
        'already' => 'Seit :time Uhr.',
        'next' => 'Weiter',
        'to_seating' => 'Sitzplan',
        'to_tournaments' => 'Turniere',
        'to_event' => 'Zur Event-Seite',
        'not_open_title' => 'Einchecken noch nicht möglich',
        'not_open' => 'Der Check-in öffnet am :time Uhr.',
        'not_open_unknown' => 'Der Check-in öffnet kurz vor Beginn des Events.',
        'over_title' => 'Das Event ist vorbei',
        'over' => 'Einchecken ist nicht mehr möglich.',
        'not_registered_title' => 'Du bist noch nicht angemeldet',
        'not_registered' => 'Melde dich jetzt an, danach kannst du direkt einchecken.',
        'not_registered_spots' => 'Noch :count Plätze frei.',
        'register' => 'Jetzt anmelden',
        'choose_ticket' => 'Ticket wählen',
        'full_title' => 'Ausgebucht',
        'full' => 'Ausgebucht, bitte wende dich an die Orga.',
        'not_registered_closed' => 'Für dieses Event ist keine Anmeldung möglich. Bitte wende dich an die Orga.',
        'error' => 'Das hat nicht geklappt. Versuche es noch einmal oder sprich die Orga an.',
        'retry' => 'Erneut versuchen',
        'print' => [
            'title' => 'Check-in-QR',
            'instruction' => 'QR-Code scannen und einchecken',
            'button' => 'Drucken',
        ],
        'admin' => [
            'show' => 'Check-in-QR',
            'heading' => 'Check-in-QR für :event',
            'close' => 'Schließen',
            'print' => 'Drucken',
            'rotate' => 'Token erneuern',
            'rotate_heading' => 'Check-in-Token erneuern?',
            'rotate_description' => 'Der bisherige QR-Code wird sofort ungültig. Bereits ausgehängte oder gedruckte Codes müssen ersetzt werden.',
            'rotated' => 'Neuer Check-in-QR erzeugt. Alte Codes funktionieren nicht mehr.',
        ],
    ],
];
