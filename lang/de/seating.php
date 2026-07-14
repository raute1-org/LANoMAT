<?php

return [
    'seat' => [
        'label' => 'Platz',
    ],
    'resource' => [
        'label' => 'Sitzplatz',
        'plural_label' => 'Sitzplätze',
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
    ],
    'page' => [
        'title' => 'Sitzplan',
        'free' => 'Frei',
        'my_seat' => 'Mein Platz',
        'occupied_by' => 'Belegt von :name',
        'claim' => 'Platz wählen',
        'release' => 'Platz freigeben',
        'need_registration' => 'Melde dich zuerst zum Event an, um einen Platz zu wählen.',
    ],
    'errors' => [
        'wrong_event' => 'Dieser Platz gehört nicht zu diesem Event.',
        'taken' => 'Dieser Platz ist bereits vergeben.',
    ],
    'delete' => [
        'occupied_warning' => 'Achtung: Dieser Platz ist belegt von :name. Beim Löschen wird die Sitzplatzzuweisung entfernt.',
        'occupied_warning_bulk' => 'Achtung: Mindestens ein ausgewählter Platz ist belegt. Beim Löschen werden betroffene Sitzplatzzuweisungen entfernt.',
    ],
];
