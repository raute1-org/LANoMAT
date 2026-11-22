# NFSU2 im Browser (Underground 2 Revived) — Prüfung und Einordnung

Stand: **2026-11-22** (Recherche vom 2026-11-21; der Rezept-Abschnitt wurde am
22.11. gegen das Repo geprüft). Kein Deploy, keine Live-Änderung an
`lan.raute1.org`, am Homelab oder an `nfsu2.xyz`.

## 0. Ergebnis in drei Sätzen

1. **Ja, der Port läuft ohne Install im Browser.** Auf dem Event-Laptop
   (Linux/Hyprland, Intel Arc 130V) lud `https://nfsu2.xyz/` in Brave 157 und
   Chrome 157 mit hardwarebeschleunigtem WebGPU und bootete bis zum
   Profil-Screen; ein Spielstand ist nicht nötig, um zu starten.
2. **Als Browser-Fallback ist der öffentliche Server sinnvoll, als LAN-Pflicht
   nicht.** Er braucht Internet, schickt alle ins selbe öffentliche
   Multiplayer-Netz und hängt an einem archivierten Upstream. Für eine
   geschlossene Gruppe ist Self-Hosting im Event-LAN die richtige Variante,
   aber mit TLS-, DNS- und Build-Aufwand.
3. **Self-Host-Aufwand: mittel bis hoch.** Ein fertiges Bundle gibt es nicht
   (GitHub-Releases leer); das WASM-Modul muss aus der eigenen v1.2-Kopie mit
   Rust + wasi-sdk 34 gebaut werden. Dazu kommen TLS, Reverse Proxy und
   ~2–4 GB Spiel-/Moduldaten. Aufwand eher ein halber Tag Build + 2–4 h
   Betrieb, nicht „docker run und fertig“.

## 1. Quellen

| Quelle | Was daraus stammt |
| --- | --- |
| https://nfsu2.xyz/ | Live-Deployment, Startseite, Changelog, Spielerzahlen, `game.json`/`llms.txt` |
| https://github.com/th0mk/nfsu2-web | README, Build- und Play-Anleitung, MIT-Lizenz, Archiv-Status |
| `docs/web/PLAYING.md` im Upstream (Commit `9e02302`, 2026-11-09) | Voraussetzungen, WebGPU-Fehlerbilder, Saves, Online/Worlds, Self-Host, Ports, Grenzen |
| `web/deploy/README.md`, `web/deploy/nfsu2-web.service`, `web/deploy/Caddyfile`, `web/server-rs/README.md` | VPS-Bundle, TLS, Ports, State, Speicher, Max-Peers |
| `web/deploy/pack.sh`, `web/build.sh`, `web/server-rs/Dockerfile` | Was im Bundle steckt und was nicht |
| `https://nfsu2.xyz/game.json`, `/llms.txt`, `/manifest.json` | Modul-Rev, Größe, Betreiber-Hinweis, PWA |
| `docs/ops/nfsu2-server.md`, `docs/ops/nfsu2.md` (parallele Arbeit im Repo) | Host-Entscheidungen, Event-Server `10.23.0.12`, Homelab-Messwerte |

Upstream-Stand: letzter Commit `9e0230212776f6c9a4d0db30c1963e9730138553`
(2026-11-09, „README: the repository is archived“). Das Repository ist seit
**9. November 2026 archiviert**; `nfsu2.xyz` läuft weiter und wird privat
entwickelt. Code bleibt MIT, das generierte Spielmodul ist laut Betreiber-Hinweis
**nicht** zur Weitergabe/Änderung lizenziert.

## 2. Was der Port ist

- Die x86-Code des PC-Spiels wird vorab nach C übersetzt und als
  **WebAssembly** kompiliert; Direct3D 9 läuft über **WebGPU**, Sound über
  **Web Audio**. Kein Emulator, kein Plugin, kein Download im klassischen Sinn.
- Ein Server hält die Spieldateien, liefert Seite, Modul und Daten und verbindet
  die Spieler (virtueller LAN-Switch, Free-Roam-Hub, Online-Modus).
- **Öffentlicher Server:** Der Betreiber hostet seine eigene Spielkopie.
  Spieler brauchen **keine** NFSU2-Dateien; sie öffnen die Seite und drücken
  Play. Das ist der Zero-Install-Pfad.
- **Self-Host:** Nur dort braucht man die eigene PC-Kopie, gepatcht auf v1.2
  (Nordamerika), mit exakt dieser `SPEED2.EXE`: 4.800.512 Bytes, SHA-256
  `f9dd86c054878ce6276beb07c1fd61874f7a1e4bf1f241b084c65b73e24168a7`.
  Andere Versionen laufen nicht.

Ein **Mac ist nicht nötig**: der Build läuft auf Linux oder Apple Silicon;
getestet ist beides, Linux genügt. Ein **VPS** ist nur nötig, wenn Spieler
außerhalb des Host-LANs über eine öffentliche HTTPS-Adresse mitspielen sollen.
Im Event-LAN reicht ein Linux-Host mit einem TCP-Port.

## 3. Verifiziert auf dem Event-Laptop

Gemessen am 2026-11-21 auf dem Laptop, auf dem auch die Screenshots entstanden:

| Punkt | Wert |
| --- | --- |
| Gerät | Intel Core Ultra 200V (Lunar Lake), Intel Arc 130V/140V, 2880×1800 @ 120 Hz |
| Session | Linux, Hyprland/Wayland, Skalierung 1.6, `grim` verfügbar |
| Browser | Brave `157.1.99.55`, Chrome `157.0.8103.50`, Chromium `155.0.8059.84`; Firefox `158.0.1` installiert, aber nicht als Spielpfad getestet (Linux-WebGPU standardmäßig aus) |
| WebGPU in Chrome | `navigator.gpu` vorhanden, Adapter `vendor: intel`, `architecture: xe-2lpg`, **kein** Fallback-Adapter |
| WebGPU in Brave | derselbe Hardware-Adapter (`intel`, `xe-2lpg`) — kein Flag nötig |
| Seite | Startseite meldet **Ready**; `game.json` meldet Modul-Rev `1db860b7`, 41.829.599 Bytes |
| Spielstart | Play/Enter bootet bis **Create Profile / Enter Name**, Canvas 2304×1440; danach Intro/Cutscene |
| Bildrate | Brave-Log: Display 120 Hz erkannt, Content-Rate 120; Frame-Latenz p90 ~23 ms |
| Spieler live | 7.345 gesamt / 3.377 Free Roam / 141 in Rennen (Momentaufnahme 15:47) |

**Fazit Event-Laptop:** Ja, der Port läuft dort in Brave und Chrome ohne
Install, ohne Flag, ohne Spielkopie. Ein frisches Profil (oder Gastfenster) ist
wichtig; das normale Brave-Profil mit Erweiterungen kann Cross-Origin-Isolation
brechen (`PAGE-NOT-ISOLATED`).

### Screenshots

| Datei | Zeigt |
| --- | --- |
| `docs/screenshots/nfsu2/browser-start-page-chrome.png` | Startseite in Chrome, **Ready**, Spielerzahlen, Play |
| `docs/screenshots/nfsu2/browser-start-page-brave.png` | dieselbe Seite in Brave |
| `docs/screenshots/nfsu2/browser-start-page-grim-brave.png` | `grim`-Screenshot des Brave-Fensters (Desktop-Beweis, 1440×878) |
| `docs/screenshots/nfsu2/browser-profile-create-chrome.png` | Spiel gebootet: „Create Profile / Enter Name“ in Chrome |
| `docs/screenshots/nfsu2/browser-profile-create-brave.png` | dasselbe in Brave |
| `docs/screenshots/nfsu2/browser-intro-chrome.png`, `-2.png` | Intro/Cutscene nach Profilanlage (4:3, Letterbox) |

Die Screenshots enthalten keine Secrets, nur den öffentlichen Spielstand
„NAME…“ und die Startseite.

## 4. Voraussetzungen und Grenzen

### Browser und Grafik

- **Chrome, Edge, Brave, Opera** mit WebGPU. Firefox nur, wo es WebGPU hat
  (Windows, macOS mit Apple Silicon); **Firefox auf Linux hat es standardmäßig
  aus** (`dom.webgpu.enabled` in `about:config`).
- **WebAssembly SIMD**, Shared Memory, Cross-Origin-Isolation und eine
  **sichere Seite** (`https://`, oder `localhost` auf dem Host). Ein
  `http://10.x.y.z:8080` startet das Spiel **nicht**.
- **WebGPU auf echter Grafik.** Chrome auf Linux nutzt Vulkan: Mesa-Vulkan-Treiber
  muss installiert sein (`mesa-vulkan-drivers`, `vulkan-intel`/`vulkan-radeon`).
  Intel Gen12+ und NVIDIA auf Wayland sind default; ältere Karten brauchen
  `chrome://flags/#enable-unsafe-webgpu`. Intel HD 4000/4400/4600 (kein D3D12)
  fallen raus.
- **~1 GB freier Speicher** für das Spiel; wenige GB Plattencache, wenn man die
  Daten vorlädt.
- **Erweiterungen** können die Seite verändern; Gastfenster/leeres Profil ist
  der Standard-Test.

### Netz und Daten

- Erststart: **~7 MB Spielcode**, danach streamt das Spiel Daten. Vorladen:
  **~2 GB auf Platte**, **~1 GB über Netz**. Filme streamen oder lassen sich
  abschalten.
- Offline nach dem ersten Laden möglich, **wenn** die Daten vorher geladen
  wurden; Free Roam und Online brauchen den Server immer.
- Viele frische Laptops gleichzeitig können den Venue-Uplink sättigen — ein
  Argument für einen Server **im** Event-LAN.

### Saves, Login, 100 %

- Profile und Karriere liegen **im Browser** auf dem jeweiligen PC, pro
  Origin/Adresse. Privates Fenster = keine Saves danach; anderer Hostname =
  anderer Speicher.
- **Kein Account, keine E-Mail.** Optional **Cloud Saves** mit 8-Wort-Passphrase:
  die Seite verschlüsselt vor dem Upload, der Server speichert undurchsichtige
  Bytes. Ohne Passphrase kein Zurück.
- **Backup/Restore** als ZIP; PC-Spielstände lassen sich aus einem ZIP von
  `%LOCALAPPDATA%\NFS Underground 2` wiederherstellen.
- **Kein mitgelieferter 100-%-Save.** Ein kompletter Spielstand kommt aus einem
  solchen ZIP oder aus der Community; Save-Editor-Stände laden mit Warnung.

### Multiplayer

- Alle Spieler eines Servers treffen sich in **Worlds**: Main World, World 1–4
  oder ein eigener Name; `#room=`-Link lädt direkt in eine World ein.
  Worlds sind ein **Filter, keine Privatsphäre** — der Name ist für alle auf
  dem Server sichtbar.
- Dazu kommen Online-Lobbys/Rennen, Crews, Freunde, Freeroam-Rennen und
  Outruns. Der Server vermittelt die Fahrzeuge der nächsten Spieler.
- Ein deployter Rust-Server kann **bis 24.000** Peers; `--max-peers` default
  128, das mitgelieferte systemd-Unit-Beispiel setzt 2000. Ein voller Server
  stellt in eine Warteschlange; „Stop waiting: play offline“ spielt allein.
- Auf dem öffentlichen Server sind **fremde, echte Spieler** und teils ranked
  Rennen unterwegs. Cheat-Tools sind laut Betreiber-Hinweis verboten; das
  KI-Modul darf laut `llms.txt` nicht verändert/automatisiert werden. Für
  unseren Research heißt das: nicht patchen, nicht fahren lassen, nichts
  weitergeben.

### Upstream-Risiko

- Repository archiviert, Entwicklung privat. Der öffentliche Server kann
  Features haben, die der archivierte Stand nicht hat (z. B. Polizei in
  Rockport, Lobby-Traffic). Ein Self-Host ist auf dem Stand vom 9. November
  eingefroren.
- Modul und Spieldaten sind nicht redistributierbar; ein Self-Host bleibt
  intern.

## 5. Hosting: Optionen im Vergleich

| Option | Adresse | Für das Event | Aufwand | Bewertung |
| --- | --- | --- | --- | --- |
| **Öffentlicher Server** | `https://nfsu2.xyz/` | Internet nötig; fremde Spieler; keine Kontrolle | **null** | **Empfohlener Fallback** und einfachster Test |
| **Event-Server im LAN** | `10.23.0.12` (laut `docs/ops/nfsu2-server.md`) | lokal, privat, kürzester Weg, kein Uplink | Build + TLS + DNS + Container | **Primär, wenn geschlossene Gruppe gewünscht** |
| **Homelab (Unraid)** | `100.83.27.66` (Tailnet) / `192.168.68.57` | nur über Tailnet/Pangolin erreichbar; 55–150 ms RTT zum Event | Docker vorhanden, aber Build/Tunnel | **Zweite Wahl**, nicht für Rennen |
| **LANoMAT-App-Server** | `46.225.28.53` (öffentlich, Traefik) | öffentlich erreichbar, aber WAN-Bandbreite und NAT-Hairpin | DNS + Traefik-Router + Container | **Optional nach dem Event**, nicht fürs LAN-Racing |

### Homelab-Befund (read-only geprüft)

- Unraid 7.3.4, 20 Threads, 94 GB RAM (davon ~84 GB belegt, ~9 GB verfügbar),
  648 GB frei auf `/mnt/user`, Docker 29.8.2.
- **Kein offener 443/80-Port am LAN-Interface.** `443` lauscht nur auf
  `100.83.27.66%tailscale1` (Tailscale Serve → Nextcloud); öffentlicher Zugang
  läuft über Pangolin/Newt, nicht über Portfreigaben.
- Ports `8080`, `8081` sind bereits belegt; für NFSU2 einen freien Host-Port
  (z. B. `8098`) oder Docker-internen Port nehmen.
- Für Event-Clients wäre der Weg Tailnet (Client-Installation nötig) oder
  Pangolin (WAN). Beides ist für einen Browser-Shooter/Racer im LAN schlechter
  als ein Host im Event-LAN. Der vorhandene NFS-Online-Test bestätigt
  55–150 ms RTT vom Event zum Homelab.

### App-Server

Prinzipiell möglich: eigener Container hinter Traefik, DNS-Name z. B.
`nfsu2.raute1.org` (oder split-horizon `nfsu2.lan.raute1.org`), TLS über
Traefik. Nachteile: ~2 GB Spieldaten und ~40 MB Modul pro frischem Client über
den Venue-Uplink, dazu Fremdspieler-freie aber öffentlich erreichbare Instanz.
Nur sinnvoll, wenn ohnehin öffentlich gespielt werden soll.

## 6. Self-Host: konkrete Schritte (nicht ausgeführt)

1. **Spielkopie** v1.2 NA mit exakt o. g. `SPEED2.EXE` besorgen; nur die
   Spieldateien kopieren (`SPEED2.EXE`, `server.dll`, `server.cfg`, `CARS`,
   `CREDITS`, `FRONTEND`, `GLOBAL`, `LANGUAGES`, `memcard`, `MOVIES`, `NIS`,
   `pfdata`, `SOUND`, `speech`, `SUBTITLES`, `TRACKS`), **nie** in ein Image
   packen.
2. **Toolchain:** Python 3, Node.js 20+, Rust (rustup), wasi-sdk 34 nach
   `deps/`; Build in WSL2 nur im Linux-Dateisystem. Dann
   `web/gen.sh "<...>/SPEED2.EXE"`, `web/build.sh`,
   `web/server-rs/build.sh`. Ergebnis: `out/web/nfsu2.wasm` (~40 MB) und
   `nfsu2.json`; die Seite liegt in `web/dist`.
3. **Bundle (ohne Docker):** `web/deploy/pack.sh --arch amd64` erzeugt
   `out/web/deploy/nfsu2-web-<rev>-amd64.tar.gz` mit Server, Seite, Modul und
   Deploy-Dateien — **ohne** Spieldaten. Nach `/opt/nfsu2-web` entpacken.
4. **Daten:** Spielfolder nach `/srv/nfsu2/game`, lesbar für den Dienst
   (`chmod -R u=rwX,go=rX`). Sprachextras/Packs vorher mit
   `tools/packcheck.py` prüfen.
5. **Dienst:** `nfsu2-web.service` anpassen; eine Instanz, ein TCP-Port:
   - hinter Caddy/Traefik: `--behind-proxy --http-port 8080 --host <name>
     --max-peers 2000`, Proxy macht TLS auf 443;
   - direkt: `--port 443 --http-port 0 --tls-cert ... --tls-key ...`.
   State (`/var/lib/nfsu2-web` bzw. Docker-Volume `/state`) persistieren:
   ETag-Key, LAN-Tokens, Online-Records, Bans.
6. **TLS/DNS:** Für Event-LAN eine echte DNS-01-Wildcard + lokale DNS-Zone
   (siehe `docs/infra/lan-tls-certificates.md`), z. B.
   `nfsu2.<LAN_DOMAIN>` → `10.23.0.12`. Kein Port 80 nötig, wenn DNS-01.
   Alternativ interne CA + Root auf allen Laptops (mehr Aufwand).
7. **Proxy-Anforderungen:** WebSocket-Upgrade für `/lan` durchlassen, große
   Dateien/Range-Requests streamen (kein Buffering), viele Verbindungen
   erlauben (pro Spieler ~6 HTTP + 1 WebSocket), `Host`-Header des Servers
   setzen. Caddy-Beispiel im Upstream nutzt `max_conns_per_host 15900`.
8. **Firewall:** TCP 443 (bzw. 8443) von Clients; intern 8080 nur loopback.
   **Kein UDP** — Multiplayer läuft über WebSocket auf demselben Port.
9. **Kapazität:** Server ~110–250 MB RAM + bis 8 MB Queue pro langsamen
   Spieler; Modul ~40 MB, Spieldaten ~2 GB, Cache pro Client ~2 GB. CPU
   unkritisch, GPU nur clientseitig.
10. **Danach:** erster Load von einem Client-VLAN, nicht nur vom Host; ein
    Rennen/Free-Roam mit zwei Laptops; `PAGE-NOT-SECURE` und
    `PAGE-NOT-ISOLATED` aktiv prüfen.

**Nicht verifiziert:** Build-Dauer und Modulgröße auf unserer Hardware,
Verhalten hinter dem Venue-Traefik, gleichzeitige Erstlade-Last von 20–30
Laptops. Das sind die offenen Messpunkte vor einer Zusage.

## 7. Recipe-Variante im Repo

Im Repo, parallel zum nativen Rezept `need-for-speed-underground-2`:

```
resources/recipes/need-for-speed-underground-2-browser/
  recipe.yaml
  SKILL.md
```

| Feld | Wert |
| --- | --- |
| `slug` | `need-for-speed-underground-2-browser` |
| `title` | `Need for Speed: Underground 2 (Browser)` |
| `status` | `published` (Zero-Install-Pfad getestet; LAN-Hardware noch nicht) |
| `version` | `2026-11-21` |
| `platforms` | `windows`, `linux`, `macos` (Browser, nicht Spiel-OS) |
| `play_url` | `https://nfsu2.xyz/` |
| `metadata.live_url` | `https://nfsu2.xyz/` (nur mitgeführt; keine Oberfläche liest es) |
| `requires` | `[]` — **keine** Base-/ISO-/Patch-Datei, kein Download, kein Install |
| `metadata.players_max` | `64` (angezeigte Fahrzeuge; Server kann bis 24.000) |
| `steps` | 5: Browser/WebGPU prüfen, Seite öffnen, LAN-Gruppe beitreten, Saves/100 %, optional Self-Host |

Damit rendert die Games-Seite „Keine Dateien nötig … läuft direkt im Browser“
und „Im Browser starten“ statt „Installieren“ (`installable` ist bei leeren
`requires` false). Die YAML täuscht keine installierbaren Base-Dateien vor.
Für eine Instanz im Event-LAN wird `play_url` auf den dortigen TLS-Namen
gesetzt, z. B. `https://nfsu2.<LAN_DOMAIN>/`; `{{host}}` wäre hier falsch, weil
das die Adresse der LANoMAT-App ist, nicht des Spielservers.

**Das native Rezept nennt seit 2026-11-22 keine Adresse mehr** — es trägt
`{{server_host}}` (siehe [`nfsu2.md`](nfsu2.md) und
[`ADMIN-HANDOFF.md`](ADMIN-HANDOFF.md), Abschnitt 9). `metadata.live_url` ist
auch dort **nicht** nötig: die Spielseite liest den Schlüssel nicht.

## 8. Empfehlung

- **Browser als Fallback: ja.** Für „schnell mal fahren“, schwache Laptops ohne
  Wine und als Demo ist `nfsu2.xyz` unschlagbar: kein Install, keine
  Spieldateien, funktioniert auf dem Event-Laptop in Brave und Chrome.
- **Nicht als einziger LAN-Pfad.** Ohne Internet kein Erststart; fremde Spieler
  auf dem öffentlichen Server; Upstream archiviert; Saves hängen am Browser.
- **Für eine geschlossene LAN-Gruppe:** Self-Host auf `10.23.0.12`, TLS-Name
  im Venue-DNS, ein TCP-Port. Das ist der saubere Weg, kostet aber Build-,
  DNS- und Proxy-Zeit.
- **Homelab** ist wegen Tailnet/Pangolin und Latenz nur zweite Wahl, der
  App-Server nur für eine öffentliche Instanz nach dem Event.

## 9. Offene Punkte

- [ ] Build auf `10.23.0.12` oder einer Linux-Build-Maschine messen
      (Dauer, Modulbytes, RAM).
- [ ] TLS-Name im Venue-DNS festlegen (`nfsu2.<LAN_DOMAIN>`) und Wildcard
      prüfen.
- [ ] Traefik-Route mit WebSocket und Range-Requests testen; kein Buffering.
- [ ] 2–3 gemischte Laptops (Intel/AMD/NVIDIA, Brave/Chrome) im Event-LAN
      testen, inkl. `PAGE-NOT-ISOLATED` mit dem normalen Brave-Profil.
- [ ] Erstlade-Last von 20–30 Clients messen (Uplink vs. LAN-Host).
- [ ] Entscheiden, ob das öffentliche `nfsu2.xyz` im UI als „Live“ verlinkt
      wird: `metadata.live_url` wird heute von keiner Oberfläche gelesen —
      dafür bräuchte es erst eine; sonst bleibt der Server nur in der Skill
      und in der Rezeptbeschreibung genannt.
- [ ] `llms.txt`-Hinweis beachten: keine Modul-Patches, keine Automatisierung,
      keine Weitergabe des Moduls.
