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
        'in_lineup' => 'In der Spieleliste zeigen',
        'in_lineup_help' => 'Die Spieler sehen die eingestellte Spieleliste plus jedes Rezept mit diesem Schalter. Ist keine Spieleliste eingestellt, erscheint ohnehin jedes veröffentlichte Rezept.',
        'file_state' => 'Datei',
        'pull_request' => 'Pull Request',
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
        'verify' => 'Prüfungen',
        'files' => 'Dateien',
        'contributor' => 'Beitrag von',
        'reviewed' => 'Zuletzt geprüft',
        'updated' => 'Aktualisiert',
    ],

    'actions' => [
        'publish' => 'Veröffentlichen',
        'published' => 'Rezept veröffentlicht',
        'publish_with_files' => 'Dateien freigeben und veröffentlichen',
        'publish_with_files_confirm' => 'Diese Dateien werden freigegeben und das Rezept wird veröffentlicht:',
        'publish_with_files_submit' => 'Freigeben und veröffentlichen',
        'publish_with_files_rejected' => 'Eine verknüpfte Datei wurde abgelehnt. Verknüpfe zuerst eine andere Datei — abgelehnte Dateien können nicht freigegeben werden.',
        'publish_with_files_forbidden' => 'Die Datei wurde nicht freigegeben',
        'publish_with_files_left_rejected' => 'Rezept veröffentlicht, aber eine verknüpfte Datei ist abgelehnt',
        'published_with_files' => 'Datei freigegeben und Rezept veröffentlicht|:count Dateien freigegeben und Rezept veröffentlicht',
        'propose_pull_request' => 'Pull Request öffnen / aktualisieren',
        'propose_pull_request_help' => 'Schickt den Rezepttext in den Git-Katalog. Die Spieldateien bleiben auf dem LAN-Dateiserver.',
        'propose_requested' => 'Pull Request wird vorbereitet',
        'propose_requested_help' => 'Der Stand erscheint gleich im Abschnitt „Katalog (Pull Request)“. Lade die Seite neu, falls er noch fehlt.',
        'archive' => 'Archivieren',
    ],

    // The file state of a shared game in the review list, decided from the
    // pinned LAN file of the recipe (see ContributionFiles).
    'contribution_file' => [
        'pending' => 'Datei wartet auf Freigabe',
        'rejected' => 'Datei abgelehnt',
        'missing' => 'Datei fehlt',
    ],

    'filters' => [
        'contributed' => 'Geteilte Spiele',
        'in_lineup' => 'Spieleliste',
        'in_lineup_any' => 'Alle',
        'in_lineup_yes' => 'In der Spieleliste',
        'in_lineup_no' => 'Nicht in der Spieleliste',
        'pull_request' => 'Pull Request',
    ],

    'navigation' => [
        'review_queue' => 'Geteilte Spiele, die auf Prüfung warten',
    ],

    // The read-only "Start und Launcher" section: what the launcher and the
    // start on the players' PCs actually run (see LauncherReview).
    'launcher' => [
        'section' => 'Start und Launcher',
        'section_help' => 'Was der Launcher und der Start auf den PCs der Spieler ausführen. Nur zur Ansicht — geändert wird es in der Rezeptdatei.',

        'offered' => 'Launcher bietet es an',
        'not_offered' => 'Launcher bietet es nicht an',
        'reason' => 'Grund: :reason',
        'reason_override_on' => 'der Schalter der Orga steht auf „immer anbieten“',
        'reason_override_off' => 'der Schalter der Orga steht auf „nie anbieten“',
        'reason_pilot' => 'UT2004-Pilot mit eigener Engine im Launcher',
        'reason_verified' => 'von Hand geprüft (generische Engine)',
        'reason_browser' => 'Browserspiel mit Spielen-Link',
        'reason_portable' => 'portables Archiv: Basis-Archiv, Datei-Prüfung und Startziel sind da',
        'reason_none' => 'keine Regel trifft zu — nicht Pilot, nicht geprüft, kein Spielen-Link, nicht portabel',

        'portable' => 'Portable (metadata.portable): :value',
        'portable_complete' => 'Form für den generischen Installer: vollständig (Pflicht-Basisarchiv, Datei-Prüfung, Startziel)',
        'portable_missing' => 'Portable ist gesetzt, dem generischen Installer fehlt aber noch: :missing',
        'missing_base' => 'ein Pflicht-Basis-Archiv',
        'missing_check' => 'eine Datei- oder Ordner-Prüfung in einem nicht optionalen Schritt',
        'missing_target' => 'ein Startziel',
        'yes' => 'ja',
        'no' => 'nein',

        'play_url' => 'Spielen-Link (metadata.play_url): :value',
        'targets' => 'Startbefehle (laufen auf dem PC der Spieler)',
        'no_targets' => 'Keine Startbefehle hinterlegt.',
        'no_command' => '(kein Befehl hinterlegt)',
        'no_label' => '(ohne Bezeichnung)',
        'no_shell' => 'ohne Shell',
        'derived' => 'abgeleitet aus :source',
        'verify_os' => ' · nur :os',

        'override' => 'Launcher-Freigabe (metadata.launcher_supported)',
        'override_help' => 'Automatisch entscheidet nach den Regeln oben. „Immer anbieten“ und „nie anbieten“ überschreiben sie. Alle anderen Metadaten bleiben unverändert.',
        'override_auto' => 'Automatisch entscheiden',
        'override_always' => 'Immer anbieten',
        'override_never' => 'Nie anbieten',
    ],

    // The publish confirmation: what lands on the players' PCs.
    'release' => [
        'heading' => 'Das wird veröffentlicht',
        'counts' => ':steps Schrittbefehle und :launch Startbefehle',
        'runs_on_players' => 'Diese Befehle laufen auf den PCs der Spieler.',
        'no_launch' => 'Keine Startbefehle hinterlegt.',
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
