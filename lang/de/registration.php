<?php

return [
    'status' => [
        'pending' => 'Ausstehend',
        'confirmed' => 'Bestätigt',
        'cancelled' => 'Storniert',
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
];
