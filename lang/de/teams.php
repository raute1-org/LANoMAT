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
];
