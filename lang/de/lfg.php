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
    ],
];
