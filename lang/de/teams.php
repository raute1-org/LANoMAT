<?php

return [
    'role' => ['owner' => 'Kapitän', 'member' => 'Mitglied'],
    'join_status' => ['pending' => 'Offen', 'accepted' => 'Angenommen', 'declined' => 'Abgelehnt'],

    'errors' => [
        'already_member' => 'Dieser Nutzer ist bereits Mitglied des Teams.',
        'request_pending' => 'Es liegt bereits eine offene Beitrittsanfrage vor.',
        'owner_must_transfer' => 'Der Kapitän muss die Teamleitung übergeben, bevor er das Team verlassen kann.',
        'not_a_member' => 'Der neue Kapitän muss bereits Mitglied des Teams sein.',
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
