# Launcher ↔ Agent ↔ Web: Integration der ersten Lineup

Stand: **2026-11-21**. Beschreibt den gemeinsamen Vertrag von Launcher,
`/api/v1/recipes`, Web `/games`, Home-Chat und MCP. Keine Secrets, keine
Live-Änderung.

## 1. Eine Quelle für die Auswahl

`config/launcher.php` → `lineup` (Env `LAUNCHER_LINEUP`) ist die einzige
Allowlist. Der Parser sitzt in
`App\Modules\GameRecipes\Support\LauncherLineup`:

- leer → keine Filterung (Backcompat)
- sonst → nur diese Slugs, lowercased, dedupliziert

Angewendet wird sie in:

| Oberfläche | Stelle |
| --- | --- |
| Launcher/API | `RecipeController::index` |
| Web `/games` + `/games/{slug}` | `GamePageController::publishedRecipes()` |
| Home-Chat | `HomeChatData::games()` |
| MCP `recipes_list` | `RecipesListTool::run()` |

Direkte Admin-/Sync-Pfade und `recipe_get` bleiben unverändert. Die Rezepte
liegen weiter in der DB.

## 2. Katalogvertrag

`GET /api/v1/recipes` liefert pro Eintrag:

```json
{
  "slug": "need-for-speed-underground-2-browser",
  "title": "Need for Speed: Underground 2 (Browser)",
  "installable": false,
  "launcher_supported": true,
  "play_url": "https://nfsu2.xyz/",
  "platforms": ["windows", "linux", "macos"]
}
```

Bedeutung:

- `installable`: Rezept hat Requirements, Steps und ein Launch-Target → es
  gibt einen Installationsplan.
- `launcher_supported`: dieser Client kann damit etwas anfangen. UT2004 und
  das generische NFSU2-Rezept kommen aus der Server-Allowlist; jede Browser-
  Recipe ist über ihr `play_url` unterstützt.
- `play_url`: nur bei Browser-Rezepten. Der Launcher öffnet sie mit
  `open_url`; es gibt keinen Plan, keinen Download und kein `verify_install`.

`RecipeSummary` (Rust) hat dafür ein optionales `play_url`. Serde ignoriert
unbekannte Felder, ein älterer Launcher bleibt kompatibel; `play_url` fehlt
dort einfach.

## 3. Launcher-Verhalten

`launcher_supports(recipe)` ist wahr, wenn:

1. der Slug der dedizierte UT2004-Pfad ist, **oder**
2. der Server `launcher_supported: true` sendet, **oder**
3. ein nicht-leeres `play_url` vorhanden ist.

Die Library-Karte:

- Browser-Recipe (`installable === false` + `play_url`): ein primärer Button
  **Spielen**, der `play_url` über `open_url` öffnet. Kein „Bald im
  Launcher“, kein Install-/Verify-Aufruf.
- Installierbare Recipe: **Installieren** bzw. nach dem Ledger **Starten**.
- Alles andere: **Bald im Launcher** + Webseite.

Der `open_url`-Allowlist des Shells erlaubt `https://`, `http://`,
`steam://` und `ut2004://`; `https://nfsu2.xyz/` fällt darunter.

## 4. Web und MCP

Web `/games` bekommt nur die Lineup. Die Browser-Karte zeigt:

- **Spielen** → `play_url` in einem neuen Tab
- eine Zeile aus `metadata.server.players`:
  `Ein Server · Nickname · Join/Leave`

`/games/{slug}` listet genau einen Server aus `metadata.server`, wenn kein
echter `ServerLink` für das Spiel existiert:

| Feld | Wert |
| --- | --- |
| Titel | `NFSU2 (Browser)` |
| Adresse/Port | `nfsu2.xyz:443` |
| Connect | `https://nfsu2.xyz/` (HTTPS, `connectIsLink: true`) |

Der MCP-Katalog (`recipes_list`) sieht dieselbe Lineup. `recipe_get` und
`recipe_install_plan` bleiben für direkte Slugs erreichbar; der Plan eines
Browser-Rezepts ist leer (keine Requirements).

## 5. Serverbrowser

- UT2004: bestehender Server `10.23.0.40:7777`, unverändert.
- NFSU2: ein manueller/ext. Dauerserver, siehe
  `docs/ops/launcher-lineup.md` (`LauncherLineupSeeder`): `NFSU2 (Browser)`,
  `nfsu2.xyz:443`, Connect `https://nfsu2.xyz/`.

Ein echter `ServerLink` gewinnt gegen die Rezept-Ableitung; die Spielseite
zeigt den Server dann nicht doppelt.

## 6. Änderungen an der Lineup

1. Slug in `LAUNCHER_LINEUP` aufnehmen (kommagetrennt).
2. Config-Cache leeren.
3. Sicherstellen, dass das Rezept publiziert und für den Ziel-Nutzer sichtbar
   ist.
4. Bei einem Browser-Rezept `play_url` + `metadata.server` setzen; bei einem
   installierbaren Rezept Requirements/Steps/Launch-Target pflegen.
5. Tests aus `docs/ops/launcher-lineup.md` laufen lassen.

Eine leere Variable schaltet die Kuratierung ab; dann erscheint wieder der
ganze sichtbare Katalog (z. B. für eine offene Test-Instanz).

## 7. MCP-Tools für Launcher und pi-Agent

Der native Launcher spricht `/api/v1/*` (Rezepte, Pläne, Downloads,
Heartbeats). Der **pi-Agent** spricht `POST /mcp` und braucht dort den
vollständigen Tool-Katalog, nicht nur Seite 1.

### 7.1 Transport und Token

| Punkt | Wert |
| --- | --- |
| Endpoint | `POST https://<LAN_DOMAIN>/mcp` (Streamable HTTP) |
| Accept | `application/json, text/event-stream` |
| Auth | `Authorization: Bearer lmcp_…`, nur Header (kein Query, keine Session) |
| Token-Quelle | Device-Flow (`lanomat-agent login` → `/mcp/connect`) oder `php artisan lanomat:mcp-token <user>` |
| Token-Dateien | Launcher: `~/.config/lanomat-launcher/config.json` (0600); Agent: `~/.config/lanomat/agent.json`; pi: `~/.pi/agent/mcp.json` mit `Bearer ${LANOMAT_TOKEN}` |
| Rechte | keine Scopes; der Token handelt als sein User über die normalen Policies |
| Laufzeit | gleitend 90 Tage (`MCP_TOKEN_TTL_DAYS`), sofort widerrufbar |

`lanomat-agent ask` exportiert `LANOMAT_TOKEN` für die pi-Session;
`lanomat-agent mcp install` schreibt den pi-Eintrag und zeigt die
`export`-Zeile einmal an.

### 7.2 Tool-Matrix (Full-Profil, 24 Tools)

| Fähigkeit | Tool(s) | Launcher/pi braucht es für | Modul |
| --- | --- | --- | --- |
| Account | `lanomat_me` | Erster Call: Identität, Rolle, aktive Module | core |
| Events | `events_list`, `event_get` | Aktives Event, Zeitplan, eigene Anmeldung | core |
| Beitritt Event | `event_register` | Anmeldung des Token-Inhabers (Kapazität/Fenster prüft der Server) | core |
| Teams/Turniere | `teams_list`, `tournament_list`, `tournament_bracket`, `tournament_checkin`, `match_result` | Team-Kontext, Brackets, Check-in, Ergebnis melden | core |
| Spiele-Katalog | `games_search` | Spielname → Katalog-/Turnier-Slug | core |
| Dateien | `files_list`, `file_download_url` | Freigegebene LAN-Dateien finden, Bearer-Download-URL holen | Files |
| Rezepte/Install | `recipes_list`, `recipe_get`, `recipe_install_plan`, `recipe_avatars`, `recipe_contribute` | „install <Spiel> für mich“: Rezept → Dateien → Plan → Avatare; Beitrag (Draft) | GameRecipes |
| Server/Join | `servers_list` | Dauerserver oder Event-Server mit Connect-Info und Status | GameServers |
| Zeitplan | `schedule_list` | „Wann/wo ist …?“ | Schedule |
| Voting | `polls_list`, `poll_vote` | Umfragen lesen, eigene Stimme abgeben | Voting |
| Catering | `catering_menu` | Speisekarte und offene/geplante Bestellungen | Catering |
| LFG | `lfg_list`, `lfg_create` | Gruppe finden oder posten | Lfg |
| Browser-Play | `recipe_get` (`play_url`, `launch_target(s)`) | Browser-Spiel ohne Installation öffnen; der Launcher liest `play_url` zusätzlich aus `/api/v1/recipes/{slug}` | GameRecipes |

**Pflicht für den Installations-Flow des pi-Agenten:** `lanomat_me`,
`recipes_list`, `recipe_get`, `recipe_install_plan`, `recipe_avatars`; dazu
`files_list`/`file_download_url`, wenn eine Datei nicht schon im Plan steht.
**Join-Aktionen:** `event_register`, `tournament_checkin`, `lfg_create`.
**Kontext nach dem Install:** `servers_list`, `recipe_get.play_url`,
`events_list`/`event_get`.

### 7.3 Pagination: warum nur 15 Tools sichtbar waren

`tools/list` ist cursor-paginiert. Die alte Live-Version `m12-768` liefert
ohne Parameter 15 Tools und `nextCursor` mit Offset 15; die zweite Seite hat
die restlichen 9 inklusive aller fünf Recipe-Tools.

Zwei Client-Ursachen für „Recipe-Tools unsichtbar“:

1. **Doctor:** `clients/agent/src/doctor.ts` rief `client.listTools()` genau
   einmal auf. Der SDK paginiert nicht automatisch → „15 MCP tool(s)
   available“. Der Doctor fragt jetzt `per_page=100` und folgt jedem
   `nextCursor` (`listAllToolNames`), meldet also 24.
2. **Cursor:** Ein Cursor ohne Base64-Padding (`==`) oder in base64url wurde
   vom Vendor-Paginator still als Offset 0 gelesen und zeigte Seite 1 erneut.
   `App\Modules\Mcp\Server\Methods\ListTools` normalisiert Padding/base64url,
   akzeptiert `per_page` als Zahl oder Zahl-String, liefert auf der letzten
   Seite `nextCursor: null` und beantwortet einen echten Müll-Cursor mit
   JSON-RPC `-32602` statt Seite 1.

Zusätzlich setzt `LanomatServer::$defaultPaginationLength = 50`: Damit passt
der heutige 24-Tool-Katalog in **eine** Seite, auch für Clients ohne
Cursor-Schleife. Der Doctor paginiert trotzdem weiter, damit ein später
größerer Katalog nicht wieder abgeschnitten wird.

| Request | HEAD (dieser Baum) | Live `m12-768` |
| --- | --- | --- |
| `{}` | 24 Tools, `nextCursor: null` | 15 Tools, Cursor Offset 15 |
| `{"per_page": 15}` | 15 Tools, Cursor Offset 15 | 15 Tools, Cursor Offset 15 |
| `{"cursor": "eyJvZmZzZXQiOjE1fQ=="}` | 9 Tools, `nextCursor: null` | 9 Tools, `nextCursor: null` |
| `{"per_page": 100}` | 24 Tools (Server kappt auf 50), `nextCursor: null` | 24 Tools, `nextCursor: null` |

### 7.4 Live-Status und Deploy-Version

Am 2026-11-21 gegen `https://lan.raute1.org/mcp` mit dem Launcher-Token
geprüft:

- Live läuft `m12-768-ge8de9dad` (23 Commits hinter HEAD
  `m12-791-gd82d29be`).
- Seite 1: 15 Tools; Cursor 15: 9 Tools; `per_page=100`: **24 Tools**.
- Der MCP-SDK-/pi-Client folgt dem Cursor und sieht auch live 24 Tools.
- Der ausgelieferte Doctor meldet mit einem einzelnen `listTools()` 15;
  der Fix liegt in diesem Baum.

**Kein Pagination-Deploy-Blocker auf `m12-768`**: Cursor und `per_page`
funktionieren live. Ausgerollt werden muss HEAD (m12-791+) für die
Cursor-Härtung, den paginierenden Doctor und die Lineup-/Recipe-Änderungen.
Wenn nach dem Deploy weiter 15 Tools erscheinen: (1) Agent-Build mit
Doctor-Fix prüfen, (2) Cursor unverändert durchreichen (kein `=`
abschneiden/neu kodieren), (3) `MODULES_PROFILE=full` und `GameRecipes`
aktiviert lassen — sonst fehlen die fünf Recipe-Tools legitim.

### 7.5 Lineup filtert Daten, nicht Tools

`LAUNCHER_LINEUP` filtert die Spiel-Auswahl in `/api/v1/recipes`, Web
`/games` und `recipes_list`. Die **Registrierung** der Tools hängt nur an
`Module::GameRecipes`: Mit einer engen Lineup bleiben `recipes_list`,
`recipe_get`, `recipe_install_plan`, `recipe_avatars`, `recipe_contribute`
sichtbar; `recipe_get`/`recipe_install_plan` bleiben für direkte Slugs
erreichbar. `McpToolPaginationTest` prüft alle 24 Tools mit
`launcher.lineup=unreal-tournament-2004`.

### 7.6 Kurzprobe

```bash
TOKEN=$(jq -r .token ~/.config/lanomat-launcher/config.json)

curl -sS https://lan.raute1.org/mcp \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"per_page":100}}' \
  | jq '{count:(.result.tools|length), next:.result.nextCursor, names:[.result.tools[].name]}'
```

`lanomat-agent doctor` muss danach dieselbe Zahl melden wie der zweite
Probe-Request (24 bei Full-Profil).

