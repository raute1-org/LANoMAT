<?php

return [
    'errors' => [
        'unknown_linked_account_provider' => 'Dieser Anbieter steht aktuell nicht zur Verknüpfung zur Verfügung.',
        'unsupported_token_refresh' => 'Dieser Anbieter unterstützt keine Token-Erneuerung.',
        'token_refresh_failed' => 'Die Token-Erneuerung ist fehlgeschlagen. Bitte verknüpfe den Account erneut.',
        'cannot_change_own_role' => 'Du kannst deine eigene Rolle nicht ändern.',
        'last_admin' => 'Der letzte Administrator kann nicht herabgestuft werden.',
        'account_already_linked' => 'Dieser Account ist bereits mit einem anderen LANoMAT-Konto verknüpft.',
    ],

    'admin' => [
        'linked_accounts_title' => 'Verknüpfte Konten',
        'model_label' => 'Verknüpfung',
        'plural_model_label' => 'Verknüpfungen',
        'empty' => 'Keine verknüpften Konten',
        'provider' => 'Anbieter',
        'nickname' => 'Nickname',
        'linked_at' => 'Verknüpft am',
    ],
];
