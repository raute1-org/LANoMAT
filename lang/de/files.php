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
        'uploads_unavailable' => 'Der Upload ist gerade nicht verfügbar. Bitte wende dich an das Orga-Team.',
        'use_resumable_upload' => 'Bitte lade die Datei über die Upload-Fläche auf dieser Seite hoch.',
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

        // Resumable uploader (resources/js/components/files/ResumableUploader.vue)
        'up_pick' => 'Dateien auswählen',
        'up_drop' => 'oder hierher ziehen',
        'up_hint' => 'Auch große Spiele sind möglich, bis :max pro Datei. Sie werden in Teilen direkt zum Dateiserver übertragen und machen nach einer Unterbrechung an derselben Stelle weiter. Lass diese Seite während des Uploads offen. Jede Datei wartet danach auf die Freigabe durch das Orga-Team.',
        'up_hint_reload' => 'Seite neu geladen oder Tab geschlossen? Wähle dieselbe Datei noch einmal aus, der Upload setzt dort fort, wo er gestoppt hat.',
        'up_status_queued' => 'Wartet auf einen freien Platz',
        'up_status_preparing' => 'Wird vorbereitet …',
        'up_status_uploading' => 'Lädt hoch',
        'up_status_resumed' => 'Nach Unterbrechung fortgesetzt, lädt hoch',
        'up_status_reconnecting' => 'Verbindung unterbrochen, neuer Versuch läuft …',
        'up_status_paused' => 'Pausiert',
        'up_status_done' => 'Hochgeladen, wartet auf Freigabe durch das Orga-Team',
        'up_eta' => 'noch :time',
        'up_pause' => 'Pausieren',
        'up_resume' => 'Fortsetzen',
        'up_cancel' => 'Abbrechen',
        'up_retry' => 'Erneut versuchen',
        'up_dismiss' => 'Ausblenden',
        'up_err_too_large' => 'Die Datei ist größer als die erlaubte Dateigröße.',
        'up_err_blocked_type' => 'Dieser Dateityp ist nicht erlaubt: HTML-, SVG- und JavaScript-Dateien werden abgelehnt.',
        'up_err_quota_exceeded' => 'Dein Upload-Kontingent für dieses Event reicht für diese Datei nicht mehr.',
        'up_err_ticket_expired' => 'Die Upload-Berechtigung ist abgelaufen. Starte den Upload erneut.',
        'up_err_ticket_invalid' => 'Die Upload-Berechtigung wurde nicht akzeptiert. Starte den Upload erneut.',
        'up_err_ticket_mismatch' => 'Die Datei passt nicht zur Upload-Berechtigung. Starte den Upload erneut.',
        'up_err_uploads_unavailable' => 'Der Upload ist gerade nicht verfügbar. Bitte wende dich an das Orga-Team.',
        'up_err_rate_limited' => 'Zu viele Uploads in kurzer Zeit. Warte eine Minute und versuche es erneut.',
        'up_err_host_full' => 'Auf dem Dateiserver ist gerade kein Platz für neue Uploads. Bitte melde dich beim Orga-Team.',
        'up_err_network' => 'Der Dateiserver ist nicht erreichbar. Prüfe deine Verbindung und versuche es erneut, bereits übertragene Teile bleiben erhalten.',
        'up_err_generic' => 'Der Upload ist fehlgeschlagen. Versuche es erneut.',
    ],
];
