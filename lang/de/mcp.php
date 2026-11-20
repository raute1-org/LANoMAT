<?php

return [

    'connect' => [
        'title' => 'Agent & MCP verbinden',
        'description' => 'Koppelt den LANoMAT-Agenten (pi) oder einen anderen MCP-Client mit deinem Konto.',
        'enter_heading' => 'Code eingeben',
        'enter_help' => 'Starte die Verbindung im Agenten. Er zeigt dir einen Code aus acht Zeichen an.',
        'code_label' => 'Code aus dem Agenten',
        'code_placeholder' => 'ABCD-EFGH',
        'continue' => 'Weiter',
        'confirm_heading' => 'Diesen Agenten verbinden?',
        'confirm_help' => 'Der Agent kann danach in deinem Namen Events, Turniere und Dateien lesen und Installationspläne abrufen. Genehmige nur, wenn du die Verbindung gerade selbst gestartet hast und der Code übereinstimmt.',
        'device' => 'Agent',
        'code' => 'Code',
        'approve' => 'Verbinden',
        'deny' => 'Ablehnen',
        'invalid' => 'Dieser Code ist unbekannt, abgelaufen oder schon benutzt. Starte die Verbindung im Agenten neu.',
        'approved' => 'Agent verbunden. Du kannst zum Terminal zurückkehren.',
        'denied' => 'Verbindung abgelehnt.',
        'revoked' => 'Agent getrennt.',
        'devices_heading' => 'Verbundene Agenten',
        'devices_empty' => 'Noch kein Agent verbunden. Nach `lanomat-agent login` erscheint er hier.',
        'created' => 'Verbunden am',
        'last_used' => 'Zuletzt genutzt',
        'never_used' => 'Noch nie',
        'revoke' => 'Trennen',
    ],

];
