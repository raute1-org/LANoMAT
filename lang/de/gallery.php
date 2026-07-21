<?php

return [
    'resource' => [
        'label' => 'Foto',
        'plural_label' => 'Fotos',
    ],

    'fields' => [
        'thumbnail' => 'Vorschau',
        'event' => 'Event',
        'user' => 'Nutzer',
        'caption' => 'Bildunterschrift',
        'visibility' => 'Status',
        'is_highlight' => 'Highlight',
    ],

    'visibility' => [
        'pending' => 'Wartet auf Freigabe',
        'approved' => 'Freigegeben',
        'rejected' => 'Abgelehnt',
    ],

    'actions' => [
        'approve' => 'Freigeben',
        'reject' => 'Ablehnen',
        'delete' => 'Löschen',
        'highlight' => 'Als Highlight markieren',
        'unhighlight' => 'Highlight entfernen',
    ],

    'errors' => [
        'unreadable' => 'Die hochgeladene Datei konnte nicht als Bild gelesen werden.',
        'too_large' => 'Die hochgeladene Datei ist zu groß.',
        'invalid_type' => 'Dieser Dateityp wird nicht unterstützt.',
        'unauthorized' => 'Du bist nicht berechtigt, diese Aktion auszuführen.',
    ],

    'page' => [
        'title' => 'Galerie',
        'empty' => 'Noch keine Fotos für dieses Event — lade die ersten hoch.',
        'upload_title' => 'Fotos hochladen',
        'upload_label' => 'Fotos auswählen',
        'upload_caption_label' => 'Bildunterschrift (optional)',
        'upload_submit' => 'Hochladen',
        'uploading' => 'Wird hochgeladen …',
        'pending_badge' => 'Wird geprüft',
        'mine_badge' => 'Von dir',
        'highlight_badge' => 'Highlight',
        'uploaded_by' => 'Hochgeladen von',
        'delete' => 'Löschen',
        'delete_confirm_title' => 'Foto löschen?',
        'delete_confirm_body' => 'Das Foto wird endgültig entfernt.',
        'cancel' => 'Abbrechen',
        'no_upload_notice' => 'Melde dich für dieses Event an, um eigene Fotos hochzuladen.',
        'guest_notice' => 'Melde dich an, um die Galerie zu sehen und Fotos hochzuladen.',
        'load_error' => 'Die Galerie konnte nicht geladen werden.',
        'photo_count' => 'Fotos',
        'close' => 'Schließen',
    ],
];
