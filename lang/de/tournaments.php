<?php

return [
    'format' => [
        'single_elimination' => 'Einfach-K.-o.-System',
        'double_elimination' => 'Doppel-K.-o.-System',
        'round_robin' => 'Jeder gegen jeden',
    ],

    'status' => [
        'draft' => 'Entwurf',
        'enrollment' => 'Anmeldung offen',
        'check_in' => 'Check-in',
        'live' => 'Live',
        'finished' => 'Beendet',
    ],

    'entry_status' => [
        'registered' => 'Angemeldet',
        'checked_in' => 'Eingecheckt',
        'withdrawn' => 'Zurückgezogen',
    ],

    'match_status' => [
        'pending' => 'Ausstehend',
        'ready' => 'Bereit',
        'reported' => 'Gemeldet',
        'disputed' => 'Strittig',
        'completed' => 'Abgeschlossen',
    ],

    'report_status' => [
        'pending' => 'Ausstehend',
        'confirmed' => 'Bestätigt',
        'disputed' => 'Strittig',
    ],

    'errors' => [
        'not_in_enrollment' => 'Das Turnier ist nicht für Anmeldungen geöffnet.',
        'full' => 'Das Turnier ist ausgebucht.',
        'already_enrolled' => 'Diese Person oder dieses Team ist bereits angemeldet.',
        'checkin_closed' => 'Der Check-in ist derzeit nicht geöffnet.',
        'roster_size_mismatch' => 'Die Teamgröße stimmt nicht mit der geforderten Teamgröße überein.',
        'already_started' => 'Das Turnier hat bereits begonnen.',
    ],

    'enrollment' => [
        'enroll' => 'Anmelden',
        'withdraw' => 'Anmeldung zurückziehen',
        'enrolled' => 'Erfolgreich angemeldet.',
        'withdrawn' => 'Anmeldung zurückgezogen.',
    ],

    'checkin' => [
        'check_in' => 'Einchecken',
        'checked_in' => 'Erfolgreich eingecheckt.',
        'window_closed' => 'Das Check-in-Fenster ist geschlossen.',
    ],
];
