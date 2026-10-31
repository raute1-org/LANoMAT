<?php

return [
    'resource' => [
        'label' => 'Datei',
        'plural_label' => 'Dateien',
    ],

    'fields' => [
        'event' => 'Event',
        'user' => 'Nutzer',
        'original_name' => 'Dateiname',
        'size_bytes' => 'Größe',
        'mime' => 'Typ',
        'visibility' => 'Status',
        'storage' => 'Speicherort',
    ],

    'storage' => [
        'local' => 'App-Server',
        'filehost' => 'Dateiserver',
    ],

    'visibility' => [
        'pending' => 'Wartet auf Freigabe',
        'approved' => 'Freigegeben',
        'rejected' => 'Abgelehnt',
    ],

    'actions' => [
        'approve' => 'Freigeben',
        'reject' => 'Ablehnen',
    ],

    'errors' => [
        'quota_exceeded' => 'Dein Upload-Kontingent für dieses Event ist ausgeschöpft.',
        'too_large' => 'Die Datei überschreitet die maximal erlaubte Größe.',
        'blocked_type' => 'Dieser Dateityp ist nicht erlaubt: HTML-, SVG- und JavaScript-Dateien können im Browser ausgeführt werden und werden abgelehnt. Alles andere (auch .exe, .msi, .iso, .zip) darfst du teilen, es wird vor der Freigabe durch das Orga-Team geprüft.',
        'store_unavailable' => 'Der Dateiserver ist gerade nicht erreichbar. Bitte versuche es in ein paar Minuten noch einmal.',
        'not_approvable' => 'Eine abgelehnte Datei wurde vom Server entfernt und kann nicht mehr freigegeben werden. Bitte lade sie neu hoch.',
        'unreadable' => 'Die Datei konnte nicht gelesen werden.',
    ],

    'page' => [
        'title' => 'Dateien',
        'empty' => 'Noch keine Dateien für dieses Event — lade die erste hoch.',
        'upload_title' => 'Datei hochladen',
        'upload_label' => 'Datei auswählen',
        'upload_submit' => 'Hochladen',
        'uploading' => 'Wird hochgeladen …',
        'pending_badge' => 'Wartet auf Freigabe',
        'mine_badge' => 'Von dir',
        'uploaded_by' => 'Hochgeladen von',
        'delete' => 'Löschen',
        'download' => 'Herunterladen',
        'load_error' => 'Die Dateiliste konnte nicht geladen werden.',
        'quota_label' => 'Kontingent',
    ],
];
