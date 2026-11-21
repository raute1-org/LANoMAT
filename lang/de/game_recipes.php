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
        'size_bytes' => 'Größe (Bytes)',
        'size_bytes_help' => 'Optional: Dateigröße für die Anzeige im Installationsplan.',
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
        'contributor' => 'Beitrag von',
        'reviewed' => 'Zuletzt geprüft',
        'updated' => 'Aktualisiert',
    ],

    'actions' => [
        'publish' => 'Veröffentlichen',
        'archive' => 'Archivieren',
    ],

    // The launcher fleet monitor (LauncherTerminalResource) and the state a
    // terminal reports over POST /api/v1/terminals/heartbeat.
    'terminals' => [
        'resource' => [
            'label' => 'Terminal',
            'plural_label' => 'Terminals',
        ],

        'fields' => [
            'name' => 'Terminal',
            'state' => 'Status',
            'game' => 'Läuft gerade',
            'installed' => 'Installiert',
            'version' => 'Launcher-Version',
            'gamepad' => 'Gamepad',
            'last_seen' => 'Zuletzt gesehen',
            'paired_user' => 'Gepaart von',
            'remove' => 'Entfernen',
        ],
    ],

    'terminal_state' => [
        'online' => 'Online',
        'playing' => 'Spielt',
        'idle' => 'Bereit',
        'offline' => 'Offline',
    ],

];
