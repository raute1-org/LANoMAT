<?php

return [
    'seat' => [
        'label' => 'Platz',
    ],
    'resource' => [
        'label' => 'Sitzplatz',
        'plural_label' => 'Sitzplätze',
    ],
    'validation' => [
        'label_taken' => 'In diesem Event gibt es bereits einen Platz mit dieser Bezeichnung.',
    ],

    'fields' => [
        'event' => 'Event',
        'label' => 'Platz',
        'pos_x' => 'Spalte',
        'pos_y' => 'Reihe',
        'switch_port' => 'Switch-Port',
        'ip' => 'IP-Adresse',
        'occupied_by' => 'Belegt durch',
    ],
    'grid' => [
        'action' => 'Raster anlegen',
        'event' => 'Event',
        'rows' => 'Reihen',
        'cols' => 'Spalten',
        'prefix' => 'Label-Präfix',
        'done' => ':count Plätze angelegt.',
        'too_many' => 'Ein Raster darf höchstens :max Plätze haben (Reihen mal Spalten).',
    ],
    'page' => [
        'title' => 'Sitzplan',
        'free' => 'Frei',
        'occupied' => 'Belegt',
        'my_seat' => 'Mein Platz',
        'occupied_by' => 'Belegt von :name',
        'claim' => 'Platz wählen',
        'release' => 'Platz freigeben',
        'need_registration' => 'Melde dich zuerst zum Event an, um einen Platz zu wählen.',
        'opens_with_registration' => 'Der Sitzplan öffnet mit der Anmeldung.',
        'registration_closed' => 'Die Anmeldung ist beendet — Plätze können nicht mehr gewählt werden.',
        'registration_full' => 'Ausgebucht — alle Plätze sind vergeben. Bitte wende dich an die Orga.',
        'confirm_registration_first' => 'Bestätige zuerst deine Anmeldung.',
        'event_over' => 'Das Event ist beendet — der Sitzplan ist nur noch zur Ansicht.',
        'claimed' => 'Platz :seat gewählt.',
        'released' => 'Platz freigegeben.',
        'close' => 'Schließen',
        'zoom' => 'Zoom',
        'zoom_in' => 'Sitzplan vergrößern',
        'zoom_out' => 'Sitzplan verkleinern',
        'scroll_hint' => 'Zum Verschieben wischen, einen Platz antippen für Details.',
        'select_hint' => 'Einen Platz antippen für Details.',
        'to_registration' => 'Zur Anmeldung',
        'empty' => 'Der Sitzplan für dieses Event ist noch nicht angelegt — schau später nochmal vorbei.',
    ],
    'errors' => [
        'wrong_event' => 'Dieser Platz gehört nicht zu diesem Event.',
        'taken' => 'Dieser Platz ist bereits vergeben.',
        'registration_not_confirmed' => 'Bestätige zuerst deine Anmeldung, dann kannst du einen Platz wählen.',
        'event_over' => 'Das Event ist beendet — Plätze können nicht mehr gewählt oder geändert werden.',
    ],
    'delete' => [
        'occupied_warning' => 'Achtung: Dieser Platz ist belegt von :name. Beim Löschen wird die Sitzplatzzuweisung entfernt.',
        'occupied_warning_bulk' => 'Achtung: Mindestens ein ausgewählter Platz ist belegt. Beim Löschen werden betroffene Sitzplatzzuweisungen entfernt.',
    ],
];
