<?php

return [
    'resource' => [
        'label' => 'Turnier',
        'plural_label' => 'Turniere',
    ],

    'fields' => [
        'event' => 'Event',
        'name' => 'Name',
        'format' => 'Format',
        'status' => 'Status',
        'team_size' => 'Teamgröße',
        'max_entries' => 'Max. Anmeldungen',
        'starts_at' => 'Beginn',
        'checkin_opens_at' => 'Check-in öffnet',
        'checkin_closes_at' => 'Check-in schließt',
        'settings' => 'Einstellungen',
    ],

    'admin' => [
        'entries' => [
            'title' => 'Anmeldungen',
            'display_name' => 'Anzeigename',
            'team' => 'Team',
            'user' => 'Person',
            'status' => 'Status',
            'checked_in' => 'Eingecheckt',
            'check_in' => 'Einchecken',
            'withdraw' => 'Zurückziehen',
        ],
        'actions' => [
            'start' => 'Turnier starten',
            'started' => 'Turnier gestartet.',
        ],
        'disputes' => [
            'title' => 'Strittige Matches',
            'tournament' => 'Turnier',
            'round' => 'Runde',
            'entry1' => 'Anmeldung 1',
            'entry2' => 'Anmeldung 2',
            'score1' => 'Ergebnis 1',
            'score2' => 'Ergebnis 2',
            'override' => 'Ergebnis überschreiben',
        ],
    ],

    'page' => [
        'title' => 'Turniere',
        'index_title' => 'Turniere',
        'show_title' => 'Turnier',
        'no_tournaments' => 'Für dieses Event sind noch keine Turniere geplant.',
        'back_to_index' => 'Zurück zu allen Turnieren',
        'round' => 'Runde :number',
        'winners_bracket' => 'Gewinner-Bracket',
        'losers_bracket' => 'Verlierer-Bracket',
        'finals' => 'Finale',
        'enroll' => 'Anmelden',
        'check_in' => 'Einchecken',
    ],

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
        'unsupported_double_elimination_size' => 'Doppel-K.-o.-System wird nur für 2, 4, 6, 8 oder 16 teilnehmende Anmeldungen unterstützt.',
        'match_not_ready' => 'Dieses Match ist noch nicht bereit, um gemeldet zu werden.',
        'stale_match' => 'Dieses Match wurde inzwischen von jemand anderem aktualisiert. Bitte lade die Seite neu und versuche es erneut.',
        'not_a_participant' => 'Diese Anmeldung ist keine Teilnehmerin dieses Matches.',
        'cannot_confirm_own_report' => 'Die meldende Anmeldung kann die eigene Meldung nicht bestätigen oder anfechten.',
        'reporter_has_no_owner' => 'Die meldende Anmeldung hat weder eine Person noch einen Team-Owner.',
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

    'auto_team' => [
        'name' => 'Team :number',
    ],

    'report' => [
        'submitted' => 'Ergebnis gemeldet. Warte auf Bestätigung durch den Gegner.',
        'confirmed' => 'Ergebnis bestätigt.',
        'disputed' => 'Ergebnis angefochten. Die Orga wird sich darum kümmern.',
        'overridden' => 'Ergebnis von der Orga überschrieben.',
        'report_action' => 'Melden',
        'confirm_action' => 'Bestätigen',
        'dispute_action' => 'Anfechten',
        'score1' => 'Ergebnis (du)',
        'score2' => 'Ergebnis (Gegner)',
    ],
];
