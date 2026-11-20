---
title: Install & login
description: Install the agent, pair it with LANoMAT, sync skills and run the first install.
sidebar_icon: download
---

The agent runs on a participant's PC. It needs a LANoMAT account, a paired device and a model. The
installer is small; the recipes and game files stay on the LAN.

<div class="live">LIVE 17:42 · paired · 2 published skills · model ready</div>

## 1. Install

The agent is an npm package (`@lanomat/agent`) with a Node 22.12+ runtime. Today it ships from the
repository and as a container image; there is no signed Windows setup executable yet.

### From source (Linux, Windows, macOS)

```bash
git clone https://github.com/raute1-org/LANoMAT.git
cd LANoMAT/clients/agent
npm ci
npm run build
npm link          # exposes lanomat-agent, or: node dist/cli.js
lanomat-agent --version
```

`npm link` puts a shim on your PATH; without it, call `node clients/agent/dist/cli.js` directly.

### Container (CI and a disposable shell)

```bash
docker build -t lanomat-agent clients/agent
docker run --rm -it -v "$HOME/.config/lanomat:/root/.config/lanomat" lanomat-agent --help
```

Mount the config directory if you want the pairing to survive the container.

## 2. Pair the device

```bash
lanomat-agent login --url https://lan.example
```

`--url` is the server origin (default `http://lanomat.local`); `--name` labels the device in the
app, and `--no-browser` skips opening a browser. The agent prints the short code and the URL
`https://lan.example/mcp/connect`, then polls `POST /api/mcp/token` every few seconds.

```text
Device code: K7QF-2M9R
Open:        https://lan.example/mcp/connect
Waiting for approval… done. Token stored for lan.example.
```

Approve it under `/mcp/connect`; the same page lists every paired device and can revoke one. The
token is valid for 90 days and slides on use.

The token lives in:

| OS | Path |
|---|---|
| Linux | `~/.config/lanomat/agent.json` (mode `0600`) |
| Linux with `XDG_CONFIG_HOME` | `$XDG_CONFIG_HOME/lanomat/agent.json` |
| Windows | `%USERPROFILE%\.config\lanomat\agent.json` (or `$XDG_CONFIG_HOME`) |

`LANOMAT_CONFIG_DIR` overrides the directory. The JSON holds `url`, `token`, an optional
`token_expires_at`, the user and the chosen model; the token is never printed except in the
`export LANOMAT_TOKEN=…` line that `mcp install` prints.

## 3. Wire pi and choose a model

```bash
lanomat-agent mcp install                  # writes/merges ~/.pi/agent/mcp.json
lanomat-agent model setup                  # writes/merges ~/.pi/agent/models.json + settings.json
lanomat-agent doctor                       # config, server, MCP, pi, skills, key, model, tool calls
```

`mcp install` preserves other servers and keys; `--server-name` changes the entry name (default
`lanomat`). For Venice:

```bash
export VENICE_API_KEY="…"        # PowerShell: $env:VENICE_API_KEY = "…"
lanomat-agent model setup        # reads $VENICE_API_KEY, ~/.config/lanomat/venice.env, or prompts
lanomat-agent doctor             # token, MCP, model, tool-calling probe
```

The default model is `venice/e2ee-deepseek-v4-1-flash-1017-abliterated`. Details, precedence and
alternatives: [Models & fuel](/docs/agent/venice).

## 4. Sync the recipe skills

```bash
lanomat-agent skills sync
lanomat-agent skills sync --dest ~/skills --prune
```

`sync` pulls every recipe the token can see (`GET /api/v1/recipes`, then each
`GET /api/v1/recipes/{slug}/skill`) into the pi skills directory, by default
`~/.agents/skills/lanomat-<slug>/`:

```text
~/.agents/skills/lanomat-unreal-tournament-2004/
├── SKILL.md
├── recipe.json        # {slug, title, version, source_url}
└── .lanomat-source    # {url, fetched_at}
```

It is idempotent: unchanged skills are not rewritten. `--prune` removes only `lanomat-*`
directories that carry a `.lanomat-source` marker; your own skills are never touched. Offline, the
synced skills are still readable — run `sync` before the LAN if you want the catalogue with you.

## 5. The first install

```bash
lanomat-agent install ut2004 --os windows
# or, one prompt with the MCP server attached:
lanomat-agent ask "install ut2004 for me on my pc"
```

`install` does the whole one-shot flow: plan → availability → avatars → LAN downloads → steps →
verify. Useful flags:

| Flag | Meaning |
|---|---|
| `--os windows\|linux` | Target OS (defaults to the current one) |
| `--event <slug>` | Restrict LAN file matching to one event |
| `--avatar <id>` | Select an avatar/content pack; repeat, or use `all` |
| `-y, --yes` | No prompts; run optional steps and select no avatars |
| `--allow-external` | Permit internet fallbacks; without it they are skipped and listed |
| `--dry-run` | Print the plan and commands, download and execute nothing |
| `--jobs <n>` | Parallel downloads (default 4) |
| `--chat` | Open the pi agent after a successful install (`--model` for that session) |

### Avatar multi-select

Avatars are not a yes/no question. The interactive prompt lists every LAN match:

```text
? Which avatars should I install? (space selects, enter confirms, none is valid)
  [x] VRM avatar · Ayla (agile scout) — 18 MB
  [x] VRM avatar · Brutus (heavy assault) — 21 MB
  [ ] VRM avatar · Kestrel (shock rifle) — 17 MB
  [ ] VRM avatar · Mako (arena duelist) — 19 MB
  [ ] VRM avatar · Sable (night ops) — 20 MB
```

In a script, pass `--avatar ayla --avatar brutus`, `--avatar all`, or `-y` to skip avatars. The agent
never invents a file; it only offers what `recipe_avatars` returned.

## 6. Day-to-day commands

```bash
lanomat-agent login                     # pair again / switch server
lanomat-agent recipes                   # list visible recipes
lanomat-agent plan ut2004 --os windows  # plan only, no downloads
lanomat-agent install ut2004            # same as the game page's Install button
lanomat-agent launch ut2004             # start an installed game
lanomat-agent launch ut2004 --variant vr  # pick a launch_targets entry (flat, vr, …)
lanomat-agent avatars --recipe ut2004   # download avatar/content packs
lanomat-agent ask "plan ridge racer type 4"
lanomat-agent ask --print-command "install ut2004"   # show the exact pi argv
lanomat-agent skills sync
lanomat-agent model roles               # show chat/decision/stt/tts
lanomat-agent doctor
```

The games library uses the same entry points as deep links:

| Link | Opens |
|---|---|
| `lanomat://install/<slug>` | The agent, with the recipe selected and the plan flow started |
| `lanomat://launch/<slug>` | The agent, launching the installed game |
| `lanomat://play/<slug>` | Alias for `launch` (what the web Starten button uses) |

The copyable equivalents are `lanomat-agent install <slug>` and `lanomat-agent launch <slug>`.
`lanomat-agent open "lanomat://install/ut2004"` handles a link manually; `lanomat-agent register`
writes the desktop/registry handler for the scheme.

`ask` is one prompt and exits. For a longer session with the same MCP connection, run `pi` with the
LANoMAT preset:

```bash
pi --model venice/e2ee-deepseek-v4-1-flash-1017-abliterated
```

The `lanomat` MCP server is already in `~/.pi/agent/mcp.json`, so `/mcp` shows it inside pi.

## Disk and permissions

| What | Where | Typical size |
|---|---|---|
| Agent + pi runtime | the npm global prefix, `~/.pi` | ~150 MB |
| Skills | `~/.agents/skills/lanomat-<slug>/` | < 5 MB |
| Avatars | `~/.local/share/lanomat/avatars/` (Linux), `%LOCALAPPDATA%\LANoMAT\avatars` (Windows) | the selected packs |
| Downloads | `<target>/.lanomat-downloads/` | the recipe's bytes |
| Install target | recipe-specific (`C:\Games\…`, `~/Games/…`) | the game |

`LANOMAT_SKILLS_DIR`, `LANOMAT_AVATARS_DIR` and `LANOMAT_PI_DIR` override the defaults. The agent
never writes into `Program Files` unless the recipe's target says so, and never modifies the LANoMAT
server.

<div class="callout-live">
  <strong>One server, one token.</strong> The agent talks to the server you paired with. A token from
  another LAN is just a string: run <code>login --url</code> again for the new host.
</div>
