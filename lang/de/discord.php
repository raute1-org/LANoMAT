<?php

return [
    'registration_open' => 'Die Anmeldung für :event ist jetzt geöffnet!',
    'reminder' => 'Erinnerung: :event beginnt in etwa :hours Stunden.',
    'unknown_command' => 'Unbekannter Befehl.',
    'not_linked' => 'Dein Discord-Account ist mit keinem LANoMAT-Account verknüpft.',

    'commands' => [
        'tournament' => [
            'description' => 'Turnier-Befehle',
            'list' => [
                'description' => 'Turniere des aktuellen Events anzeigen',
                'none' => 'Für das aktuelle Event sind noch keine Turniere geplant.',
                'no_current_event' => 'Es gibt gerade kein aktives Event.',
            ],
            'info' => [
                'description' => 'Details zu einem Turnier anzeigen',
                'id_option' => 'Turnier-ID',
                'not_found' => 'Turnier nicht gefunden.',
                'summary' => ":name\nFormat: :format\nStatus: :status\nStart: :starts_at",
            ],
            'checkin' => [
                'description' => 'Für ein Turnier einchecken',
                'id_option' => 'Turnier-ID',
                'no_entry' => 'Du hast für dieses Turnier keine Anmeldung.',
                'success' => 'Check-in erfolgreich für :name.',
            ],
            'bracket' => [
                'description' => 'Link zur Bracket-Seite eines Turniers',
                'id_option' => 'Turnier-ID',
                'not_found' => 'Turnier nicht gefunden.',
                'link' => 'Bracket für :name: :url',
            ],
        ],
        'help' => [
            'description' => 'Zeigt die verfügbaren Befehle',
        ],
        'schedule' => [
            'description' => 'Nächste Programmpunkte des aktuellen Events anzeigen',
            'no_current_event' => 'Es gibt gerade kein aktives Event.',
            'none' => 'Für das aktuelle Event stehen keine weiteren Programmpunkte an.',
            'heading' => 'Nächste Programmpunkte:',
            'item' => '- :title (:starts_at)',
        ],
    ],

    'help' => [
        'text' => "Verfügbare Befehle:\n/tournament list – Turniere des aktuellen Events anzeigen\n/tournament info <id> – Details zu einem Turnier anzeigen\n/tournament checkin <id> – Für ein Turnier einchecken\n/tournament bracket <id> – Link zur Bracket-Seite anzeigen\n/schedule – Nächste Programmpunkte des aktuellen Events anzeigen\n/help – Diese Hilfe anzeigen",
    ],

    'match_channel' => [
        'unknown_opponent' => 'Unbekannt',
        'welcome_title' => ':entry1 vs. :entry2',
        'welcome_description' => "Willkommen in eurem Match-Channel!\n:entry1 vs. :entry2\nDetails zum Match: :url",
        'result_announcement' => 'Ergebnis: :entry1 vs. :entry2 – Sieger: :winner',
        'voice_links_heading' => 'Voice-Channel beitreten:',
    ],
];
