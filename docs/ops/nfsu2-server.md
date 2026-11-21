# NFS Underground 2 Online-Server — Betriebs-Runbook

Stand: **2026-11-21**. Getestet auf dem Laptop `10.23.3.220` mit
NFS Online Server **1.1.3** (Classic V958, Client 0.2.22), Python 3.14.8,
Docker 29.9.2 / Compose 5.6.1 und Python 3.12.16 im Container.

Ziel: ein U2-Online-/LAN-Server für das Event, betrieben als Docker-Service
aus dem LANoMAT-Repo. Kein Spiel, keine Spielinhalte und keine Zugangsdaten
liegen im Repo.

## 1. Host-Entscheidung

| Host | Adresse | Rolle | Bewertung |
| --- | --- | --- | --- |
| **Event-Server** | `10.23.0.12` | **Primär für das Event** | Gleiches `/22` wie die Clients, kürzester Weg, vorhandene Event-Infrastruktur (u. a. `files.lan.raute1.org`), Docker-Betrieb vorgesehen. |
| Laptop (Test) | `10.23.3.220` | **Getestete Referenz / Fallback** | Voller Funktionstest bestanden, aber WLAN, DHCP-Lease und Suspend machen ihn für ein ganzes Event ungeeignet. |
| LANoMAT-App-Server | `46.225.28.53` | **Optional für Online nach dem Event** | Öffentlich erreichbar, aber zusätzliche Portfreigaben, Abhängigkeit von der App-Infrastruktur und für Event-Clients im LAN potenziell NAT-Hairpin. |

**Entscheidung:** Der Server läuft für das Event auf **`10.23.0.12`**.
Die Clients bekommen `10.23.0.12` als Host. Der Laptop bleibt der getestete
Fallback; `46.225.28.53` ist eine spätere, separate Online-Instanz.

Wichtig: `PUBLIC_HOST` ist die Adresse, die der Server **den Clients
ankündigt** (Bootstrap, Lobby, Control, Race). Sie muss exakt die Adresse
sein, unter der die Clients den Server erreichen. Eine Instanz kann nicht
gleichzeitig LAN- und WAN-Clients korrekt bedienen, wenn dafür
unterschiedliche Adressen nötig sind — dann zwei Instanzen oder Split-DNS
mit einem Namen, der intern auf `10.23.0.12` und extern auf die öffentliche
IP zeigt.

## 2. Ports

Der Server bindet nach dem Start:

| Dienst | Protokoll | Port(s) | Zweck |
| --- | --- | --- | --- |
| Shared EA Messenger | TCP | `13505` | Messenger/Control-Alias, HTTP-multiplexed |
| U2 Bootstrap | TCP | `20921` | Serverliste/Bootstrap |
| U2 Lobby/Auth | TCP | `20922` | Login und Lobby |
| U2/MW Control + PREL | TCP | `20923` | News, TOS, Prel |
| U2 Race Relay | **UDP** | **`20000-20005`** | ein Port pro Teilnehmer, 6 Channels |
| Messenger IPC | TCP | `13506` | nur `127.0.0.1`, **nicht** freigeben |

Die Task-Annahme „nur 20000/udp“ ist zu knapp: der Server bindet
`20000,20001,20002,20003,20004,20005` (Log: `channels=6`). Bei
`GAME_MAX_PLAYERS = 6` müssen **alle sechs** UDP-Ports erreichbar sein.
Most Wanted (`30920/30921/tcp`) und Carbon (`18210/18215/tcp`,
`19119/udp`, `9000/tcp`) sind nur nötig, wenn `NFS_GAMES` sie enthält.

### Firewall auf dem Event-Server

Beispiel mit `ufw`:

```bash
ufw allow 13505/tcp
ufw allow 20921:20923/tcp
ufw allow 20000:20005/udp
```

Beispiel mit `nftables`:

```bash
nft add rule inet filter input tcp dport { 13505, 20921, 20922, 20923 } accept
nft add rule inet filter input udp dport 20000-20005 accept
```

Docker veröffentlicht die Ports über die `DOCKER`-Kette. Eine `ufw`-Regel
greift je nach Host-Konfiguration nicht automatisch für veröffentlichte
Container-Ports. Deshalb **von einem zweiten LAN-Host** prüfen, nicht nur
auf dem Server selbst:

```bash
for p in 13505 20921 20922 20923; do nc -zv 10.23.0.12 "$p"; done
```

UDP hat keinen Handshake; `nc -zuv` beweist nichts. UDP 20000–20005
verifiziert man mit `tcpdump`/`ss` und vor allem im Spiel.

## 3. Deployment als Docker-Service

Dateien im LANoMAT-Repo:

```text
docker/nfs-online/Dockerfile
docker/nfs-online/compose.yml
docker/nfs-online/docker-entrypoint.sh
```

Das Image basiert auf `python:3.12-slim`, bringt den NFS-Server per
`COPY --from=nfs` mit und startet `nfs_online.py` **im Vordergrund**
(`start --games u2`, kein `--daemon`). Die Logs der Kindprozesse landen
dadurch auf stdout und in `docker compose logs`. `stdin_open: true` hält
die interaktive Konsole offen; ohne stdin würde sie EOF sehen und
herunterfahren.

Das Server-Repo muss nicht im LANoMAT-Repo liegen. Der Pfad wird als
benannter Build-Context übergeben:

```bash
git clone https://github.com/moio9/NFS-Online-Server.git /opt/NFS-Online-Server
git -C /opt/NFS-Online-Server checkout 96699af   # getesteter Stand (2026-11-11)

export NFS_ONLINE_SRC=/opt/NFS-Online-Server       # Checkout des NFS-Repos
export NFS_PUBLIC_HOST=10.23.0.12                  # Client-Adresse
export NFS_GAMES=u2

cd /opt/LANoMAT
docker compose -f docker/nfs-online/compose.yml up -d --build
docker compose -f docker/nfs-online/compose.yml ps
docker compose -f docker/nfs-online/compose.yml logs -f --tail=50
```

Ohne `NFS_ONLINE_SRC` zeigt der Compose-Default auf den
Entwickler-Checkout dieses Laptops
(`../../../../Downloads/nfsu2/NFS-Online-Server`). Auf jedem anderen Host
zwingend setzen.

Persistenz (Named Volumes, Projektname `lanomat-nfs-online`):

| Volume | Mount | Inhalt |
| --- | --- | --- |
| `nfs-online-config` | `/app/config` | `server.toml`, vom Entrypoint aus `PUBLIC_HOST` gesetzt |
| `nfs-online-data` | `/app/data` | `accounts.sqlite3`, `users/`, Carbon-Assets, Runtime-State |
| `nfs-online-logs` | `/app/logs` | zusätzliche Logdateien |

Ein frisches Volume wird aus dem Image initialisiert; Laufzeitdaten
(Accounts, Sessions, `server-state.json`) werden beim Build aus dem Seed
entfernt, damit kein Test-Account ins Image wandert.

### Accounts

Interaktiv (Passwort wird nicht in `ps`/Shell-History sichtbar):

```bash
docker compose -f docker/nfs-online/compose.yml run --rm nfs-online create-account
docker compose -f docker/nfs-online/compose.yml run --rm -T nfs-online account list
```

`account create NAME --password ...` funktioniert auch, legt das Passwort
aber in der Prozessliste und Shell-History offen. Für das Event pro Spieler
ein eigenes Konto anlegen; keine gemeinsamen Accounts.

### Healthcheck, Stop, Update, Backup

```bash
# Healthcheck: lauscht 20922? (0x51BA in /proc/net/tcp)
docker compose -f docker/nfs-online/compose.yml ps

# Stop, Daten bleiben erhalten
docker compose -f docker/nfs-online/compose.yml down

# Update
git -C "$NFS_ONLINE_SRC" pull
docker compose -f docker/nfs-online/compose.yml build --pull
docker compose -f docker/nfs-online/compose.yml up -d

# Backup des Daten-Volumes
docker run --rm \
  -v lanomat-nfs-online_nfs-online-data:/data:ro \
  -v "$PWD":/backup \
  alpine tar czf /backup/nfs-online-data-$(date +%F).tgz -C /data .
```

Der Healthcheck öffnet bewusst keine TCP-Verbindung, sondern liest
`/proc/net/tcp`; sonst würde alle 15 s ein Lobby-Connect/Disconnect im Log
stehen.

## 4. Client bauen

Upstream-Quirk: `build_linux.sh` bootstrappt Zig nach
`source/client-zig/.tools/`. `tools/check_source.py` zählt danach die
`.cpp`-Dateien der Zig-Toolchain als „non-Zig implementation files“ und der
Build bricht ab. Deshalb Zig **außerhalb** des Repos ablegen und per `ZIG`
übergeben:

```bash
mkdir -p /opt/nfs-online-tools && cd /opt/nfs-online-tools
curl -L --fail -o zig-0.16.0.tar.xz \
  https://ziglang.org/download/0.16.0/zig-x86_64-linux-0.16.0.tar.xz
sha256sum -c <<'EOF'
70e49664a74374b48b51e6f3fdfbf437f6395d42509050588bd49abe52ba3d00  zig-0.16.0.tar.xz
EOF
tar -xf zig-0.16.0.tar.xz

cd /opt/NFS-Online-Server/source/client-zig
ZIG=/opt/nfs-online-tools/zig-x86_64-linux-0.16.0/zig ./build_linux.sh
```

Zweiter Upstream-Quirk: `nfs_online.py configure --public-host` aktualisiert
`clients/*/*.ini`, aber nicht `source/client-zig/config/*.ini`. Der
Port-Audit des Builds vergleicht beide und schlägt fehl. Vor dem Build die
veröffentlichten Configs übernehmen:

```bash
cd /opt/NFS-Online-Server
cp clients/underground2/net_u2.ini  source/client-zig/config/net_u2.ini
cp clients/most-wanted/net_mw.ini   source/client-zig/config/net_mw.ini
cp clients/carbon/net_carbon.ini    source/client-zig/config/net_carbon.ini
```

Ergebnis (dieser Test):

```text
client-zig/zig-out/bin/net_u2.asi        PE32 DLL, Intel i386, 112.640 B
client-zig/zig-out/bin/net_u2.ini        606 B, host = 10.23.0.12
sha256 net_u2.asi  8db6557e685cf2dd47f825e62cc202cbace85f6cc4a43af271b8238e0367a04f
sha256 net_u2.ini  326b77932af8c280cced1071dfd86f797f450503a18d8b3f851ab0a8d71a43f9
```

`zig-out/bin/` ist gitignored und nicht Teil des Server-Images. Die Dateien
gehören in die Client-Auslieferung (LANoMAT-Dateihost), nicht ins Repo.

## 5. Client installieren und konfigurieren

1. `net_u2.asi` und `net_u2.ini` **nebeneinander** in das ASI-/Plugin-
   Verzeichnis von NFSU2 kopieren (üblicherweise Spielwurzel bzw. dort, wo
   der ASI Loader lädt). Ein ASI Loader (z. B. Ultimate ASI Loader) muss
   vorhanden sein.
2. Keine alten Netzwerk-Hooks parallel laden; alle Teilnehmer müssen die
   gleiche Client-Version (`0.2.22`) verwenden.
3. `net_u2.ini` auf den Server-Host setzen. Für das Event:

```ini
[network]
enabled = true
host = 10.23.0.12
bootstrap_port = 20921
lobby_port = 20922
control_port = 20923
control_alias_port = 13505
race_port = 20000

[lan]
enabled = true
host = 10.23.0.12
port = 20922
inject_server = true

[patches]
enabled = true

[logging]
enabled = true
udp_trace = false
```

`host` steht in beiden Sections. Der sauberste Weg, beide Werte und die
drei Client-Configs konsistent zu setzen:

```bash
python3 nfs_online.py configure --public-host 10.23.0.12
```

Danach Client neu bauen (Abschnitt 4), damit `zig-out/bin/net_u2.ini`
denselben Host enthält. Für Online außerhalb des LANs stattdessen die
öffentliche IP bzw. einen DNS-Namen eintragen und `PUBLIC_HOST` der Instanz
identisch setzen.

## 6. Durchgeführte Tests

### 6.1 Server nativ auf dem Laptop (`10.23.3.220`)

```bash
cd /home/reneil1337/Downloads/nfsu2/NFS-Online-Server
python3 nfs_online.py check
python3 nfs_online.py configure --public-host 10.23.3.220
printf 'TestDriver\nTest Driver\n<test-passwort>\n<test-passwort>\n' | \
  python3 nfs_online.py create-account
python3 nfs_online.py start --games u2 --daemon
python3 nfs_online.py status
ss -ltnp | grep -E ':(13505|20921|20922|20923)\b'
ss -lunp | grep -E ':2000[0-5]\b'
```

Beobachtet:

```text
Package: NFS Online Server 1.1.3
Python: 3.14.8
SQLite: 3.54.0
Public host: 127.0.0.1
Default games: u2, mw, carbon
Structure and configuration: OK

Public host set in config/server.toml: 10.23.3.220
Client configurations updated: 3
created: account=TestDriver persona=Test Driver
Started: U2 + shared EA Messenger.

Games: u2
Classic: running (PID 822933)
Carbon: stopped
Messenger: running in Classic (PID 822933)
```

Ports (Auszug):

```text
LISTEN 0.0.0.0:13505  python3
LISTEN 0.0.0.0:20921  python3
LISTEN 0.0.0.0:20922  python3
LISTEN 0.0.0.0:20923  python3
UNCONN 0.0.0.0:20000  python3
```

Log (`logs/classic.log`):

```text
[classic.build] INFO: Classic service V958 (toml-kick-live), U2=enabled, MW=disabled
[classic.protocols.runtime] INFO: Need for Speed Underground 2 bootstrap listening on 0.0.0.0:20921
[classic.protocols.runtime] INFO: Need for Speed Underground 2 lobby/auth listening on 0.0.0.0:20922, advertised as 10.23.3.220:20922
[classic.app] INFO: Shared EA Messenger endpoint (HTTP multiplexed) listening on 0.0.0.0:13505, advertised as 10.23.3.220:13505
[classic.app] INFO: Shared news/TOS/PREL endpoint (Messenger multiplexed) listening on 0.0.0.0:20923, advertised as 10.23.3.220:20923
[classic.app] INFO: Shared race relay listening on UDP 0.0.0.0 ports=20000,20001,20002,20003,20004,20005, advertised as 10.23.3.220 ports=20000,20001,20002,20003,20004,20005 channels=6
```

Verbindungstest von der LAN-Adresse:

```text
tcp/13505 OPEN
tcp/20921 OPEN
tcp/20922 OPEN
tcp/20923 OPEN
HTTP 20923 /news -> 200
HTTP 20923 /tos  -> 200
HTTP 20923 /     -> 404 (erwartet)
```

Danach mit `python3 nfs_online.py stop` gestoppt; keine `pkill`-Nutzung.

### 6.2 Docker auf dem Laptop

```bash
cd /home/reneil1337/coding/LANoMAT
export NFS_PUBLIC_HOST=10.23.3.220
docker compose -f docker/nfs-online/compose.yml up -d --build
docker compose -f docker/nfs-online/compose.yml ps
docker compose -f docker/nfs-online/compose.yml logs --tail=40
docker compose -f docker/nfs-online/compose.yml run --rm -T nfs-online check
```

Beobachtet:

```text
NAME                              STATUS                   PORTS
lanomat-nfs-online-nfs-online-1   Up (healthy)             0.0.0.0:13505->13505/tcp,
                                                           0.0.0.0:20921-20923->20921-20923/tcp,
                                                           0.0.0.0:20000-20005->20000-20005/udp
```

Container-Log:

```text
Public host set in config/server.toml: 10.23.3.220
Public host: 10.23.3.220
Effective public IPv4: 10.23.3.220
Detected local IPv4: 172.22.0.2
[U2] ... bootstrap listening on 0.0.0.0:20921
[U2] ... lobby/auth listening on 0.0.0.0:20922, advertised as 10.23.3.220:20922
[Messenger] ... listening on 0.0.0.0:13505, advertised as 10.23.3.220:13505
[Shared] ... news/TOS/PREL ... 0.0.0.0:20923, advertised as 10.23.3.220:20923
[Race] ... UDP ports=20000,20001,20002,20003,20004,20005, advertised as 10.23.3.220 ... channels=6
Started: U2 + shared EA Messenger.
Interactive console active. Type 'help'.
```

Damit ist bestätigt: Der Container bindet intern `0.0.0.0`, erkennt die
interne Docker-IP (`172.22.0.2`), kündigt aber korrekt die per
`PUBLIC_HOST` gesetzte LAN-Adresse an. `check` im Container meldet
`Python 3.12.16`, `Structure and configuration: OK`. Ein Account ließ sich
im Container über `create-account` anlegen und mit `account list` lesen;
danach wurden Volumes und Testkonto entfernt und der Dienst sauber neu
gestartet.

Host-seitig waren die Ports `13505`, `20921`, `20922`, `20923` (TCP) und
`20000-20005` (UDP) gebunden und von `10.23.3.220` aus erreichbar.

### 6.3 Upstream-Tests

```bash
cd /home/reneil1337/Downloads/nfsu2/NFS-Online-Server
python3 -m unittest discover -s tests -q
# Ran 30 tests in 10.339s — OK
```

## 7. Was noch fehlt

| Fehlt | Warum wichtig |
| --- | --- |
| Windows-Client mit echtem NFSU2 + ASI Loader | Ohne Spiel lässt sich der Bootstrap-/Login-/Lobby-Flow nicht end-to-end testen. Der Laptop hat kein NFSU2; getestet sind nur TCP-Annahme, HTTP-Endpunkte und Logs. |
| Logintest mit einem echten Konto im Spiel | `create-account` und `account list` sind geprüft, der Auth-Handshake des Spiels nicht. |
| UDP-Race-Test mit ≥ 2 Clients | Ports sind gebunden, aber Paketfluss, NAT und Channel-Zuordnung wurden nicht mit echten Fahrzeugen geprüft. |
| Firewall-Freigaben auf `10.23.0.12` | Auf dem Host müssen TCP 13505/20921–20923 und UDP 20000–20005 offen sein; zusätzlich Docker-`DOCKER-USER`-Regeln prüfen. |
| Deployment auf `10.23.0.12` | SSH-Zugang war in dieser Session nur passwortbasiert, kein Key-Login; der Container lief daher auf dem Laptop. Der Compose-Start auf dem Event-Server ist der nächste Schritt. |
| DNS / Split-DNS | Für das Event reicht die IP. Für Online braucht es einen Namen, der intern und extern auf die richtige Instanz zeigt. |
| Backup-/Restore-Probe | Backup-Befehl ist dokumentiert, aber nicht mit einem Restore getestet. |

## 8. Kurzcheckliste für das Event

1. Auf `10.23.0.12`: `ss -ltnup` prüfen, dass 13505, 20921–20923 und
   20000–20005 frei sind.
2. `NFS_ONLINE_SRC=/opt/NFS-Online-Server NFS_PUBLIC_HOST=10.23.0.12 \
   docker compose -f docker/nfs-online/compose.yml up -d --build`.
3. `ps` muss `healthy` zeigen; `logs` muss die fünf „listening“-Zeilen mit
   `advertised as 10.23.0.12` enthalten.
4. Von einem zweiten LAN-Host TCP-Ports testen und `/news` mit HTTP 200
   abrufen.
5. Konten anlegen (`create-account`), Client mit `host = 10.23.0.12`
   ausliefern.
6. Ersten Client starten, einloggen, Lobby öffnen; erst dann UDP-Race mit
   zwei Fahrzeugen testen.
7. Nach dem Event: `down` (ohne `-v`), Daten-Volume sichern.

## 9. Lizenz und Grenzen

Der Server und die Clients stehen unter **AGPL-3.0-or-later**
(Client `src/carbon/virus.zig`: LGPL-3.0-only). Es werden keine
Spieldateien mitgeliefert. Need for Speed und verwandte Namen sind Marken
von Electronic Arts; das Projekt ist inoffiziell. Bei einer öffentlichen
Instanz die AGPL-Pflichten (Quellcode-Angebot) beachten.
