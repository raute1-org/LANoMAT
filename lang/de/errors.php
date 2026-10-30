<?php

return [
    'home' => 'Zur Startseite',
    'admin_home' => 'Zum Admin-Bereich',
    'status' => 'Fehler :code',

    '403' => [
        'title' => 'Kein Zugriff',
        'message' => 'Für diese Seite fehlt dir die Berechtigung. Wenn du denkst, dass das ein Irrtum ist, frag das Orga-Team.',
    ],
    '404' => [
        'title' => 'Seite nicht gefunden',
        'message' => 'Diese Adresse gibt es nicht oder sie wurde verschoben. Prüfe den Link oder starte auf der Startseite.',
    ],
    '419' => [
        'title' => 'Sitzung abgelaufen',
        'message' => 'Die Seite war zu lange offen. Lade sie neu und versuche es noch einmal.',
    ],
    '429' => [
        'title' => 'Zu viele Anfragen',
        'message' => 'Es kamen in kurzer Zeit zu viele Anfragen. Warte einen Moment und versuche es dann erneut.',
    ],
    '500' => [
        'title' => 'Etwas ist schiefgelaufen',
        'message' => 'Auf unserer Seite ist ein Fehler aufgetreten. Versuche es gleich noch einmal. Bleibt es dabei, sag dem Orga-Team Bescheid.',
    ],
    '503' => [
        'title' => 'Wartungsarbeiten',
        'message' => 'LANoMAT ist kurz nicht erreichbar. Versuche es in ein paar Minuten noch einmal.',
    ],
];
