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
    ],

    'visibility' => [
        'pending' => 'Wartet auf Freigabe',
        'approved' => 'Freigegeben',
        'rejected' => 'Abgelehnt',
    ],

    'errors' => [
        'quota_exceeded' => 'Dein Upload-Kontingent für dieses Event ist ausgeschöpft.',
        'too_large' => 'Die Datei überschreitet die maximal erlaubte Größe.',
        'invalid_mime' => 'Dieser Dateityp ist nicht erlaubt.',
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
    ],
];
