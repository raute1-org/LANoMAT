<?php

// Pull requests that carry a shared recipe into the git catalogue.
return [

    'section' => 'Katalog (Pull Request)',
    'section_help' => 'Der Rezepttext liegt im Git-Katalog; die Spieldateien bleiben auf dem LAN-Dateiserver.',

    'state' => [
        'open' => 'Pull Request offen',
        'merged' => 'Im Katalog',
        'closed' => 'Pull Request geschlossen',
        'blocked' => 'Nicht gesendet',
        'failed' => 'Fehlgeschlagen',
    ],

    // Console output of `lanomat:recipes:propose` and `lanomat:recipes:pr-status`.
    'commands' => [
        'missing' => 'Kein Rezept mit dem Slug ":slug" gefunden.',
        'not_configured' => 'Pull Requests sind nicht aktiviert (RECIPES_PR_ENABLED, RECIPES_PR_REPOSITORY, RECIPES_PR_TOKEN).',
        'nothing' => 'Keine Änderung am Katalog – für ":slug" wurde kein Pull Request angelegt.',
        'not_sent' => ':state: :reason',
        'failed' => 'Der Pull Request konnte nicht angelegt werden: :reason',
        'proposed' => ':state (#:number): :url',
        'kept' => 'Der Pull Request bleibt :state, wurde aber nicht neu gesendet: :reason',
        'synced' => ':count offene Pull Requests geprüft.',
        'sync_failed' => 'Nicht abgefragt: :slug (:reason)',
        'sync_replaced' => 'Übersprungen: der Pull Request von :slug wurde inzwischen ersetzt.',
    ],

    'never_synced' => 'Noch nicht mit dem Repository abgeglichen',
    'synced_at' => 'Zuletzt abgeglichen: :time',

];
