<?php

return [
    'type' => [
        'bracket' => 'Turnierbaum',
        'upcoming_matches' => 'Nächste Matches',
        'schedule' => 'Programm',
        'announcement' => 'Ansage',
        'seatmap' => 'Sitzplan',
        'payment_qr' => 'Bezahl-QR',
        'sponsors' => 'Sponsoren',
        'tombola' => 'Tombola',
        'status' => 'Statusanzeige',
        'winner' => 'Sieger-Einblendung',
    ],

    'resource' => [
        'label' => 'Infoscreen-Szene',
        'plural_label' => 'Infoscreen-Szenen',
    ],

    'fields' => [
        'event' => 'Event',
        'type' => 'Typ',
        'duration_sec' => 'Dauer (Sek.)',
        'enabled' => 'Aktiv',
        'sort' => 'Reihenfolge',
        'headline' => 'Überschrift',
        'body' => 'Text',
        'tournament' => 'Turnier',
        'qr_payload' => 'QR-Inhalt',
        'qr_caption' => 'QR-Beschriftung',
        'sponsor_logos' => 'Sponsoren-Logos',
        'sponsor_logo' => 'Logo',
        'sponsor_logo_add' => 'Logo hinzufügen',
    ],

    'admin' => [
        'nav_group' => 'Event-Planung',
    ],

    'control' => [
        'title' => 'Infoscreen-Fernbedienung',
        'show_now' => 'Sofort einblenden',
        'shown' => 'Szene wird jetzt eingeblendet.',
        'empty' => 'Es sind noch keine Szenen angelegt.',
        'enabled' => 'Aktiv',
        'disabled' => 'Inaktiv',
    ],

    'triggers' => [
        'title' => 'Trigger',
        'food_ready_title' => 'Essen ist da',
        'food_ready_button' => 'Essen ist da',
        'food_ready_sent' => 'Alle Bestellenden wurden benachrichtigt.',
        'food_ready_empty' => 'Es gibt aktuell keine offene Essensbestellung.',
        'checkin_open_title' => 'Check-in öffnet',
        'checkin_open_button' => 'Check-in öffnet',
        'checkin_open_sent' => 'Alle bestätigten Teilnehmenden wurden benachrichtigt.',
    ],

    'screen' => [
        'title' => 'Infoscreen',
        'idle' => 'Bereit',
        'idle_body' => 'Es sind noch keine Szenen aktiv.',

        'bracket_title' => 'Turnierbaum',
        'upcoming_matches_title' => 'Nächste Matches',
        'upcoming_matches_empty' => 'Aktuell sind keine Matches bereit.',
        'slot_tbd' => 'TBD',
        'versus' => 'vs.',
        'schedule_title' => 'Programm',
        'schedule_now' => 'Jetzt',
        'schedule_next' => 'Gleich',
        'schedule_empty' => 'Noch kein Programm.',
        'seatmap_title' => 'Sitzplan',
        'seatmap_empty' => 'Es sind noch keine Plätze angelegt.',
        'payment_qr_title' => 'Kostenbeitrag',
        'payment_qr_empty' => 'Es ist noch kein QR-Code hinterlegt.',
        'sponsors_title' => 'Sponsoren',
        'sponsors_empty' => 'Es sind noch keine Sponsoren-Logos hinterlegt.',
        'sponsors_logo_alt' => 'Sponsor-Logo',

        // BracketView's own labels (shared with the tournament show page's
        // "labels"/"matchStatusLabels"/"reportLabels" — mirrored here flat
        // since the screen only has one "labels" bag).
        'round' => 'Runde :number',
        'winners_bracket' => 'Gewinner-Bracket',
        'losers_bracket' => 'Verlierer-Bracket',
        'finals' => 'Finale',
        'match_status_pending' => 'Ausstehend',
        'match_status_ready' => 'Bereit',
        'match_status_reported' => 'Gemeldet',
        'match_status_disputed' => 'Strittig',
        'match_status_completed' => 'Abgeschlossen',
        'report_action' => 'Melden',
        'confirm_action' => 'Bestätigen',
        'dispute_action' => 'Anfechten',

        // SceneWinner (Task 7) — the finals winner-moment overlay.
        'winner_title' => 'Sieger!',
        'winner_subtitle' => 'Gewinner von :tournament',
    ],
];
