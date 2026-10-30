<?php

return [
    'resource' => [
        'label' => 'Turnier',
        'plural_label' => 'Turniere',
    ],

    'fields' => [
        'event' => 'Event',
        'game' => 'Spiel',
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
            'open_enrollment' => 'Anmeldung öffnen',
            'open_enrollment_description' => 'Das Turnier wird für Teilnehmende sichtbar anmeldbar. Vorher werden Spiel, Teilnehmerlimit und Check-in-Fenster geprüft.',
            'enrollment_opened' => 'Anmeldung geöffnet.',
            'close_enrollment' => 'Anmeldung schließen',
            'close_enrollment_description' => 'Das Turnier geht zurück in den Entwurf. Das ist nur möglich, solange es keine Anmeldungen gibt.',
            'enrollment_closed' => 'Anmeldung geschlossen, das Turnier ist wieder ein Entwurf.',
            'open_checkin' => 'Zur Check-in-Phase',
            'open_checkin_description' => 'Beendet die Anmeldephase und öffnet den Check-in für die Angemeldeten.',
            'checkin_opened' => 'Check-in-Phase gestartet.',
            'next_steps' => 'Nächste Schritte',
        ],
        'disputes' => [
            'title' => 'Strittige Matches',
            'tournament' => 'Turnier',
            'round' => 'Runde',
            'entry1' => 'Anmeldung 1',
            'entry2' => 'Anmeldung 2',
            'score1' => 'Ergebnis 1',
            'score2' => 'Ergebnis 2',
            'reported_score' => 'Gemeldet (Anm. 1 : Anm. 2)',
            'reported_by' => 'Gemeldet von',
            'reported_at' => 'Gemeldet am',
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
        'entries_players' => 'Anmeldungen',
        'entries_teams' => 'Teams',
        'full' => 'ausgebucht',
        'check_in' => 'Einchecken',
        'join_voice' => 'Voice beitreten',
        'not_started' => 'Das Turnier hat noch nicht begonnen.',
        'finished' => 'Turnier beendet',
        'champion' => 'Sieger',
        'connection_lost' => 'Verbindung unterbrochen, aktualisiere nach dem Wiederverbinden',
        'withdraw' => 'Abmelden',
        'withdraw_confirm' => 'Wirklich vom Turnier abmelden? Dein Platz wird freigegeben.',
        'entered' => 'Angemeldet',
        'checked_in' => 'Eingecheckt',
        'registration_required' => 'Melde dich zuerst zum Event an, um dich für Turniere anzumelden.',
        'register_now' => 'Zur Event-Anmeldung',
        'team_pick_label' => 'Team wählen',
        'team_pick_placeholder' => 'Team auswählen',
        'team_pick_incomplete' => ':count von :size Mitgliedern',
        'team_no_team' => 'Für dieses Turnier brauchst du ein eigenes Team mit :size Mitgliedern.',
        'team_create' => 'Team erstellen',
        'team_none_ready' => 'Keines deiner Teams hat genau :size Mitglieder.',
        'enroll_team' => 'Mit Team anmelden',
        'registration_not_open' => 'Die Event-Anmeldung ist derzeit nicht geöffnet.',
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
        'warmup' => 'Aufwärmen',
        'reported' => 'Gemeldet',
        'disputed' => 'Strittig',
        'completed' => 'Abgeschlossen',
    ],

    // BracketMatchCard's calm-register placeholders for never-played
    // matches (bracket padding at odd field sizes): `bye` for a match won
    // unopposed (exactly one slot null), `not_held` for a losers-bracket
    // phantom (both winners-bracket feeders were byes, so both slots are
    // null and nobody ever advances from this match).
    'bracket' => [
        'bye' => 'Freilos',
        'not_held' => 'Entfällt',
    ],

    'report_status' => [
        'pending' => 'Ausstehend',
        'confirmed' => 'Bestätigt',
        'disputed' => 'Strittig',
    ],

    'errors' => [
        'checkin_window_order' => 'Check-in schließt (muss nach Check-in öffnet liegen)',
        'team_required' => 'Wähle ein Team, mit dem du dich anmelden möchtest.',
        'not_draft' => 'Nur ein Entwurf kann für Anmeldungen geöffnet werden.',
        'incomplete_setup' => 'Vor dem Öffnen der Anmeldung fehlen noch: :fields.',
        'cannot_return_to_draft' => 'Nur eine Anmelde- oder Check-in-Phase kann zum Entwurf zurückgesetzt werden.',
        'has_entries' => 'Es gibt bereits Anmeldungen. Zurück zum Entwurf ist nur ohne Anmeldungen möglich.',
        'withdrawal_closed' => 'Zurückziehen ist nur während der Anmeldung oder des Check-ins möglich.',
        'registration_required' => 'Melde dich zuerst zum Event an.',
        'not_in_enrollment' => 'Das Turnier ist nicht für Anmeldungen geöffnet.',
        'full' => 'Das Turnier ist ausgebucht.',
        'already_enrolled' => 'Diese Person oder dieses Team ist bereits angemeldet.',
        'checkin_closed' => 'Der Check-in ist derzeit nicht geöffnet.',
        'roster_size_mismatch' => 'Die Teamgröße stimmt nicht mit der geforderten Teamgröße überein.',
        'already_started' => 'Das Turnier hat bereits begonnen.',
        'unsupported_double_elimination_size' => 'Doppel-K.-o.-System wird nur für 2 bis 64 teilnehmende Anmeldungen unterstützt.',
        'not_enough_entries' => 'Zum Starten werden mindestens 2 teilnehmende Anmeldungen benötigt (aktuell :count). Bei aktivem Check-in zählen nur eingecheckte Anmeldungen.',
        'unsupported_single_elimination_size' => 'Einfach-K.-o.-System wird nur für 2 bis 64 teilnehmende Anmeldungen unterstützt.',
        'checkin_window_closed_early' => 'Das Check-in-Fenster ist bereits abgelaufen. Passe zuerst „Check-in schließt“ im Turnier an.',
        'checkin_closes_after_opens' => 'Check-in schließt muss nach Check-in öffnet liegen.',
        'match_not_ready' => 'Dieses Match ist noch nicht bereit, um gemeldet zu werden.',
        'match_not_in_warmup' => 'Dieses Match ist nicht in der Aufwärmphase.',
        'stale_match' => 'Das Ergebnis wurde inzwischen geändert. Seite neu geladen.',
        'not_a_participant' => 'Diese Anmeldung ist keine Teilnehmerin dieses Matches.',
        'cannot_confirm_own_report' => 'Die meldende Anmeldung kann die eigene Meldung nicht bestätigen oder anfechten.',
        'reporter_has_no_owner' => 'Die meldende Anmeldung hat weder eine Person noch einen Team-Owner.',
        'tied_score' => 'Ein Match kann nicht unentschieden enden.',
        'report_not_pending' => 'Diese Meldung wurde bereits bearbeitet.',
        'match_not_disputable' => 'Dieses Match kann nicht mehr angefochten werden.',
        'entry_withdrawn' => 'Diese Anmeldung wurde zurückgezogen und kann nicht mehr eingecheckt werden.',
    ],

    'enrollment' => [
        'enroll' => 'Anmelden',
        'withdraw' => 'Anmeldung zurückziehen',
        'enrolled' => 'Erfolgreich angemeldet.',
        'withdrawn' => 'Anmeldung zurückgezogen.',
    ],

    // Advisory-only game-ownership hint (M9 task 9.7): NEVER blocks
    // enrollment, only shown as a calm warning. The Unknown case (no
    // provider mapping, no linked account, private profile, ...) is
    // intentionally not shown at all — see Tournaments/Index.vue.
    'ownership_hint' => [
        'not_owned_warning' => 'Wir konnten deinen Besitz von :game nicht bestätigen — anmelden kannst du dich trotzdem.',
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
        'pending_title' => 'Gemeldetes Ergebnis',
        'own_label' => 'du',
        'opponent_label' => 'Gegner',
        'reported_by' => 'Gemeldet von :name',
        'awaiting' => 'Warte auf Bestätigung durch :name',
    ],

    'standings' => [
        'title' => 'Tabelle',
        'empty' => 'Noch keine Ergebnisse.',
        'rank' => 'Platz',
        'name' => 'Teilnehmer',
        'played' => 'Sp.',
        'wins' => 'S',
        'losses' => 'N',
        'points' => 'Punkte',
        'difference' => 'Diff.',
        'tiebreak' => 'Bei Gleichstand zählen Siege, dann Punktdifferenz, dann der direkte Vergleich und die erzielten Punkte.',
    ],

    'warmup' => [
        'go_action' => 'Go!',
        'waiting' => 'Aufwärmen läuft …',
        'went_live' => 'Das Match ist jetzt live.',
    ],

    'notifications' => [
        'match_ready' => [
            'title' => 'Match bereit',
            'body' => ':entry1 vs. :entry2 – euer Match kann jetzt gespielt werden: :url',
        ],
    ],
];
