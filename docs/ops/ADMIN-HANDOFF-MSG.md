# Admin message — LANoMAT first lineup / NFSU2 (copy/paste)

**Stand:** `main` = `74a933be` · Live = `m12-791-g7db267fb` (`/launcher` = 200) · 2026-11-21

---

**LANoMAT ist live: https://lan.raute1.org**
Launcher: `/launcher` · Spiele: `/games` · Server: `/servers`

**First Lineup**
- **UT2004** — `10.23.0.40:7777` (Raute1 LAN, query `7778`), install/start via launcher.
- **NFSU2 (nativ)** — unser NFS-Online-Server: **`10.23.3.220:20922`** (Test) → Ziel **`10.23.0.12:20922`**; TCP `13505, 20921–20923`, UDP `20000–20005`; Client `net_u2.asi` + `net_u2.ini`, `dinput8.dll`.
- **NFSU2 (Browser)** — https://nfsu2.xyz/ (Community-Port, **nicht unser Server**); ein Server, Nickname, Join/Leave.

**Pakete**
- `nfsu2-portable.zip` — **2.602.084.719 B**, SHA-256 `0d7028987ba4fdb5dfbd92e3c5a77fd2d6e98992b85b14ef0268fba040767219` (flach, `speed.exe` + `SPEED2.EXE` v1.2, `net_u2.asi`, `net_u2.ini`, `dinput8.dll`, `start-nfsu2-linux.sh`). Unter Proton bis Menü getestet.
- `ut2004-base.zip` — 2,5 GB flach; UT2004-Install lokal getestet (v3374, Hauptmenü).
- Beide Uploads stehen noch aus (Filehost nur im WLAN **LANoMAT**).

**Deploy / Admin-Schritte**
```bash
cd /opt/lanomat
git pull --ff-only                 # 74a933be
./scripts/deploy/deploy.sh

NFS_SERVER_HOST=10.23.0.12 php artisan db:seed --class=LauncherLineupSeeder
php artisan lanomat:recipes:sync --only=unreal-tournament-2004 --json
php artisan lanomat:recipes:sync --only=need-for-speed-underground-2 --json
php artisan lanomat:recipes:sync --only=need-for-speed-underground-2-browser --json
```
NFSU2 native recipe im Admin auf **published** stellen und die LAN-Datei `nfsu2-portable.zip` pinnen; Browser-Recipe ist published.

**NFS-Server**
```bash
NFS_ONLINE_SRC=/srv/nfs-online NFS_PUBLIC_HOST=10.23.0.12 \
  docker compose -f docker/nfs-online/compose.yml up -d --build
```
Firewall: TCP `13505, 20921:20923`, UDP `20000:20005`. Danach `nfs_online.py status` und Login/Lobby mit zwei Clients testen.

**Uploads**
```bash
# WLAN LANoMAT, nicht qc
scripts/ops/ut2004/upload-file.sh /home/reneil1337/Downloads/nfsu2/nfsu2-portable.zip nfsu2-portable.zip 64
scripts/ops/ut2004/upload-file.sh /home/reneil1337/Downloads/ut2004-base.zip ut2004-base.zip 64
```
Danach Rezept-Sync + Plan prüfen (`need-for-speed-underground-2` / `os: windows|linux`).

**Discord**
```bash
DISCORD_WEBHOOK_URL='…' scripts/ops/discord-announce.sh \
  --file docs/ops/announcements/2026-11-first-lineup.md
```
Bot: `DISCORD_AGENT_ENABLED=true`, `LANOMAT_URL=https://lan.raute1.org`, `LANOMAT_TOKEN=…`, `PI_MODEL=…`; pi/MCP liefert 24 Tools (nach diesem Deploy).

**Jetzt testen**
1. Launcher: UT2004 starten, `Join Game → LAN` → `10.23.0.40:7777`.
2. Browser: `https://nfsu2.xyz/` in Chrome/Brave, Profil anlegen, World wählen, joinen/leaven.
3. Nativ: `nfsu2-portable.zip` entpacken, `start-nfsu2-linux.sh` (Proton) bzw. `SPEED2.EXE`; Lobby `10.23.3.220:20922` (Test).
4. Launcher-Serverbrowser: nach Seed erscheint `NFSU2 (NFS-Online, unser Server)`; UT2004 bleibt.

**Offen:** NFSU2-Recipe live **Draft 25** (`version: null`/`launch_targets: []`, `missing: []`; Datei 20 matcht); Ein-Client-Login/Lobby ist verifiziert, Race-UDP mit zwei Clients offen; NFSU2-Server-Zeile noch nicht geseedet; Filehost-Uploads; Ein-Client-Login/Lobby **verifiziert** (bootstrap → ToS → auth/pers → gsea); Zwei-Client-/Race-UDP und Split-Screen-2P noch offen; Discord-Webhook. Details: `docs/ops/ADMIN-HANDOFF.md`.
