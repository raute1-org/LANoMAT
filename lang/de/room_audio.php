<?php

return [
    'nav' => 'Raumsteuerung',
    'title' => 'Raumsteuerung',
    'state' => [
        'unreachable' => 'Receiver nicht erreichbar',
        'unreachable_hint' => 'Die Steuerung ist gesperrt, bis der Receiver wieder antwortet. Netzwerk und Strom am Gerät prüfen.',
        'standby' => 'Receiver ist aus',
        'standby_hint' => 'Zum Steuern den Receiver einschalten.',
        'on' => 'Receiver ist an',
        'loading' => 'Zustand wird aktualisiert',
    ],
    'power_on' => 'Einschalten',
    'power_off' => 'Ausschalten',
    'volume' => 'Lautstärke',
    'volume_up' => 'Lauter',
    'volume_down' => 'Leiser',
    'volume_slider' => 'Lautstärke einstellen',
    'mute' => 'Stummschalten',
    'unmute' => 'Ton einschalten',
    'muted' => 'Stumm',
    'source' => 'Quelle',
    'mode' => 'Klangmodus',
    'ceiling' => 'Lautstärke-Obergrenze',
    'ceiling_hint' => 'Kein Befehl geht über diesen Wert hinaus (0 bis 98).',
    'ceiling_save' => 'Obergrenze speichern',
    'ceiling_saved' => 'Obergrenze gespeichert',
    'no_options' => 'Keine Auswahl gemeldet',
    'errors' => [
        'unavailable' => 'Der Receiver antwortet nicht. Die Änderung wurde nicht übernommen.',
        'invalid' => 'Ungültige Eingabe. Die Änderung wurde nicht übernommen.',
    ],
];
