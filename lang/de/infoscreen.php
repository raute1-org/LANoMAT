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
        'servers' => 'Spielserver',
        'presence' => 'Präsenz',
        'now_playing' => 'Läuft gerade',
        'gallery' => 'Fotogalerie',
        'winner' => 'Sieger-Einblendung',
        'gong' => 'Go-Gong',
        'scoreboard' => 'Live-Scoreboard',
    ],

    'status_level' => [
        'ok' => 'OK',
        'degraded' => 'Eingeschränkt',
        'down' => 'Ausgefallen',
    ],

    'status_component' => [
        'internet' => 'Internet',
        'servers' => 'Server',
        'voice' => 'Voice',
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
        'prize_title' => 'Preis',
    ],

    'tombola_resource' => [
        'label' => 'Tombola-Preis',
        'plural_label' => 'Tombola-Preise',
    ],

    'errors' => [
        'no_eligible_entrants' => 'Es gibt aktuell keine ziehbaren Teilnehmenden mehr (niemand eingecheckt oder bereits alle gezogen).',
        'orga_ping_too_many_words' => 'Bitte maximal drei Wörter angeben.',
        'orga_ping_words_too_long' => 'Bitte maximal 40 Zeichen angeben.',
    ],

    'orga_ping' => [
        'title' => 'Orga wird gerufen',
        'body' => ':name (Platz: :seat) ruft die Orga: :words',
        'no_seat' => 'kein Platz',
        'no_words' => '–',
        'button' => 'Orga rufen',
        'words_label' => 'Kurze Nachricht (optional, max. 3 Wörter)',
        'words_placeholder' => 'z. B. „Netzwerk kaputt“',
        'send' => 'Orga rufen',
        'sent' => 'Die Orga wurde benachrichtigt.',
    ],

    'control' => [
        'title' => 'Infoscreen-Fernbedienung',
        'show_now' => 'Sofort einblenden',
        'shown' => 'Szene wird jetzt eingeblendet.',
        'empty' => 'Es sind noch keine Szenen angelegt.',
        'enabled' => 'Aktiv',
        'disabled' => 'Inaktiv',
    ],

    'status' => [
        'saved' => 'Status wurde gespeichert.',
        'title' => 'Betriebsstatus',
        'component_label' => 'Komponente',
        'level_label' => 'Status',
        'message_label' => 'Hinweis (optional)',
        'message_placeholder' => 'z. B. „Kein Uplink, Techniker ist dran.“',
        'save_button' => 'Status setzen',
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
        'tombola_draw_title' => 'Tombola ziehen',
        'tombola_draw_button' => 'Nächsten Preis ziehen',
        'tombola_draw_sent' => 'Der Gewinner wird jetzt auf dem Beamer gezogen.',
        'tombola_draw_empty' => 'Es sind aktuell keine Preise mehr zu ziehen.',
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
        'sponsors_logo_alt' => 'Sponsor-Logo :index',

        // SceneTombola (Task 11) — the tombola prize board and reveal.
        'tombola_title' => 'Tombola',
        'tombola_empty' => 'Es sind noch keine Preise angelegt.',
        'tombola_drawn_label' => 'Gewonnen',
        'tombola_winner_title' => 'Gewinner!',
        'tombola_winner_prize' => 'Preis: :prize',

        // BracketView's own labels (shared with the tournament show page's
        // "labels"/"matchStatusLabels"/"reportLabels" — mirrored here flat
        // since the screen only has one "labels" bag).
        'round' => 'Runde :number',
        'winners_bracket' => 'Gewinner-Bracket',
        'losers_bracket' => 'Verlierer-Bracket',
        'finals' => 'Finale',
        'match_status_pending' => 'Ausstehend',
        'match_status_ready' => 'Bereit',
        'match_status_warmup' => 'Aufwärmen',
        'match_status_reported' => 'Gemeldet',
        'match_status_disputed' => 'Strittig',
        'match_status_completed' => 'Abgeschlossen',
        'report_action' => 'Melden',
        'confirm_action' => 'Bestätigen',
        'dispute_action' => 'Anfechten',

        // SceneWinner (Task 7) — the finals winner-moment overlay.
        'winner_title' => 'Sieger!',
        'winner_subtitle' => 'Gewinner von :tournament',

        // SceneGong (Task 11) — the warmup->live "Go" gong moment.
        'gong_title' => 'GO!',
        'gong_subtitle' => ':slot1 vs. :slot2',
        'gong_tournament' => ':tournament',

        // SceneStatus (Task 12) — the operations status tile and its
        // auto-override reassurance banner.
        'status_title' => 'Betriebsstatus',
        'status_reassurance_title' => 'Orga weiß Bescheid',
        'status_reassurance_body' => 'Es gibt gerade eine Störung — die Orga kümmert sich bereits.',
        'status_component_internet' => 'Internet',
        'status_component_servers' => 'Server',
        'status_component_voice' => 'Voice',
        'status_level_ok' => 'OK',
        'status_level_degraded' => 'Eingeschränkt',
        'status_level_down' => 'Ausgefallen',

        // SceneServers (Task 7) — the joinable game server board.
        'servers_title' => 'Spielserver',
        'servers_empty' => 'Aktuell sind keine Spielserver bereit.',
        'servers_address_label' => 'Adresse',
        'servers_port_label' => 'Port',

        // SceneScoreboard (Task 12) — the CS2 live-stats scoreboard moment.
        'scoreboard_title' => 'Live-Scoreboard',
        'scoreboard_round' => 'Runde :number',
        'scoreboard_tournament' => ':tournament',

        // ScenePresence (M10 Task 10.4) — the beamer "wer ist da / spielt
        // gerade / freie Slots" board, reusing the M10 PresenceProjection.
        'presence_title' => 'Präsenz',
        'presence_checked_in_label' => 'Eingecheckt',
        'presence_live_matches_heading' => 'Läuft gerade',
        'presence_live_matches_empty' => 'Gerade läuft kein Match.',
        'presence_live_label' => 'LIVE',
        'presence_free_slots_heading' => 'Freie Slots',
        'presence_free_slots_empty' => 'Aktuell keine offenen Anmeldungen.',
        'presence_open_unlimited' => 'offen',
        'presence_open_spots' => ':count frei',

        // SceneNowPlaying (M11 Jukebox) — the beamer "läuft gerade" now-playing
        // board, reusing JukeboxQueue's current()/upcoming() read-model.
        'now_playing_title' => 'Läuft gerade',
        'now_playing_empty' => 'Gerade läuft nichts. Fügt Musik in der Jukebox hinzu!',
        'now_playing_live_label' => 'LIVE',
        'now_playing_artist_unknown' => 'Unbekannt',
        'now_playing_up_next_heading' => 'Als Nächstes',
        'now_playing_up_next_empty' => 'Die Warteschlange ist leer.',

        // SceneGallery (Task 7) — the beamer photo slideshow, reusing
        // GalleryQuery's approvedFor() read-model.
        'gallery_empty' => 'Noch keine Fotos freigegeben.',
    ],
];
