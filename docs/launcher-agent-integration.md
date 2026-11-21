# Launcher ↔ Agent ↔ Web: Integration der ersten Lineup

Stand: **2026-11-21**. Beschreibt den gemeinsamen Vertrag von Launcher,
`/api/v1/recipes`, Web `/games`, Home-Chat und MCP. Keine Secrets, keine
Live-Änderung.

## 1. Eine Quelle für die Auswahl

`config/launcher.php` → `lineup` (Env `LAUNCHER_LINEUP`) ist die einzige konfigurierte
Allowlist. Der Parser sitzt in
`App\Modules\GameRecipes\Support\LauncherLineup`:

- konfigurierte Liste **plus** jedes Rezept mit `game_recipes.in_lineup = true`
  (der Panel-Schalter „In der Spieleliste zeigen“, gesetzt u. a. beim
  Veröffentlichen eines geteilten Spiels), lowercased, dedupliziert
- leere konfigurierte Liste → keine Filterung (Backcompat), auch dann, wenn
  Rezepte das Flag tragen

Angewendet wird sie in:

| Oberfläche | Stelle |
| --- | --- |
| Launcher/API | `RecipeController::index` |
| Web `/games` + `/games/{slug}` | `GamePageController::publishedRecipes()` |
| Home-Chat | `HomeChatData::games()` |
| MCP `recipes_list` | `RecipesListTool::run()` |
| Serverzeilen (Install-Block) | `RecipeInstallLinks::candidates()` |
| `/launcher` „Jetzt live“ | `LauncherPageController::liveServers()` (über den Install-Block) |

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
  "in_lineup": true,
  "pull_request": null,
  "play_url": "https://nfsu2.xyz/",
  "platforms": ["windows", "linux", "macos"]
}
```

Bedeutung:

- `installable`: Rezept hat Requirements, Steps und ein Launch-Target → es
  gibt einen Installationsplan.
- `launcher_supported`: dieser Client kann damit etwas anfangen. Die Antwort
  kommt aus **einer** Klasse, `App\Modules\GameRecipes\Support\LauncherSupport`
  (dieselbe für REST, Web, Home-Chat, Launcher-Seite und Serverzeilen):
  1. `metadata.launcher_supported` als Boolean überschreibt alles (der
     Orga-Schalter),
  2. sonst `true` für den UT2004-Piloten (`PILOT_SLUG`) und die von Hand
     geprüften `VERIFIED_SLUGS`,
  3. sonst `true` für ein Browser-Rezept mit `play_url`,
  4. sonst `isPortable()`: `metadata.portable: true`, ein erforderliches
     `base`-Requirement, ein nicht-optionaler `file_exists`/`dir_exists`-Check
     und mindestens ein Launch-Target.
- `in_lineup`: das Panel-Flag des Rezepts. Der Client sieht nur Rezepte, die
  die Lineup ohnehin durchlässt; das Feld macht sichtbar, ob das Rezept
  konfiguriert oder per Flag in der Liste steht.
- `pull_request`: `{number, url, state, label}` für ein eigenes geteiltes
  Rezept (oder für die Orga), sonst `null`. `url` ist nur bei einem Pull
  Request gesetzt, den es im Repository gibt (`open`, `merged`, `closed`);
  `blocked`/`failed` tragen den deutschen Zustand ohne Link.
- `play_url`: nur bei Browser-Rezepten. Der Launcher öffnet sie mit
  `open_url`; es gibt keinen Plan, keinen Download und kein `verify_install`.

`RecipeSummary` (Rust) hat dafür ein optionales `play_url`. Serde ignoriert
unbekannte Felder, ein älterer Launcher bleibt kompatibel; `in_lineup` und
`pull_request` fehlen dort einfach.

## 3. Launcher-Verhalten

`launcher_supports(recipe)` bleibt im Client eine Oder-Verknüpfung:

1. der Slug der dedizierte UT2004-Pfad ist, **oder**
2. der Server `launcher_supported: true` sendet (die Regel oben), **oder**
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

Ein Beitrag über `recipe_contribute` oder `POST /api/v1/recipes` muss einzeilig
sein (Titel, Kurzfassung, Version, Aliase), pinnt nur Dateien, die der Aufrufer
herunterladen darf (`SharedFilePolicy::download`: die eigene wartende Datei
oder eine freigegebene; nie eine abgelehnte), und zählt gegen
`RECIPES_MAX_DRAFTS_PER_USER` (Standard 20 je Teilnehmer, Orga ausgenommen).
`metadata.launcher_supported` setzt nur die Orga. Ein Entwurf erreicht den
öffentlichen Katalog erst, wenn die Orga ihn veröffentlicht — mit dem
Standard `RECIPES_PR_TRIGGER=publish` entsteht der Pull Request in diesem
Moment; `contribute` öffnet ihn schon beim Teilen.

## 5. Serverbrowser

Jede Serverzeile trägt jetzt den **Install-Block** aus der Serverprojektion
(`ServerListProjection::dto()` → `install`):

```json
{
  "recipeSlug": "unreal-tournament-2004",
  "title": "Unreal Tournament 2004",
  "pageUrl": "/games/unreal-tournament-2004",
  "launcherSupported": true,
  "launcherJoinUrl": "lanomat://join/unreal-tournament-2004?address=lan.example%3A7777"
}
```

- `install` ist `null`, wenn kein veröffentlichtes, von der Lineup erlaubtes
  Rezept zum Spiel des Servers passt. Die Zuordnung ist **eine** Klasse,
  `RecipeGameMatcher`: exakter Spiel-Slug zuerst (der Server-Slug gegen den
  Rezept-Slug), sonst die `searchTerms()` des Rezepts gegen Name und Slug des
  Spiels nach den Regeln der Spielseite.
- `launcherJoinUrl` baut `LauncherJoinLink`: `?address=<host>:<port>` für
  UT2004 (der Launcher startet direkt auf dem Server), sonst
  `?url=<urlencodierte Join-URL>` (z. B. `steam://connect/…`), sonst der Link
  ohne Parameter. Hat der Server weder eine brauchbare Adresse noch eine
  erlaubte Join-URL, ist `launcherJoinUrl` `null`; die Zeile behält ihre
  eigene Verbindung. Nur bei `LauncherSupport::supports()` wird überhaupt ein
  Link gebaut.
- Die Zeile zeigt dann „Im Launcher beitreten“, „Spiel installieren“ (→
  `pageUrl`) und „Mitspieler suchen“ (→ `pageUrl#zusammen-spielen`). Amber
  bleibt bei „Verbinden“ der eigenen Match-Zeile.
- UT2004: bestehender Server `10.23.0.40:7777`, unverändert.
- NFSU2: ein manueller/ext. Dauerserver, siehe
  `docs/ops/launcher-lineup.md` (`LauncherLineupSeeder`): `NFSU2 (Browser)`,
  `nfsu2.xyz:443`, Connect `https://nfsu2.xyz/`.

Ein echter `ServerLink` gewinnt gegen die Rezept-Ableitung; die Spielseite
zeigt den Server dann nicht doppelt. Der Install-Block ist davon unabhängig.

## 6. Änderungen an der Lineup

1. Slug in `LAUNCHER_LINEUP` aufnehmen (kommagetrennt),
   **oder** im Panel beim Rezept „In der Spieleliste zeigen“ einschalten
   (das setzt `in_lineup`; das Veröffentlichen eines geteilten Spiels macht
   es automatisch).
2. Config-Cache leeren.
3. Sicherstellen, dass das Rezept publiziert und für den Ziel-Nutzer sichtbar
   ist.
4. Bei einem Browser-Rezept `play_url` + `metadata.server` setzen; bei einem
   installierbaren Rezept Requirements/Steps/Launch-Target pflegen — oder es
   als portables Rezept teilen (`metadata.portable`, ein Base-Archiv, ein
   Datei-Check, Launch-Targets).
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

### 7.2 Tool-Matrix (Full-Profil, 29 Tools)

`LanomatServer::$tools` enthält 29 Tool-Klassen; `tests/Feature/Mcp/McpToolPaginationTest.php`
führt dieselben 29 Namen und schlägt fehl, sobald die Listen auseinanderlaufen.

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
| Catering | `catering_menu`, `catering_menu_get` | Speisekarte und offene/geplante Bestellungen | Catering |
| Catering-Import (Orga) | `catering_menu_preview`, `catering_menu_import`, `catering_round_create`, `restaurants_list` | Menü aus PDF/Seite einlesen, prüfen, importieren; Runde anlegen | Catering |
| LFG | `lfg_list`, `lfg_create` | Gruppe finden oder posten | Lfg |
| Browser-Play | `recipe_get` (`play_url`, `launch_target(s)`) | Browser-Spiel ohne Installation öffnen; der Launcher liest `play_url` zusätzlich aus `/api/v1/recipes/{slug}` | GameRecipes |

**Pflicht für den Installations-Flow des pi-Agenten:** `lanomat_me`,
`recipes_list`, `recipe_get`, `recipe_install_plan`, `recipe_avatars`; dazu
`files_list`/`file_download_url`, wenn eine Datei nicht schon im Plan steht.
**Join-Aktionen:** `event_register`, `tournament_checkin`, `lfg_create`.
**Kontext nach dem Install:** `servers_list`, `recipe_get.play_url`,
`events_list`/`event_get`.

### 7.3 Pagination: warum nur 15 Tools sichtbar waren

`tools/list` ist cursor-paginiert. Die damals ausgelieferte Live-Version
`m12-768` lieferte ohne Parameter 15 Tools und `nextCursor` mit Offset 15;
die zweite Seite hatte die restlichen 9 inklusive aller fünf Recipe-Tools.
Mit `per_page=15` ist das auch heute noch die erste Seite; der Katalog hat
inzwischen 29 Tools.

Zwei Client-Ursachen für „Recipe-Tools unsichtbar“:

1. **Doctor:** `clients/agent/src/doctor.ts` rief `client.listTools()` genau
   einmal auf. Der SDK paginiert nicht automatisch → „15 MCP tool(s)
   available“. Der Doctor fragt jetzt `per_page=100` und folgt jedem
   `nextCursor` (`listAllToolNames`), meldet also alle 29.
2. **Cursor:** Ein Cursor ohne Base64-Padding (`==`) oder in base64url wurde
   vom Vendor-Paginator still als Offset 0 gelesen und zeigte Seite 1 erneut.
   `App\Modules\Mcp\Server\Methods\ListTools` normalisiert Padding/base64url,
   akzeptiert `per_page` als Zahl oder Zahl-String, liefert auf der letzten
   Seite `nextCursor: null` und beantwortet einen echten Müll-Cursor mit
   JSON-RPC `-32602` statt Seite 1.

Zusätzlich setzt `LanomatServer::$defaultPaginationLength = 50`: Damit passt
der heutige 29-Tool-Katalog in **eine** Seite, auch für Clients ohne
Cursor-Schleife. Der Doctor paginiert trotzdem weiter, damit ein später
größerer Katalog nicht wieder abgeschnitten wird.

| Request | HEAD (dieser Baum) | Live `m12-768` (damals) |
| --- | --- | --- |
| `{}` | 29 Tools, `nextCursor: null` | 15 Tools, Cursor Offset 15 |
| `{"per_page": 15}` | 15 Tools, Cursor Offset 15 | 15 Tools, Cursor Offset 15 |
| `{"cursor": "eyJvZmZzZXQiOjE1fQ=="}` | 14 Tools, `nextCursor: null` | 9 Tools, `nextCursor: null` |
| `{"per_page": 100}` | 29 Tools (Server kappt auf 50), `nextCursor: null` | 24 Tools, `nextCursor: null` |

### 7.4 Live-Status und Deploy-Version

Am 2026-11-21 gegen `https://lan.raute1.org/mcp` mit dem Launcher-Token
geprüft (der Baum hatte damals 24 Tools):

- Live lief `m12-768-ge8de9dad` (23 Commits hinter HEAD
  `m12-791-gd82d29be`).
- Seite 1: 15 Tools; Cursor 15: 9 Tools; `per_page=100`: **24 Tools**.
- Der MCP-SDK-/pi-Client folgt dem Cursor und sieht auch live alle Tools.
- Der ausgelieferte Doctor meldet mit einem einzelnen `listTools()` 15;
  der Fix liegt in diesem Baum.

**Kein Pagination-Deploy-Blocker auf `m12-768`**: Cursor und `per_page`
funktionieren live. Ausgerollt werden muss HEAD für die Cursor-Härtung, den
paginierenden Doctor und die Lineup-/Recipe-Änderungen.
Wenn nach dem Deploy weiter 15 Tools erscheinen: (1) Agent-Build mit
Doctor-Fix prüfen, (2) Cursor unverändert durchreichen (kein `=`
abschneiden/neu kodieren), (3) `MODULES_PROFILE=full` und `GameRecipes`
aktiviert lassen — sonst fehlen die fünf Recipe-Tools legitim.

Ein Tool-Zähler ist keine feste Zahl im Vertrag: er ändert sich mit jedem
Modul und jedem Tool. Verbindlich sind `LanomatServer::$tools` und die
Namenliste in `McpToolPaginationTest`; beide müssen gleich sein.

### 7.5 Lineup filtert Daten, nicht Tools

`LAUNCHER_LINEUP` filtert die Spiel-Auswahl in `/api/v1/recipes`, Web
`/games` und `recipes_list` — zusammen mit den Rezepten, die
`game_recipes.in_lineup = true` tragen. Die **Registrierung** der Tools hängt
nur an `Module::GameRecipes`: Mit einer engen Lineup bleiben `recipes_list`,
`recipe_get`, `recipe_install_plan`, `recipe_avatars`, `recipe_contribute`
sichtbar; `recipe_get`/`recipe_install_plan` bleiben für direkte Slugs
erreichbar. `McpToolPaginationTest` prüft alle 29 Tools mit
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
Probe-Request (29 bei Full-Profil).

