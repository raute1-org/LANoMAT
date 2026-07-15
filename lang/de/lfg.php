<?php

return [
    'resource' => [
        'label' => 'LFG-Anzeige',
        'plural_label' => 'LFG-Anzeigen',
    ],

    'fields' => [
        'event' => 'Event',
        'user' => 'Nutzer',
        'game' => 'Spiel',
        'title' => 'Titel',
        'body' => 'Beschreibung',
        'slots_needed' => 'Benötigte Plätze',
        'expires_at' => 'Läuft ab am',
    ],

    'errors' => [
        'event_not_visible' => 'LFG-Anzeigen können nur für ein öffentlich sichtbares Event erstellt werden.',
        'invalid_duration' => 'Die Laufzeit muss eine positive Anzahl von Stunden sein.',
        'invalid_title' => 'Der Titel ist ungültig (max. 120 Zeichen).',
    ],

    'page' => [
        'title' => 'Mitspieler finden',
        'empty' => 'Aktuell gibt es keine offenen Anzeigen für dieses Event.',
        'create_title' => 'Neue Anzeige erstellen',
        'game' => 'Spiel',
        'game_placeholder' => 'z. B. Valorant (optional)',
        'title_field' => 'Titel',
        'title_placeholder' => 'Worum geht es?',
        'body' => 'Beschreibung',
        'body_placeholder' => 'Details (optional)',
        'slots_needed' => 'Benötigte Plätze',
        'duration_hours' => 'Läuft ab in (Stunden)',
        'submit' => 'Anzeige veröffentlichen',
        'mine_badge' => 'Meine Anzeige',
        'expires_at' => 'Läuft ab',
        'delete' => 'Löschen',
    ],
];
