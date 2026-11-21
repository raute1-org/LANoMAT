# Discord-Assistent (`/ask`, Mentions, DMs)

Stand: **2026-11-21**. Der Assistent beantwortet Fragen von Gästen im Discord:
Spiele, Installation, Server, Events. Er ist **opt-in** und läuft im
`discord-gateway`-Sidecar neben der bestehenden Gateway-Verbindung.

| Was | Wo |
| --- | --- |
| Gateway + Agent | `docker/discord-gateway/bot.mjs`, `docker/discord-gateway/agent.mjs` |
| Tests (ohne Discord/Token) | `docker/discord-gateway/agent.test.mjs` |
| MCP-Server | `https://lan.raute1.org/mcp` (`routes/ai.php`, `app/Modules/Mcp`) |
| Helfertext | `lang/de/discord.php` → `discord.help.agent` |
| Konfiguration | `.env` (Vorlage: `.env.example`), `compose.yml` |

## 1. Architektur in einem Satz

Der Gateway hält die Discord-Verbindung; `agent.mjs` baut
`~/.pi/agent/mcp.json`, startet `pi --print` mit deutschem Systemprompt und
fällt ohne `pi`/Modell auf einen deterministischen MCP-JSON-RPC-Client zurück.
Alle anderen Events gehen unverändert an den PHP-Ingress.

### Was der Bot wann beantwortet

- `/ask <frage>` — guild-only registriert beim Start (create/edit).
- `@LANoMAT <frage>` — nur in `DISCORD_AGENT_GUILD_ID` (Fallback
  `DISCORD_GUILD_ID`) und, wenn gesetzt, nur in `DISCORD_AGENT_CHANNEL_ID`.
- DM an den Bot — immer (kein Guild/Channel-Filter).
- `!ask …`, `!game …`, `?ask …` — dieselbe Guild/Channel-Beschränkung.

Bots, Webhooks, Systemnachrichten und Gateway-Redeliveries (begrenztes
`seenMessages`-Set) werden ignoriert. Ein Per-User-Rate-Limit (Default 5
Fragen / 10 Minuten) schützt vor Spam. Antworten werden bei 2000 Zeichen
umgebrochen (Zeilen-/Wortgrenzen, keine kaputten Emoji-Paare) und mit
`allowed_mentions.parse = []` gesendet.

### pi-Aufruf

```
pi --print --no-session --no-context-files --no-builtin-tools \
   [--provider "$PI_PROVIDER"] [--model "$PI_MODEL"] \
   [--skill "$PI_SKILLS_DIR"] \
   --system-prompt "<deutscher Systemprompt>" \
   "Frage von <name> im Kanal <name>: <frage>"
```

- `--no-builtin-tools`: kein Shell-, Datei- oder Edit-Zugriff; nur MCP-Tools.
- Timeout: `DISCORD_AGENT_TIMEOUT_MS` (Default 90 s), danach `SIGKILL` und
  Fallback.
- `mcp.json` enthält `Bearer ${LANOMAT_TOKEN}` (Platzhalter, kein Token auf
  Platte), `exposure: deferred` — dieselbe Form wie `clients/agent/src/mcp.ts`.
- Der Systemprompt verbietet erfundene Slugs/IDs/Preise/Termine und verlangt
  `lanomat://install/<slug>` plus `https://lan.raute1.org/games/<slug>`.

### Fallback ohne pi/LLM

`agent.mjs` spricht JSON-RPC über den streamable-HTTP-Endpunkt (`/mcp`) und
nutzt ausschließlich vier lesende Tools:

| Tool | Zweck |
| --- | --- |
| `recipes_list` | Spiel anhand der Frage finden (`query`, notfalls einzelne Wörter) |
| `recipe_get` | Titel, Startbefehl, Dateien |
| `recipe_install_plan` | Plan für `windows`/`linux`/`macos`, `dry_run: true` |
| `servers_list` | Titel, Spiel, Status, `join_info` |

Antwortmuster (nur aus Tool-Daten):

```
Installiere Unreal Tournament 2004 (`unreal-tournament-2004`):
Plan für linux: ~/Games/unreal-tournament-2004
Schritte: 5 · Downloads: 3 · Fehlt: nichts
Dateien:
- UT2004 Linux Installer (LAN): https://lan.raute1.org/api/v1/files/7/download
…
Mehr: lanomat://install/unreal-tournament-2004 · https://lan.raute1.org/games/unreal-tournament-2004
```

```
Server:
- UT2004 Hauptserver (Unreal Tournament 2004) · Status: online · Beitreten: <lan-ip>:7777
Serverbrowser: https://lan.raute1.org/servers
```

Mehrdeutige Treffer werden als Liste ausgegeben, keine Treffer als
„kein passendes Spiel“ — es wird nie ein Slug geraten.

## 2. Einmalige Einrichtung

### 2.1 Discord-Portal

1. **Message Content Intent** aktivieren (Bot → Privileged Gateway Intents).
   Ohne ihn kommen DMs und `!ask`-Texte leer an; `<@mention>` funktioniert
   meist trotzdem.
2. **Server Members Intent** bleibt aktiv (bestehender Gateway-Bedarf).
3. **Interactions Endpoint URL leeren**, damit Interaktionen über den Gateway
   kommen (bestehende Voraussetzung, siehe `docker/discord-gateway/README.md`).
4. Bot mit `bot` + `applications.commands` in die Guild einladen.

### 2.2 MCP-Token

Ein eigener, möglichst nur lesender LANoMAT-Nutzer für den Bot:

```bash
docker compose exec app php artisan lanomat:mcp-token bot@example.org --name "Discord-Bot"
```

Der Token wird **einmal** ausgegeben. In `.env` auf dem Server:

```dotenv
DISCORD_AGENT_ENABLED=true
DISCORD_AGENT_GUILD_ID=<guild-id>
DISCORD_AGENT_CHANNEL_ID=<optional-channel-id>
LANOMAT_URL=https://lan.raute1.org
LANOMAT_TOKEN=<token>
# Modell/Provider optional; ohne Modell läuft der Fallback.
PI_MODEL=
PI_PROVIDER=
# z. B. ANTHROPIC_API_KEY / OPENAI_API_KEY für pi (nicht ins Repo)
```

Keine echten IDs/Tokens in getrackte Dateien schreiben
(`scripts/ops/check-public-safe.sh` erkennt Snowflakes).

### 2.3 Start und Prüfung

```bash
docker compose --profile prod up -d --build discord-gateway
docker compose logs -f discord-gateway | grep -E "logged in as|agent enabled|/ask registered"
```

Erwartet:

```
discord-gateway: logged in as LANoMAT#1234
discord-gateway: agent enabled (guild 123…, channel any, model pi default)
discord-gateway: /ask registered in guild 123…
```

Dann im konfigurierten Kanal:

- `/ask Welche Spiele kann ich installieren?`
- `@LANoMAT Installiere UT2004`
- DM: `Welche Server gibt es?`

Im Log steht bei jeder Nicht-pi-Antwort
`agent fallback for … (fallback)` — das ist der erwartete deterministische Pfad.

## 3. MCP `tools/list` und der Cursor-Bug (m12-768)

Der Live-Stand `m12-768` liefert auf Seite 1 15 Tools; die fünf Recipe-Tools
(`recipes_list`, `recipe_get`, `recipe_install_plan`, `recipe_avatars`,
`recipe_contribute`) liegen auf Seite 2, und der Live-Cursor liefert fälschlich
erneut Seite 1 (m12-768). In HEAD gilt:

- 24 registrierte **Tools** (plus 1 Resource und 1 Prompt = 26 MCP-Primitives),
- `per_page` wird auf `maxPaginationLength` geklemmt,
- der Cursor ist `base64({"offset":N})`,
- 24 Tools passen mit `per_page=100` in **eine** Seite.

Der Server-Fix liegt repo-lokal (nicht Live) in
`app/Modules/Mcp/Server/Methods/ListTools.php`: base64url/ungepolstert wird
normalisiert, ein kaputter Cursor ist `-32602` statt „Seite 1 nochmal“, und die
letzte Seite liefert `nextCursor: null`. `LanomatServer::boot()` registriert den
Handler.

Prüfung nach dem Deploy (Token nie in die History schreiben):

```bash
read -rsp 'LANoMAT MCP token: ' LANOMAT_TOKEN; echo
export LANOMAT_TOKEN

curl -sS https://lan.raute1.org/mcp \
  -H "Authorization: Bearer $LANOMAT_TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{"per_page":100}}' \
  | jq -r '.result.tools[].name' | sort

curl -sS https://lan.raute1.org/mcp \
  -H "Authorization: Bearer $LANOMAT_TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{"per_page":15}}' \
  | jq '{count:(.result.tools|length), next:.result.nextCursor}'

unset LANOMAT_TOKEN
```

Erwartet nach Deploy:

- `per_page=100`: **24** Tool-Namen, kein `nextCursor`, darunter alle fünf
  Recipe-Tools. Zählt man Resource (`RecipeSkillResource`) und Prompt
  (`InstallGamePrompt`) mit, sind es die im Task genannten **26** MCP-Primitives.
- `per_page=15`: 15 Tools + `nextCursor`; ein zweiter Aufruf mit
  `"cursor":"<nextCursor>"` liefert die restlichen 9 (ohne Überschneidung).

Automatisiert im Repo:
`tests/Feature/Mcp/McpToolPaginationTest.php` (24 Tools, Cursor-Seite 2,
ungepolsteter base64url-Cursor, kaputter Cursor → 400/-32602,
`per_page=100` → eine Seite). Der Test läuft gegen die lokale Test-DB, nicht
gegen Live.

> **Offen:** m12-768 ist der Live-Stand. Ob der Fix live ankommt, entscheidet
> der nächste Deploy von HEAD; ohne Deploy bleibt der Live-Cursor defekt. Der
> Fallback ruft `tools/call` direkt (kein `tools/list`), die Recipe-Tools sind
> also auch auf m12-768 aufrufbar; der kaputte Cursor trifft vor allem `pi`
> und andere Clients, die die Tool-Liste als Katalog lesen. Für eine saubere
> Live-Verifikation bleibt der Deploy trotzdem nötig.

## 4. Tests (ohne Discord, ohne Token)

```bash
cd docker/discord-gateway
node --check bot.mjs
node --check agent.mjs
node --test agent.test.mjs
```

Abgedeckt: `mcp.json`-Form/Merge (kein Token auf Platte), pi-Argumente und
Systemprompt, DM-/Mention-/Prefix-Erkennung inkl. Guild/Channel-Filter,
2000-Zeichen-Chunks (Surrogat-sicher), JSON/SSE-Parsing, `per_page=100`-Seite,
deterministischer Install-/Server-Fallback ohne Halluzinationen, pi-Fehler →
Fallback, Rate-Limit.

PHP (lokal oder im CI-Container mit Test-DB):

```bash
php artisan test \
  tests/Feature/Discord/GatewaySurfaceEventsTest.php \
  tests/Feature/Discord/SlashCommandTest.php \
  tests/Feature/Mcp/McpToolPaginationTest.php
```

## 5. Offene Punkte vor dem Live-Betrieb

1. **Deploy:** HEAD mit dem Cursor-Fix und diesem Agenten ausrollen; erst
   danach den Live-`per_page=100`-Check aus §3 als bestanden markieren.
2. **Token:** `LANOMAT_TOKEN` muss ein echter, dedizierter Bot-Nutzer sein;
   Ablauf/Löschung über `/mcp/connect` bzw. `lanomat:mcp-token` beachten.
   Der Token steht nur in der Server-`.env`, nie im Repo.
3. **Portal:** Message-Content- und Members-Intent müssen im Developer Portal
   gesetzt sein; `applications.commands` für die guild-only `/ask`-Registrierung.
4. **Modell:** `pi` braucht Provider-Credentials (Env) oder einen lokalen
   Provider. Ohne Modell bleibt der Fallback aktiv (gewollt), aber
   Freitextfragen außerhalb der vier Tools werden nur grob beantwortet.
5. **Live-Rauchtest:** eine echte Mention, eine DM, `/ask`, ein Rate-Limit-Fall
   und ein Server-/Install-Fall; Log auf `agent fallback` und auf
   `could not register /ask` prüfen.
6. **Chunk-Grenzen:** Discord begrenzt 2000 Zeichen; der Bot splittet, aber
   sehr lange Modellantworten werden ggf. auf mehrere Nachrichten verteilt.
   Das ist beabsichtigt, aber im Live-Test einmal ansehen.
7. **Impressum/Datenschutz:** Nachrichteninhalte gehen an den Modell-Provider
   (bei aktivem pi) und werden für die Dauer des Requests verarbeitet. Wenn das
   für die Veranstaltung nicht gewünscht ist, `DISCORD_AGENT_ENABLED=false`
   lassen oder nur den Fallback nutzen (kein LLM, nur MCP).
