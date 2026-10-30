<?php

return [
    'title' => 'Dashboard',
    'description' => 'Dein Überblick: nächste LAN, Ticket, Platz und Teams.',
    'load_error' => 'Das Dashboard konnte nicht aktualisiert werden. Lade die Seite neu und versuche es noch einmal.',

    'event' => [
        'heading' => 'Aktuelle LAN',
        'empty' => 'Aktuell ist keine LAN angekündigt. Sobald Orga ein Event veröffentlicht, erscheint es hier.',
        'when' => 'Wann',
        'where' => 'Wo',
        'open' => 'Zur Event-Seite',
        'countdown' => 'Noch :days Tage, :hours Std',
        'countdown_soon' => 'Es geht gleich los',
    ],

    'ticket' => [
        'heading' => 'Dein Ticket',
        'type' => 'Ticket-Art',
        'none_open' => 'Du bist noch nicht angemeldet.',
        'none_full' => 'Ausgebucht',
        'none_upcoming' => 'Die Anmeldung öffnet bald.',
        'none_closed' => 'Die Anmeldung ist beendet.',
        'register' => 'Jetzt anmelden',
        'show' => 'Ticket anzeigen',
        'confirmed' => 'Angemeldet',
        'pending' => 'Angemeldet, Bestätigung offen',
        'paid' => 'Bezahlt',
        'unpaid' => 'Noch nicht bezahlt',
        'checked_in' => 'Eingecheckt',
        'not_checked_in' => 'Noch nicht eingecheckt',
    ],

    'seat' => [
        'heading' => 'Dein Platz',
        'none' => 'Du hast noch keinen Platz gewählt.',
        'none_no_registration' => 'Plätze kannst du nach der Anmeldung wählen.',
        'choose' => 'Platz wählen',
        'show' => 'Sitzplan öffnen',
    ],

    'teams' => [
        'heading' => 'Deine Teams',
        'empty' => 'Du bist noch in keinem Team. Gründe eins oder tritt einem bei.',
        'all' => 'Alle Teams',
        'role' => ['owner' => 'Kapitän', 'member' => 'Mitglied'],
    ],

    'links' => [
        'heading' => 'Schnellzugriff',
        'events' => 'Events',
        'teams' => 'Teams',
        'connections' => 'Verknüpfungen',
        'steam_nudge' => 'Verknüpfe Steam, damit Freunde und Spiele besser zu dir passen.',
        'steam_link' => 'Steam verknüpfen',
    ],
];
