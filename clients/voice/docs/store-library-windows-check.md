# Prüfliste: Store-Bibliothek auf Windows (Stufe 1)

Für den Owner, auf dem eigenen Windows-PC. Die Prüfung läuft mit dem Windows-Testbuild des Clients
(Tauri-App, Tab "Spiele"). Ergebnis je Punkt: ok, Fehler (mit Beschreibung) oder nicht getestet.
Keine Zugangsdaten eintragen, nur Spieltitel und Ergebnisse notieren.

## Vorbereitung

- [ ] Steam, Epic Games Launcher, GOG Galaxy und Ubisoft Connect sind installiert.
- [ ] Je Shop mindestens ein Spiel installiert, möglichst eines auf einem zweiten Laufwerk oder in einer
      zweiten Steam-Bibliothek.
- [ ] Ein Testspiel bereithalten, dessen Ordner man vorübergehend umbenennen darf (nur für den Punkt "defekt").

## Erkennung

- [ ] **Steam:** jedes installierte Steam-Spiel steht im Tab "Spiele" mit dem richtigen Titel.
      Eine zweite Steam-Bibliothek (falls vorhanden) wird mitgelistet.
- [ ] **Epic:** jedes installierte Epic-Spiel steht mit dem richtigen Titel.
- [ ] **GOG:** jedes installierte GOG-Spiel steht mit dem richtigen Titel.
- [ ] **Ubisoft:** jedes installierte Ubisoft-Spiel steht mit dem richtigen Titel (Name aus dem Ordner).
- [ ] Die Quellen-Angabe (Steam, Epic, GOG, Ubisoft) stimmt je Eintrag.
- [ ] **Neu einlesen:** ein Spiel neu installieren (oder einen Shop-Eintrag anlegen), dann "Neu einlesen" drücken.
      Das neue Spiel erscheint danach ohne Neustart der App.

## Starten

- [ ] **Steam:** "Spielen" startet das Spiel über Steam (Steam öffnet sich bei Bedarf).
- [ ] **Epic:** "Spielen" startet das Spiel über den Epic Games Launcher.
- [ ] **Ubisoft:** "Spielen" startet das Spiel über Ubisoft Connect.
- [ ] **GOG mit Galaxy:** "Spielen" öffnet die Spielseite in GOG Galaxy (dort startet ein weiterer Klick das Spiel; so ist es in der Spec festgelegt).
- [ ] **GOG ohne Galaxy:** auf einem PC oder in einer VM ohne GOG Galaxy prüfen (Spiel aus dem Offline-Installer),
      oder Galaxy zum Test deinstallieren; ein zweites Windows-Konto reicht nicht, die Galaxy-Erkennung gilt
      für den ganzen PC. "Spielen" startet die Spiel-.exe direkt aus dem Spielordner.
- [ ] **Defekt:** den Ordner eines Testspiels umbenennen, "Neu einlesen" drücken. Das Spiel bleibt
      sichtbar und trägt den Status "defekt"; "Spielen" startet es nicht. Danach Ordner zurücknennen.

## Ohne Anmeldung und ohne Netz

- [ ] Client abmelden (Pairing lösen), dann auf dem Anmeldebildschirm "Meine Spiele ohne Anmeldung" wählen
      (nach dem Abmelden gibt es keinen Tab "Spiele"): die lokalen Spiele stehen da, der Grund für fehlende
      Server-Rezepte steht als eine Zeile, kein Fehlerbildschirm. Eine Minute warten: die App springt nicht
      zurück zum Anmeldebildschirm.
- [ ] **Angemeldet, Server nicht erreichbar:** angemeldet bleiben, den LANoMAT-Server abschalten oder das
      Netz trennen, die App neu starten, "Spiele" öffnen: die lokalen Spiele stehen nach wenigen Sekunden
      da, dazu eine Zeile "Keine Verbindung zum Server", kein Fehlerbildschirm.
- [ ] Netzwerk abschalten (WLAN/Kabel aus), "Spiele" öffnen und ein Store-Spiel starten: der Tab öffnet
      sich und das Spiel startet über den Shop.
- [ ] Netzwerk wieder einschalten.

## Einordnung der Einträge

- [ ] **Steam-Werkzeuge:** "Steamworks Common Redistributables" (App 228980) steht nicht in der Liste.
- [ ] **Steam-Update:** ein Spiel mit ausstehendem Update (Steam geschlossen, Spiel zwischenzeitlich gepatcht)
      trägt "Installiert" und "Spielen"; mit laufendem Download "Wird aktualisiert" ohne "Spielen".
- [ ] **Epic:** DLCs und Unreal-Engine-Plugins im Epic Launcher erscheinen nicht als eigene Spiele.
- [ ] **GOG:** erscheinen DLCs (Registry-Eintrag mit `dependsOn`) als eigene Spiele?

## Rezept-Zuordnung (nur mit LANoMAT-Server)

- [ ] Ein Rezept mit passender `store_ids`-Angabe (z. B. Steam-App-ID eines installierten Spiels) erscheint
      als **ein** Eintrag: LAN-Infos des Rezepts und Start über den Shop in einer Zeile.
- [ ] Ein Spiel, das in zwei Shops installiert ist und zu einem Rezept passt, ergibt einen zusammengeführten
      Eintrag mit beiden Shop-Spielen.
- [ ] Ein Rezept ohne passende Ids bleibt ein eigener Eintrag (kein Abgleich über den Titel).
- [ ] **Im Shop öffnen:** ein Rezept mit Steam-Id, dessen Spiel nicht installiert ist, bietet "Im Shop öffnen"
      und öffnet die Steam-Seite des Spiels.

## Ergebnis

- Datum, Windows-Version, Client-Version:
- Nicht getestete Punkte und Grund:
- Gefundene Fehler (Titel des Spiels, Quelle, was passiert ist):
