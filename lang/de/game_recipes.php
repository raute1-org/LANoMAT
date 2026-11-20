<?php

return [

    'resource' => [
        'label' => 'Spielrezept',
        'plural_label' => 'Spielrezepte',
    ],

    'status' => [
        'draft' => 'Entwurf',
        'published' => 'Veröffentlicht',
        'archived' => 'Archiviert',
    ],

    'fields' => [
        'slug' => 'Slug',
        'title' => 'Titel',
        'aliases' => 'Aliasse',
        'aliases_help' => 'Was Nutzer tippen könnten: ut2004, ut 2k4, unreal tournament.',
        'version' => 'Version',
        'status' => 'Status',
        'platforms' => 'Plattformen',
        'summary' => 'Kurzfassung',
        'description' => 'Beschreibung',
        'homepage' => 'Homepage',
        'license' => 'Lizenz',
        'skill_markdown' => 'SKILL.md',
        'skill_help' => 'Der Text, den der pi-Agent als Sub-Skill lädt. Leer lassen, dann wird er aus den Feldern erzeugt.',
        'requirements' => 'Benötigte Dateien',
        'role' => 'Rolle',
        'file_label' => 'Bezeichnung',
        'match' => 'Dateinamen-Muster',
        'url' => 'Externe URL',
        'sha256' => 'SHA-256',
        'shared_file' => 'LAN-Datei-ID',
        'shared_file_help' => 'Optional eine konkrete hochgeladene Datei festnageln (statt Muster-Matching).',
        'required' => 'Pflicht',
        'steps' => 'Schritte',
        'step_title' => 'Titel',
        'os' => 'Betriebssystem',
        'optional' => 'Optional',
        'detail' => 'Anleitung',
        'commands' => 'Befehle',
        'files' => 'Dateien',
        'updated' => 'Aktualisiert',
    ],

    'actions' => [
        'publish' => 'Veröffentlichen',
        'archive' => 'Archivieren',
    ],

];
