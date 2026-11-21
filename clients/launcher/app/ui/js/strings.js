// Every user-visible string of the launcher, German, in one place. Dynamic parts are
// functions. Server and Rust messages are shown as they arrive, never translated into
// invented facts.

export const DEFAULT_URL = 'https://lan.raute1.org';

// The server browser lives on the paired server, not on a fixed public host: a LAN
// installation must open its own /servers. Same path here, URL derived per server.
export const SERVERS_PATH = '/servers';

// The catalogue page of one game (`/games/{slug}`) is on the paired server as well:
// a "Bald im Launcher" card links there instead of promising an install.
export const GAMES_PATH = '/games/';

export const S = {
    loading: 'Wird geladen …',
    defaultUrl: DEFAULT_URL,
    httpError: (code) => `Der Server antwortet mit HTTP ${code}.`,

    phases: {
        planning: 'Planen',
        downloading: 'Herunterladen',
        extracting: 'Entpacken',
        patching: 'Patchen',
        verifying: 'Prüfen',
        ready: 'Fertig',
    },

    phaseState: {
        done: 'erledigt',
        current: 'aktueller Schritt',
        pending: 'ausstehend',
        failed: 'fehlgeschlagen',
    },

    connect: {
        urlLabel: 'Serveradresse',
        submit: 'Verbinden',
        submitting: 'Verbinde …',
        deviceTitle: 'Gerät freischalten',
        deviceHint:
            'Öffne die Anmeldeseite im Browser und gib diesen Code ein.',
        codeLabel: 'Anmeldecode',
        open: 'Browser öffnen',
        cancel: 'Abbrechen',
        waiting: 'Warte auf Bestätigung',
        slow: (seconds) =>
            `Der Server bremst die Abfrage. Nächster Versuch in ${seconds} s.`,
        expiredTitle: 'Code abgelaufen',
        expiredText:
            'Der Anmeldecode gilt nicht mehr. Erzeuge einen neuen Code.',
        deniedTitle: 'Anmeldung abgelehnt',
        deniedText:
            'Die Anmeldung wurde auf dem Server abgelehnt. Du kannst es neu versuchen.',
        failedTitle: 'Anmeldung fehlgeschlagen',
        failedText: 'Der Server hat die Anmeldung nicht angenommen.',
        networkText:
            'Der Server ist nicht erreichbar. Prüfe die Adresse und die Verbindung.',
        invalidUrlTitle: 'Serveradresse prüfen',
        invalidUrlText:
            'Die Adresse ist keine gültige http(s)-Adresse. Beispiel: https://lan.raute1.org',
        retry: 'Neuen Code erzeugen',
        change: 'Serveradresse ändern',
        emptyUrl: 'Bitte die Serveradresse eingeben.',
        connected: 'Verbunden. Die Spiele werden geladen.',
        cancelled: 'Anmeldung abgebrochen.',
        unknownState: (state) => `Unbekannter Zustand: ${String(state)}`,
    },

    library: {
        title: 'Spiele',
        refresh: 'Aktualisieren',
        loading: 'Spiele werden geladen',
        emptyTitle: 'Noch kein Spiel freigegeben.',
        emptyText:
            'Sobald die Orga ein Spiel für den Launcher freigibt, erscheint es hier.',
        errorTitle: 'Spiele konnten nicht geladen werden.',
        errorText: 'Der letzte Stand ist nicht verfügbar.',
        authText:
            'Die Anmeldung ist abgelaufen. Verbinde das Gerät neu, dann geht es weiter.',
        reconnect: 'Neu verbinden',
        loaded: (count) =>
            count === 1 ? '1 Spiel geladen.' : `${count} Spiele geladen.`,
        install: 'Installieren',
        start: 'Starten',
        reinstall: 'Neu installieren',
        repair: 'Reparieren',
        reinstallHint:
            'Installiert das Spiel erneut. Andere Installationen bleiben unberührt.',
        repairHint: 'Prüft die Installation und lädt nur Fehlendes nach.',
        stateReady: 'bereit',
        stateRepair: 'Reparatur nötig',
        stateMissing: 'Installationsordner fehlt',
        lanBadge: 'LAN',
        lanTitle: 'Vom LANoMAT-Server dieses Events',
        noSummary: 'Keine Beschreibung vom Server.',
        platforms: (list) => `Plattformen: ${list}`,
        installedTo: 'Installiert nach',
        verified: (count) =>
            count === 1 ? '1 Datei geprüft' : `${count} Dateien geprüft`,
        noVersion: 'Version ohne Angabe',
        // The local library: this terminal's own installs, no server involved.
        libraryTitle: 'Meine Bibliothek',
        libraryHint:
            'Auf diesem Gerät installiert — der Start klappt auch ohne Server.',
        libraryEmptyTitle: 'Noch nichts installiert.',
        libraryEmptyText:
            'Installiere ein Spiel aus der Serverliste. Es bleibt auf diesem Gerät.',
        installedLoaded: (count) =>
            count === 1
                ? '1 Spiel in deiner Bibliothek.'
                : `${count} Spiele in deiner Bibliothek.`,
        installed: 'Installiert',
        folder: 'Ordner',
        verifiedLabel: 'Geprüft',
        catalogTitle: 'Spiele vom Server',
        catalogHint:
            'Gemeinsame Liste des Events — jedes Gerät installiert für sich.',
        // A published recipe this build's install engine cannot drive yet.
        soon: 'Bald im Launcher',
        soonHint:
            'Der Launcher installiert dieses Spiel noch nicht. Auf der Webseite stehen alle Infos.',
        web: 'Auf der Webseite ansehen',
        webTitle: (title) => `${title} auf der LANoMAT-Seite öffnen`,
        webError: 'Die Spielseite konnte nicht geöffnet werden.',
    },

    chat: {
        title: 'Chat',
        skip: 'Zu den Spielen',
        clear: 'Neu starten',
        placeholder: 'Frage eingeben …',
        send: 'Senden',
        you: 'Du',
        assistant: 'LANoMAT',
        greeting: (name) =>
            name
                ? `Moin ${name}! Was möchtest du machen?`
                : 'Moin! Was möchtest du machen?',
        intro: 'Frag mich nach Spielen oder Servern — ich zeige dir die Karten.',
        hint: 'Zum Beispiel: Welche Spiele gibt es? · Welche Server laufen?',
        suggestionsLabel: 'Vorschläge',
        suggestionGames: 'Welche Spiele gibt es?',
        suggestionServers: 'Welche Server laufen?',
        thinking: 'Ich schaue nach …',
        gamesSummary: (count) =>
            count === 1
                ? 'Ein Spiel vom Server:'
                : `${count} Spiele vom Server:`,
        gamesEmptyTitle: 'Gerade ist kein Spiel freigegeben.',
        gamesEmptyText:
            'Sobald die Orga ein Spiel für den Launcher freigibt, erscheint es hier.',
        serversSummary: (servers, games) =>
            servers === 1
                ? `Ein Server${games ? ` in ${games === 1 ? 'einem Spiel' : `${games} Spielen`}` : ''}:`
                : `${servers} Server${games ? ` in ${games === 1 ? 'einem Spiel' : `${games} Spielen`}` : ''}:`,
        serversEmptyTitle: 'Gerade läuft kein Server.',
        serversEmptyText:
            'Sobald ein Event- oder Dauer-Server bereit ist, erscheint er hier.',
        unknown:
            'Das habe ich nicht verstanden. Frag mich nach Spielen oder Servern.',
        errorTitle: 'Das hat gerade nicht geklappt.',
        retry: 'Erneut fragen',
        authText:
            'Die Anmeldung ist abgelaufen. Verbinde das Gerät neu, dann geht es weiter.',
        reconnect: 'Neu verbinden',
        join: 'Beitreten',
        page: 'LANoMAT-Seite',
        permanent: 'Dauerhaft',
        noJoin: 'Noch keine Verbindung bekannt.',
        status: {
            ready: 'Läuft',
            not_responding: 'Antwortet nicht',
            offline: 'Offline',
            provisioning: 'Startet',
            pending: 'Wartet',
            failed: 'Fehlgeschlagen',
            stopped: 'Gestoppt',
        },
        unknownStatus: (status) => String(status || 'Unbekannt'),
        players: (players, max) => `${players} / ${max} Spieler`,
        map: (map) => `Karte: ${map}`,
        event: (name) => `Event: ${name}`,
        empty: 'Noch keine Nachricht. Tippe unten oder wähle einen Vorschlag.',
    },

    qr: {
        title: 'Mit Handy scannen',
        hint: 'Kamera öffnen, Code scannen und mit deinem LANoMAT-Konto anmelden.',
        label: 'QR-Code zur Anmeldung',
        fallback: 'Kein Handy zur Hand? Code unten eingeben oder Link öffnen.',
        failed: 'QR-Code konnte nicht gezeichnet werden — Code unten eingeben.',
    },

    install: {
        title: 'Installation',
        back: 'Zur Bibliothek',
        message: 'Installationsplan wird geladen …',
        file: 'Datei',
        bytes: 'Geladen',
        speed: 'Geschwindigkeit',
        eta: 'Restzeit',
        cancel: 'Abbrechen',
        cancelling: 'Wird abgebrochen …',
        cancellingHint: 'Der laufende Schritt wird beendet.',
        started: (title) => `Installation von ${title} gestartet.`,
        runningBack:
            'Die Installation läuft weiter — bitte den Launcher geöffnet lassen.',
        hint: 'Bitte den Launcher geöffnet lassen.',
        errorTitle: 'Die Installation ist fehlgeschlagen.',
        errorText:
            'Der letzte Schritt ist nicht abgeschlossen. Du kannst es erneut versuchen.',
        stoppedAt: (phase) => `Gestoppt bei „${phase}“.`,
        missingFiles: (files) => `Auf dem Server fehlen Dateien: ${files}`,
        noBaseFile:
            'Für dieses Spiel fehlt die Installationsdatei auf dem Server.',
        incomplete: (detail) => `Die Installation ist unvollständig: ${detail}`,
        launchFailed: (detail) =>
            `Der Start ist fehlgeschlagen: ${detail}`,
        cancelledTitle: 'Installation abgebrochen.',
        cancelledText:
            'Es wurde nichts gestartet. Du kannst es erneut versuchen.',
        retry: 'Erneut versuchen',
        retryAgain: 'Erneut installieren',
        ready: 'Fertig — bereit zum Start',
    },

    ready: {
        title: 'Startklar',
        lead: 'Das Spiel ist installiert und startbereit.',
        installed: 'Installiert',
        path: 'Installationsordner',
        version: 'Version',
        launch: 'Starten',
        launching: 'Wird gestartet …',
        repairShort: 'Reparieren',
        repair: 'Installation reparieren',
        repairHint:
            'Installiert die Dateien erneut. Der Download wird fortgesetzt.',
        browser: 'Serverbrowser',
        library: 'Zur Bibliothek',
        launched: (exe) => `Gestartet: ${exe}`,
        launchError: 'Das Spiel konnte nicht gestartet werden.',
        browserError: 'Der Browser konnte nicht geöffnet werden.',
        emptyTitle: 'Noch keine Installation.',
        emptyText: 'Installiere das Spiel zuerst in der Bibliothek.',
    },

    header: {
        logout: 'Abmelden',
        // The shell's logout only deletes this machine's pairing. The bearer token stays
        // valid on the server until it is revoked on /mcp/connect, so say so instead of
        // implying a full sign-out.
        logoutHint:
            'Trennt nur dieses Gerät. Der Zugriff bleibt gültig, bis du ihn auf dem Server unter /mcp/connect widerrufst.',
        logoutConfirm: 'Nur lokal abmelden?',
        loggedOut:
            'Lokal abgemeldet. Der Zugriff bleibt gültig, bis du ihn unter /mcp/connect widerrufst.',
        connectedTo: (server, user) =>
            user ? `Verbunden mit ${server} · ${user}` : `Verbunden mit ${server}`,
        // A room is full of identical launchers. Each window names its own machine;
        // every terminal pairs on its own and keeps its own installs.
        deviceLabel: 'Dieses Gerät:',
        deviceUnknown: 'PC',
        deviceHint:
            'Jedes Gerät koppelt sich selbst mit LANoMAT. Installationen bleiben auf diesem Gerät.',
        offline: 'Der Server ist nicht erreichbar. Die App versucht es weiter.',
        online: 'Der Server ist wieder erreichbar.',
        offlineRetry: 'Erneut versuchen',
    },

    deepLink: {
        needConnection: 'Deep-Link: Zuerst mit LANoMAT verbinden.',
        unknown: (slug) =>
            `Deep-Link: „${slug}“ ist auf diesem Server nicht freigegeben.`,
        notInstalled:
            'Deep-Link: Das Spiel ist noch nicht installiert. Installiere es zuerst.',
        notSupported: (slug) =>
            `Deep-Link: „${slug}“ ist noch nicht im Launcher installierbar. Die Webseite zeigt alle Infos.`,
        busy: 'Deep-Link: Es läuft bereits eine Installation.',
    },

    boot: {
        errorTitle: 'Der Launcher konnte nicht starten.',
        noTauri:
            'Diese Oberfläche braucht den LANoMAT Launcher. Bitte das Programm öffnen, nicht die HTML-Datei.',
        retry: 'Erneut versuchen',
    },

    footer: {
        build: (version, commit) => `Launcher ${version} · ${commit}`,
        unknownCommit: 'Commit unbekannt',
        update: (version) =>
            `Neue Launcher-Version verfügbar: ${version} — Launcher-Seite öffnen`,
        openError: 'Die Launcher-Seite konnte nicht geöffnet werden.',
    },

    // Arcade cabinet (`--arcade`, `--kiosk`). The same window serves many guests,
    // so signing out is framed as preparing the terminal for the next person.
    arcade: {
        badge: 'Arcade-Terminal',
        logout: 'Abmelden / Nächstes Gerät',
        logoutHint:
            'Meldet dieses Gerät ab, zeigt wieder den QR-Code und behält alle installierten Spiele.',
        logoutConfirm: 'Abmelden und QR für den nächsten Gast zeigen?',
        loggedOut:
            'Abgemeldet. Der QR-Code für das nächste Gerät steht bereit — die Spiele bleiben installiert.',
        kioskHint:
            'Kiosk-Modus: Das Vollbild bleibt, bis der Launcher beendet wird.',
        fullscreenLeft: 'Vollbild verlassen. Escape geht wieder einen Schritt zurück.',
    },

    // The gamepad line in the footer: the same signal the terminal heartbeat
    // reports to the orga, so what the monitor shows matches this window.
    gamepad: {
        label: 'Gamepad',
        connected: 'Gamepad verbunden',
        disconnected: 'Gamepad getrennt',
        hint: 'Steuerkreuz/Stick bewegt die Auswahl, A bestätigt, B geht zurück.',
    },
};
