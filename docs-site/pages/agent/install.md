---
title: Install & login
description: Install the agent, pair it with LANoMAT, sync skills and run the first install.
sidebar_icon: download
---

The agent runs on a participant's PC. It needs a LANoMAT account, a paired device and a model. The
installer is small; the recipes and game files stay on the LAN.

<div class="live">LIVE 17:42 · paired · skills 9/9 · model ready</div>

## 1. Install

### Linux

```bash
curl -fsSL https://lan.example/install-agent.sh | sh
lanomat-agent --version
```

The script installs to `~/.local/bin/lanomat-agent` (add it to `PATH` if needed) and bundles the pi
runtime. A system-wide install is `sudo sh install-agent.sh --prefix /usr/local`.

### Windows

Download `lanomat-agent-<version>-windows-x86_64-setup.exe` from the LANoMAT download page
(`https://lan.example/downloads/lanomat-agent`) and run it. It installs per user, no administrator
rights. The build is **not code-signed**, so SmartScreen shows "Windows protected your PC":
**More info → Run anyway**, after checking the SHA-256 on the download page.

### From source (developers)

```bash
git clone https://github.com/raute1-org/LANoMAT.git
cd LANoMAT/clients/agent
npm ci
npm run build
npm link          # exposes lanomat-agent
```

Node 22+ is required for the source path only; the native installer bundles its runtime.

## 2. Pair the device

```bash
lanomat-agent login --server https://lan.example
```

The agent prints a code and the URL `https://lan.example/mcp/connect`. Open it while
logged in, compare the code, approve. The agent stores a 90-day bearer token (sliding on use) and exits.

```text
Device code: K7QF-2M9R
Open:        https://lan.example/mcp/connect
Waiting for approval… done. Token stored for lan.example.
```

The token lives in:

| OS | Path |
|---|---|
| Linux | `~/.config/lanomat-agent/config.json` (mode `0600`) |
| Windows | `%APPDATA%\lanomat-agent\config.json` |

It is scoped to your user. Revoke it in the app's connections settings; the next call answers `401`
and you pair again.

| Dark | Light |
|---|---|
| ![MCP code entry, dark](/screens/mcp-connect-dark.png) | ![MCP code entry, light](/screens/mcp-connect-light.png) |
| `Agent & MCP verbinden · DARK` — code entry and the agents already paired | `Agent & MCP verbinden · LIGHT` |

## 3. Choose a model

```bash
lanomat-agent model setup
```

The wizard offers **Venice** (default), a generic OpenAI-compatible endpoint, or any provider pi
already knows. For Venice:

```bash
export VENICE_API_KEY="…"        # PowerShell: $env:VENICE_API_KEY = "…"
lanomat-agent model setup        # writes ~/.pi/agent/models.json with the venice provider
lanomat-agent doctor             # token, MCP, model, tool-calling probe
```

The default model is `venice/e2ee-deepseek-v4-1-flash-1017-abliterated`. Details, precedence and
alternatives: [Models & fuel](/docs/agent/venice).

## 4. Sync the recipe skills

```bash
lanomat-agent skills sync
lanomat-agent skills list
```

`sync` pulls every published recipe's `SKILL.md` and `recipe.yaml` into the pi skills directory
(`~/.pi/agent/skills/lanomat/<slug>/`). It is additive: a removed recipe is deleted locally, a
changed one is replaced. `--force` re-downloads everything, which is useful after a recipe update.

Offline, the synced skills are still readable. Run `sync` before the LAN if you want the catalogue
with you.

## 5. The first install

```bash
lanomat-agent ask "install ut2004 for me on my pc"
```

What happens:

1. The agent resolves the alias to `unreal-tournament-2004`.
2. It reads the skill and asks about VR and avatars.
3. It calls `recipe_install_plan` and shows the plan: required files, optional picks, target,
   estimated bytes.
4. On confirmation it downloads from the LAN file host, verifies, runs the steps and reports the
   verification checks.

### Avatar multi-select

Avatars are not a yes/no question. The interactive prompt lists every LAN match:

```text
? Which avatars should I install? (space selects, enter confirms, none is valid)
  [x] Ayla — agile scout — 18 MB
  [x] Brutus — heavy assault — 21 MB
  [ ] Kestrel — shock rifle — 17 MB
  [ ] Mako — arena duelist — 19 MB
  [ ] Sable — night ops — 20 MB
```

In a script, pass a comma-separated list with `--avatars ayla,brutus`, or `--no-avatars` to skip the
question. The agent never invents a file; it only offers what `recipe_avatars` returned.

## 6. Day-to-day commands

```bash
lanomat-agent login                 # pair again / switch server
lanomat-agent install ut2004        # same as the game page's Install button
lanomat-agent launch ut2004         # start an installed game
lanomat-agent ask "plan ridge racer type 4"
lanomat-agent ask --plan-only "install ut2004"
lanomat-agent skills sync
lanomat-agent model current
lanomat-agent doctor
```

The games library uses the same entry points as deep links:

| Link | Opens |
|---|---|
| `lanomat://install/<slug>` | The agent, with the recipe selected and the plan flow started |
| `lanomat://launch/<slug>` | The agent, launching the installed game |

The copyable equivalents are `lanomat-agent install <slug>` and `lanomat-agent launch <slug>`.

`ask` is one prompt and exits. For a longer session with the same MCP connection, run `pi` with the
LANoMAT preset:

```bash
pi --model venice/e2ee-deepseek-v4-1-flash-1017-abliterated
```

The `lanomat` MCP server is already in `~/.pi/agent/mcp.json`, so `/mcp` shows it inside pi.

## Disk and permissions

| What | Where | Typical size |
|---|---|---|
| Agent + pi runtime | `~/.local/bin`, `~/.pi` | ~150 MB |
| Skills | `~/.pi/agent/skills/lanomat/` | < 5 MB |
| Downloads | `~/.cache/lanomat-agent/downloads/` | the recipe's bytes |
| Install target | recipe-specific (`C:\Games\UT2004`, `~/Games/…`) | the game |

The agent never writes into `Program Files` unless the recipe's target says so, and never modifies
the LANoMAT server.

<div class="callout-live">
  <strong>One version, one server.</strong> The agent checks the server's version on login. A major
  mismatch asks you to update; a minor mismatch still works. The download page always offers the
  build that matches the server.
</div>
