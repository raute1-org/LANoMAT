<?php

declare(strict_types=1);

return [
    'resource' => [
        'label' => 'Nutzer',
        'plural_label' => 'Nutzer',
    ],

    'files_quota' => [
        'action' => 'Dateikontingent',
        'label' => 'Upload-Kontingent pro Event',
        'default' => 'Standard (:default GB)',
        'help' => 'Leer lassen für den Standard (:default GB).',
    ],
    'change_role' => [
        'action' => 'Rolle ändern',
        'label' => 'Neue Rolle',
        'success' => 'Rolle geändert.',
        'failed' => 'Rolle nicht geändert',
    ],
    'fields' => [
        'name' => 'Name',
        'email' => 'E-Mail',
        'role' => 'Rolle',
        'created_at' => 'Registriert am',
        'files_quota_mb' => 'Upload-Kontingent',
    ],
];
