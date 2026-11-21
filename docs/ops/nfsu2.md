# NFS Online Server (NFS:U2) — Hosting-Test Homelab

Stand: **2026-11-21**. Getestet wurde der Community-Server
NFS-Online-Server 1.1.3 (Python, `--games u2`; Quelle
`/home/reneil1337/Downloads/nfsu2/NFS-Online-Server`) als Container auf dem
Homelab. **Kein Commit, kein dauerhafter Dienst**: der
Test-Container wurde nach den Messungen wieder entfernt, die Ports sind frei.
Deploy-Artefakte liegen nur auf dem Host (`/mnt/user/appdata/nfs-online`,
`/mnt/user/appdata/nfs-online-deploy`), nicht im Repo.

## 0. Ergebnis in drei Sätzen

1. **Dauerbetrieb auf dem Homelab: ja.** Der Server läuft unverändert in
   `python:3.12-slim` mit `network_mode: host`, heilt sich per Healthcheck und
   stoppt per `docker stop` sauber (SIGTERM → Console-`quit`, Exit 0).
2. **Erreichbar für Event-Clients: nur über einen expliziten Weg.** Im LAN
   `192.168.68.0/22` und im Tailnet (`100.83.27.66`) ist alles offen; aus dem
   Internet ist **aktuell kein** Port offen (gemessen von `10.23.3.220`). Es
   fehlen DynDNS + Portfreigaben in der FRITZ!Box **oder** eine
   Pangolin-Ressource. Der NAT ist freigabefähig (kein CGNAT).
3. **Für Racing im Event ist der Homelab nur zweite Wahl.** Das Renn-UDP wird
   serverseitig relayed (`RACE_RELAY`, virtuelle IPs), also bestimmt die
   Client↔Server-Strecke die Renn-Latenz. Gemessen: 55–150 ms RTT vom Event-LAN
   zum Homelab — Lobby/News/Chat ok, Racing grenzwertig. Fürs Event daher
   besser eine Instanz **im** Event-LAN.

## 1. Getesteter Host

| Eigenschaft | Wert |
| --- | --- |
| Host | Unraid **7.3.4** (`Server`), Kernel 6.18.58, i9-13900H |
| LAN | `192.168.68.57/22`, Gateway `192.168.68.1` (FRITZ!Box **5690 Pro**) |
| Tailnet | `100.83.27.66` (`cloud`, Direktverbindung, kein DERP) |
| Öffentlich | `217.224.123.108` (Deutsche Telekom, dynamisch, `pd9e07b6c.dip0.t-ipconnect.de`) |
| NAT | Endpunkt-unabhängiges Mapping (`tailscale netcheck`: `MappingVariesByDestIP: false`), **kein CGNAT** → Portfreigaben funktionieren |
| Python | **nicht vorhanden** (`python3: command not found`) → Container ist Pflicht |
| Docker | Engine 29.8.2, Compose v2.40.3, Storage `btrfs` |
| Host-Firewall | keine: `iptables` alle Policies `ACCEPT`, kein `ufw`/`nft`/`firewalld` |
| Freier Platz | `649G` auf `/mnt/user` |

Freie Ports vor dem Test geprüft: `20921`, `20922`, `20923`, `13505` (TCP) und
`20000` (UDP) waren alle **frei**; nach dem Test wieder frei.

## 2. Testlauf — exakte Befehle

Kopie (ohne `.git`, `runtime`, `logs`), Deploy-Verzeichnis, Konfiguration:

```bash
rsync -a --delete --exclude '.git' --exclude 'runtime' --exclude 'logs' \
  --exclude '__pycache__' -e 'ssh -p 2222' \
  NFS-Online-Server/ root@100.83.27.66:/mnt/user/appdata/nfs-online/

ssh -p 2222 root@100.83.27.66 'mkdir -p /mnt/user/appdata/nfs-online-deploy'
# entrypoint.py + compose.yml dorthin kopieren (siehe Abschnitt 7)

# PUBLIC_HOST auf die im Test erreichbare Adresse setzen (Tailnet):
docker run --rm -v /mnt/user/appdata/nfs-online:/app -w /app python:3.12-slim \
  python nfs_online.py configure --public-host 100.83.27.66
# -> "Public host set in config/server.toml: 100.83.27.66" / "Client configurations updated: 3"

docker run --rm -v /mnt/user/appdata/nfs-online:/app -w /app python:3.12-slim \
  python nfs_online.py check
```

Start/Stop über Compose:

```bash
cd /mnt/user/appdata/nfs-online-deploy
docker compose up -d
docker compose ps                       # Up (healthy) nach ~20-30 s
docker compose logs -f nfs-online
docker compose down                     # SIGTERM -> "Graceful shutdown..." -> Exit 0 in ~2 s
```

Wichtig für `docker run` von Hand: `-d` **ohne** `-i` beendet den Server sofort,
weil die Konsole EOF auf stdin bekommt (`Graceful shutdown...` direkt nach dem
Start). Immer `-i` (bzw. `stdin_open: true` im Compose) verwenden.

### Test-Log (gekürzt, echter Lauf)

```text
Public host: 100.83.27.66
Effective public IPv4: 100.83.27.66
Detected local IPv4: 100.83.27.66
[Shared] [classic.build] INFO: Classic service V958 (toml-kick-live), U2=enabled, MW=disabled
[Shared] [classic.app] INFO: Shared account SQLite enabled: db=/app/data/accounts.sqlite3 files=/app/data/users lease=120.0s
[U2] [classic.protocols.runtime] INFO: Need for Speed Underground 2 bootstrap listening on 0.0.0.0:20921
[U2] [classic.protocols.runtime] INFO: Need for Speed Underground 2 lobby/auth listening on 0.0.0.0:20922, advertised as 100.83.27.66:20922
[Messenger] [classic.app] INFO: Shared EA Messenger endpoint (HTTP multiplexed) listening on 0.0.0.0:13505, advertised as 100.83.27.66:13505
[Shared] [classic.app] INFO: Shared news/TOS/PREL endpoint (Messenger multiplexed) listening on 0.0.0.0:20923, advertised as 100.83.27.66:20923
[Race] [classic.app] INFO: Shared race relay listening on UDP 0.0.0.0 ports=20000,...,20005, advertised as 100.83.27.66 ports=20000,...,20005 channels=6
Started: U2 + shared EA Messenger.
Interactive console active. Type 'help'.
[U2] underground2 bootstrap client connected: peer=100.82.3.42:41230      <- Client aus dem Event-LAN (10.23.3.220)
[U2] underground2 lobby client connected: peer=100.82.3.42:54050 challenge=no
[Race] EA race UDP unresolved raw channel: channel=0 peer=100.82.3.42:35428 w0=0x5253464e payload=8
nfs> Graceful shutdown...
Stopping Classic (PID 8)...
[Shared] classic-account-policy stopped at event_id=0
```

`unresolved raw channel` ist die erwartete Antwort auf einen handgesendeten
UDP-Probe (`payload=8`, Magic `NFR S` little-endian) — der Relay nimmt UDP an.
Ein vollständiger Renn-Handshake wurde **nicht** getestet (dazu braucht es den
U2-Client mit `net_u2.asi`).

Beobachtete Startzeit bis „Started“: ~2 s; Healthcheck `healthy` nach ~25 s.

## 3. Portstatus

Bind im Container: alle `0.0.0.0` (host network), `13506` nur `127.0.0.1`
(Messenger-IPC, bleibt intern). Erreichbarkeit geprüft von `10.23.3.220`
(Event-LAN) über Tailnet:

| Port | Proto | Dienst | von Event-Client (Tailnet) | aus dem Internet |
| ---: | --- | --- | --- | --- |
| 20921 | TCP | U2 Bootstrap | **offen** | **zu** (keine Freigabe) |
| 20922 | TCP | U2 Lobby/Auth | **offen** | **zu** |
| 20923 | TCP | News/TOS/PREL (HTTP) | **offen** (`/news` = 200) | **zu** |
| 13505 | TCP | EA Messenger (HTTP-multiplex) | **offen** | **zu** |
| 20000–20005 | UDP | Race-Relay (6 Kanäle) | **offen** (Datagramm geloggt) | **zu** |
| 13506 | TCP | Messenger-IPC | loopback-only, korrekt | — |
| 8081 | TCP | DLC-Store | optional, `127.0.0.1` | — |

Latenz-Messungen (Client `10.23.3.220` → Homelab, 3 Läufe):

| Weg | Messwert |
| --- | --- |
| Tailnet TCP-Connect `:20923` | 55–58 ms (ein Lauf 211 ms), HTTP total 112–117 ms |
| ICMP auf `217.224.123.108` | 137–166 ms (Telekom-Peering, schwankend) |
| zum Vergleich: Hetzner `46.225.28.53` | 118–138 ms |

## 4. Firewall / NAT — offene Punkte

1. **Unraid blockt nichts** (Policies `ACCEPT`), aber ein Docker-Container mit
   `network_mode: host` published auch nichts über `docker-proxy` — die
   Erreichbarkeit hängt allein am Router.
2. **FRITZ!Box 5690 Pro braucht Portfreigaben** (aktuell keine für diesen
   Server; von außen sind `22/443/20921/20923/13505` alle gefiltert):
   - TCP `20921`, `20922`, `20923`, `13505` → `192.168.68.57`
   - UDP `20000`–`20005` (alle sechs Relay-Kanäle, nicht nur `20000`)
   - besser mit „Portfreigabe aktivieren“ je Dienst, nicht als Exposed Host.
3. **DynDNS fehlt.** `217.224.123.108` ist dynamisch; `PUBLIC_HOST` muss ein
   Name sein (MyFRITZ!/DynDNS, z. B. `nfs.reneil.org` → A-Record).
4. **TR-064 nur mit Auth** (`401 Unauthorized` auf `:49000`), d. h. Freigaben
   manuell im UI setzen oder mit FritzBox-Zugang automatisieren. „Hairpin“ von
   intern über die öffentliche IP ging im Test nicht (`no hairpin/forward`) —
   LAN-Clients nutzen die LAN-IP, das erledigt `LOCAL_ADVERTISE_HOST = "auto"`
   (Peers aus RFC1918 bekommen automatisch die lokale Adresse, Public-Peers
   `PUBLIC_HOST`; siehe `server/classic/app.py`, `_advertised`).
5. **Alternative ohne Portfreigabe:** Pangolin/Newt läuft auf dem Host
   (`Newt`-Container, `PANGOLIN_ENDPOINT=https://tunnel.reneil.org`,
   VPS `195.201.113.44`). Für die Spielports wären dort **Raw-TCP-Ressourcen
   pro Port** plus UDP nötig — ungetestet, weil Dashboard-Zugang/API-Key fehlt.
6. **IPv6:** am Host nur Link-Local auf `br0`, kein globales Präfix → kein
   AAAA-Weg; alles läuft über IPv4.

## 5. Welche Adresse gehört in `net_u2.ini`?

Die Adresse ist **pro Instanz** zu setzen und muss exakt die sein, die der
Client erreicht (der Server advertised `PUBLIC_HOST:20922`):

| Szenario | `host` in `net_u2.ini` | Voraussetzung |
| --- | --- | --- |
| Dauerbetrieb öffentlich (empfohlen) | `nfs.reneil.org` (Name, nicht IP) | DynDNS + Portfreigaben aus Abschnitt 4 |
| Test/Orga im Tailnet (heute getestet) | `100.83.27.66` | alle Clients im Tailnet |
| Event, Homelab als Host | dasselbe öffentliche Name | Event-Uplink erlaubt ausgehend TCP 20921-20923/13505 und UDP 20000-20005 |
| Event, lokal gehostet (empfohlen) | `10.23.0.12` | Server-Instanz läuft dort (Docker/Python, noch unbestätigt) |

Beispiel `clients/underground2/net_u2.ini`:

```ini
[network]
enabled = true
host = nfs.reneil.org
bootstrap_port = 20921
lobby_port = 20922
control_port = 20923
control_alias_port = 13505
race_port = 20000

[lan]
enabled = true
host = nfs.reneil.org
port = 20922
inject_server = true
```

Die Ports bleiben bei allen Varianten gleich; nur `host` (in beiden Blöcken)
ändert sich. `python nfs_online.py configure --public-host <host>` schreibt
`config/server.toml` **und** die drei Client-INIs konsistent.

## 6. Alternativen im Vergleich

| Host | Erreichbarkeit | Gemessene Latenz vom Event-LAN | Betrieb | Urteil |
| --- | --- | --- | --- | --- |
| **Homelab `192.168.68.57` / `100.83.27.66`** | Tailnet: alles offen. Internet: noch nichts offen, NAT freigabefähig | 55–166 ms (schwankend) | Docker 29.8, kein Python, Unraid-Dauerläufer, Backups vorhanden | **gut für Dauerbetrieb/Lobby**, für Event-Racing nur ok |
| **LANoMAT-App-Server `46.225.28.53`** (Hetzner NBG) | öffentlich, SSH 22 offen; läuft Traefik + Compose-Stack | 118–138 ms | Docker vorhanden, aber produktiver App-Host; SSH-Key vom Laptop fehlt (`Permission denied`) | möglich, aber Ports/Compose des Live-Stacks anfassen = Risiko |
| **Event-Filehost `10.23.0.12`** | LAN, `22/80/443/8443` offen (nginx), 2092x frei | LAN (~1 ms, WLAN-Jitter gemessen) | SSH-Key fehlt; Docker/Python unbestätigt | **beste Wahl fürs Event**, wenn Docker/Python drauf sind |
| **Laptop `10.23.3.220`** | LAN + Tailnet, WLAN | LAN | Dev-Rechner, nicht dauerhaft | nur Fallback/Test |

Fazit: **zwei Instanzen, eine Config.** Homelab = permanenter, öffentlich
benannter Server (Lobby, Accounts, News, Stats). Event = dieselbe Compose-Datei
auf einem LAN-Host (`10.23.0.12`), `PUBLIC_HOST` dort auf `10.23.0.12`, weil
das Renn-UDP über den Server läuft. Die Account-DB lässt sich zwischen beiden
kopieren (`data/accounts.sqlite3` + `data/users/`), solange nur eine Instanz
gleichzeitig schreibt.

## 7. Compose für Homelab/Prod

`/mnt/user/appdata/nfs-online-deploy/compose.yml` (auf dem Host, nicht im Repo):

```yaml
services:
  nfs-online:
    image: python:3.12-slim
    container_name: nfs-online
    network_mode: host            # bindet 20921-20923/tcp, 13505/tcp, 20000-20005/udp direkt
    restart: unless-stopped
    stop_grace_period: 30s        # Entrypoint macht aus SIGTERM das Console-Kommando "quit"
    stdin_open: true
    environment:
      TZ: Europe/Berlin
      NFS_GAMES: u2               # u2 | mw | carbon | u2,mw,...
    working_dir: /app
    command: ["python", "/entrypoint.py"]
    volumes:
      - /mnt/user/appdata/nfs-online:/app               # Code + config/ + data/ + logs/
      - /mnt/user/appdata/nfs-online-deploy/entrypoint.py:/entrypoint.py:ro
    healthcheck:
      test:
        - CMD
        - python
        - -c
        - |
          import sys, urllib.request
          try:
              ok = urllib.request.urlopen("http://127.0.0.1:20923/news", timeout=3).status == 200
          except Exception:
              ok = False
          sys.exit(0 if ok else 1)
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 20s
    logging:
      driver: json-file
      options:
        max-size: "20m"
        max-file: "3"
```

`/mnt/user/appdata/nfs-online-deploy/entrypoint.py` — nötig, weil der Server
`SIGTERM` nicht selbst behandelt (ohne Wrapper würde `docker stop` nach
`stop_grace_period` hart killen; `nfs_online.py stop` im Container beendet nur
die Spielprozesse und lässt PID 1 stehen):

```python
#!/usr/bin/env python3
"""PID 1 wrapper: turns SIGTERM/SIGINT into the server console command "quit"."""
import os, signal, subprocess, sys

games = os.environ.get("NFS_GAMES", "u2")
proc = subprocess.Popen(
    [sys.executable, "-u", "nfs_online.py", "start", "--games", games],
    cwd="/app", stdin=subprocess.PIPE,
)

def _quit(signum, _frame):
    try:
        proc.stdin.write(b"quit\n")
        proc.stdin.flush()
    except (BrokenPipeError, OSError):
        pass

signal.signal(signal.SIGTERM, _quit)
signal.signal(signal.SIGINT, _quit)
sys.exit(proc.wait())
```

Volumes/Persistenz:

| Pfad | Inhalt | Backup |
| --- | --- | --- |
| `.../nfs-online:/app` | Code, `config/server.toml`, `data/`, `logs/` | ja (`config/` + `data/`) |
| `data/accounts.sqlite3`, `data/users/` | Accounts, Personas, Sessions | ja, nur bei gestopptem Dienst kopieren |
| `data/carbon/`, `data/classic/` | DLC, Stats | ja |
| `data/server-state.json` | IPC-Secret, Schema | ja (kein Secret im Repo!) |
| `runtime/`, `logs/` | flüchtig | nein |

Hinweise:

- Der Container läuft als **root** und legt `data/server-state.json`,
  `logs/`, `runtime/` als `root:root` an; die übrigen Dateien bleiben
  `1000:1000`. Für Unraid-Backups ist das unkritisch, für Hand-Edits ggf.
  `chown -R 1000:1000`.
- `python:3.12-slim` ist ein Tag, kein Digest — für Reproduzierbarkeit auf
  `python:3.12-slim@sha256:…` pinnen (getestet: Digest
  `sha256:5e2bdb1b0d542d5039cffb8181c65930093949194a65c365dfb1b0fe13a4fa5b`).
- `PUBLIC_HOST` in `config/server.toml` steht im Test auf `100.83.27.66`; vor
  dem Dauerbetrieb auf den öffentlichen Namen setzen
  (`configure --public-host nfs.reneil.org`).

## 8. Betrieb

```bash
cd /mnt/user/appdata/nfs-online-deploy
docker compose up -d                       # Start
docker compose ps                          # Up (healthy)
docker compose logs --tail 100 nfs-online
docker compose restart nfs-online          # SIGTERM -> quit -> Start
docker compose down                        # sauberer Stopp (Exit 0)
docker exec nfs-online python nfs_online.py status
docker exec nfs-online python nfs_online.py create-account   # interaktiv, -it
```

Update des Codes: `rsync` aus dem Checkout nach `/mnt/user/appdata/nfs-online`
(wie oben), danach `docker compose restart nfs-online`. `--delete` nicht ohne
`--exclude 'data' 'config' 'logs' 'runtime'` verwenden, sonst gehen Accounts
und Konfiguration verloren.

## 9. Offene Punkte

- [ ] FRITZ!Box: TCP 20921/20922/20923/13505 + UDP 20000–20005 → `192.168.68.57` freigeben.
- [ ] DynDNS-Eintrag (z. B. `nfs.reneil.org`) → `217.224.123.108`, dann `configure --public-host` setzen.
- [ ] Freigaben von **außerhalb** gegenprüfen (nicht aus dem Heimnetz, kein Hairpin).
- [ ] Event-Uplink: ausgehend TCP 20921-20923/13505 + UDP 20000-20005 erlaubt? (Firewall-Forward dropped nur private Quellen, Ports sind frei — im Betrieb bestätigen.)
- [ ] Entscheidung Homelab-Dauerbetrieb: eigener Compose-Stack + `restart: unless-stopped` (dann bleibt er auch nach Reboot an).
- [ ] Fürs Event: Docker/Python auf `10.23.0.12` prüfen und dieselbe Compose-Datei dort starten (`PUBLIC_HOST=10.23.0.12`).
- [ ] Voller Renn-Handshake mit echtem U2-Client (`net_u2.asi`) gegen den Homelab-Server.
- [ ] Pangolin-Raw-TCP-Ressourcen als portfreigabefreie Alternative bewerten.
- [ ] Account-DB: aktuell **1 Account** in der kopierten `accounts.sqlite3` (aus dem lokalen Checkout übernommen), keine Secrets im Log.
