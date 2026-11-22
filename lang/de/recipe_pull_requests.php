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

    // The readiness preview on the edit page: what the catalogue checks would
    // refuse, as the draft and once published (the default trigger opens the
    // pull request while publishing). The reasons themselves are the checks'
    // English sentences, shown verbatim and escaped — see CatalogPreview.
    'preview' => [
        'heading' => 'Textprüfung für den Katalog',
        'reasons_help' => 'Die Punkte darunter sind die englischen Meldungen der Katalogprüfung, unverändert.',
        'ready' => 'Der Text ist bereit für den Katalog – ein Pull Request hätte nichts zu beanstanden.',
        'draft' => 'Als Entwurf fehlt noch:',
        'draft_help' => 'Der Katalog prüft einen Entwurf strenger als ein veröffentlichtes Rezept.',
        'published' => 'Nach dem Veröffentlichen fehlt noch:',
        'published_help_publish' => 'Mit „Pull Request beim Veröffentlichen“ entscheidet diese Liste, ob der Pull Request entsteht.',
        'published_help_contribute' => 'Der Pull Request entsteht schon beim Teilen; beim Veröffentlichen wird er mit diesem Stand aktualisiert.',
        'now' => 'Was den Pull Request gerade blockiert:',
    ],

    'never_synced' => 'Noch nicht mit dem Repository abgeglichen',
    'synced_at' => 'Zuletzt abgeglichen: :time',
    'stored_reason' => 'Grund des letzten Versuchs:',

];
