<?php

return [
    'errors' => [
        'cannot_friend_self' => 'Du kannst dir selbst keine Freundschaftsanfrage senden.',
        'already_friends' => 'Ihr seid bereits miteinander befreundet.',
        'request_pending' => 'Zwischen euch besteht bereits eine offene Freundschaftsanfrage.',
        'blocked' => 'Einer der beiden Nutzer hat den anderen blockiert.',
        'cannot_block_self' => 'Du kannst dich nicht selbst blockieren.',
    ],

    'notifications' => [
        'request_received' => [
            'title' => 'Neue Freundschaftsanfrage',
            'body' => ':name möchte dein Freund sein.',
        ],
        'request_accepted' => [
            'title' => 'Freundschaftsanfrage angenommen',
            'body' => ':name hat deine Freundschaftsanfrage angenommen.',
        ],
    ],

    'page' => [
        'title' => 'Freunde',
        'description' => 'Anfragen, Freunde und Vorschläge auf einen Blick.',

        'incoming_title' => 'Anfragen',
        'incoming_empty' => 'Keine offenen Anfragen — melden wir uns, sobald jemand dich adden will.',
        'accept' => 'Annehmen',
        'decline' => 'Ablehnen',

        'friends_title' => 'Freunde',
        'friends_empty' => 'Noch keine Freunde — schau bei den Vorschlägen vorbei.',
        'remove' => 'Entfernen',
        'remove_confirm' => 'Freundschaft wirklich beenden?',
        'block' => 'Blockieren',

        'suggestions_title' => 'Vorschläge',
        'suggestions_empty' => 'Gerade keine Vorschläge — nimm an einem Event oder Turnier teil, dann sehen wir Überschneidungen.',
        'add' => 'Anfragen',
        'shared_count' => 'Überschneidungen: :count',
        'reason_shared_event' => 'Gemeinsames Event',
        'reason_shared_team' => 'Gemeinsames Team',
        'reason_shared_tournament' => 'Gemeinsames Turnier',
        'reason_shared_steam_friend' => 'Steam-Freund',

        'outgoing_title' => 'Gesendete Anfragen',
        'outgoing_empty' => 'Keine ausstehenden Anfragen.',
        'cancel' => 'Zurückziehen',

        'blocked_title' => 'Blockiert',
        'blocked_empty' => 'Niemand blockiert.',
        'unblock' => 'Entsperren',
        'unblock_confirm' => 'Blockierung wirklich aufheben?',
    ],
];
