<?php

return [
    'role' => ['owner' => 'Kapitän', 'member' => 'Mitglied'],
    'join_status' => ['pending' => 'Offen', 'accepted' => 'Angenommen', 'declined' => 'Abgelehnt'],

    'errors' => [
        'logo_unreadable' => 'Das Logo konnte nicht verarbeitet werden. Nutze ein normales Bild (PNG, JPEG, WebP oder GIF) in üblicher Größe.',
        'already_member' => 'Du bist bereits Mitglied dieses Teams.',
        'request_pending' => 'Es liegt bereits eine offene Beitrittsanfrage vor.',
        'owner_must_transfer' => 'Der Kapitän muss die Teamleitung übergeben, bevor er das Team verlassen kann.',
        'not_a_member' => 'Der neue Kapitän muss bereits Mitglied des Teams sein.',
        'stale_request' => 'Diese Beitrittsanfrage ist nicht mehr gültig.',
        'request_already_answered' => 'Diese Beitrittsanfrage wurde bereits beantwortet.',
        'name_taken' => 'Dieser Teamname ist bereits vergeben.',
    ],

    'notifications' => [
        'request_received' => [
            'title' => 'Neue Beitrittsanfrage',
            'body' => ':name möchte dem Team :team beitreten.',
        ],
        'request_accepted' => [
            'title' => 'Beitrittsanfrage angenommen',
            'body' => 'Du bist jetzt Mitglied im Team :team.',
        ],
        'request_declined' => [
            'title' => 'Beitrittsanfrage abgelehnt',
            'body' => 'Deine Anfrage für das Team :team wurde abgelehnt.',
        ],
    ],

    'page' => [
        'title' => 'Teams',
        'create' => 'Team erstellen',
        'create_name' => 'Name',
        'create_tag' => 'Tag',
        'join' => 'Beitreten',
        'leave' => 'Team verlassen',
        'leave_confirm' => 'Team wirklich verlassen?',
        'members' => 'Mitglieder',
        'requests' => 'Beitrittsanfragen',
        'accept' => 'Annehmen',
        'decline' => 'Ablehnen',
        'transfer' => 'Kapitän übertragen',
        'transfer_help' => 'Gib die Teamleitung an ein Mitglied ab. Danach kannst du das Team verlassen.',
        'transfer_select' => 'Neuer Kapitän',
        'transfer_select_placeholder' => 'Mitglied wählen',
        'transfer_confirm' => 'Teamleitung wirklich übertragen? Du kannst das nicht selbst rückgängig machen.',
        'transfer_no_members' => 'Dein Team hat noch kein weiteres Mitglied, an das du die Leitung übergeben kannst.',
        'transferred' => 'Die Teamleitung wurde übertragen.',
        'owner' => 'Kapitän',
        'logo' => 'Logo',
        'save' => 'Speichern',
        'edit' => 'Bearbeiten',
        'no_teams' => 'Noch keine Teams vorhanden.',
        'no_requests' => 'Keine offenen Beitrittsanfragen.',
        'already_member' => 'Du bist bereits Mitglied dieses Teams.',
        'login_to_join' => 'Melde dich an, um beizutreten.',
    ],

    'resource' => [
        'label' => 'Team',
        'plural_label' => 'Teams',
    ],

    'fields' => [
        'name' => 'Name',
        'tag' => 'Tag',
        'logo' => 'Logo',
        'owner' => 'Kapitän',
        'members_count' => 'Mitglieder',
    ],
];
