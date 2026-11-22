---
title: Install & login
description: Install the agent, pair it with LANoMAT, sync skills and run the first install.
sidebar_icon: download
---

The agent runs on a participant's PC. It needs a LANoMAT account, a paired device and a model. The
installer is small; the recipes and game files stay on the LAN.

<div class="live">LIVE 17:42 · paired · skills 9/9 · model ready</div>

## 1. Install

The agent is an npm package (`@lanomat/agent`); it is not a native installer and there is no
`install-agent.sh`. It needs Node 22.12+ (or 24) and npm 10+.

### From source

```bash
git clone https://github.com/raute1-org/LANoMAT.git
cd LANoMAT/clients/agent
npm ci
npm run build
npm link          # exposes lanomat-agent
lanomat-agent --version
```

`npm link` puts the `lanomat-agent` shim on your PATH. The `npm run build` step is the update too;
there is no self-update command.

### From the packed tarball

```bash
npm install -g ./lanomat-agent-0.1.0.tgz
lanomat-agent --version
```

A tag `agent-v<version>` attaches that tarball to a draft GitHub release; it is published to the npm
registry only when the repository has an `NPM_TOKEN`.

### Container

```bash
docker build -t lanomat-agent clients/agent
docker run --rm -it -v "$HOME/.config/lanomat:/home/node/.config/lanomat" lanomat-agent --help
```

## 2. Pair the device

```bash
lanomat-agent login --url https://lan.example
```

The agent prints a code and the URL `https://lan.example/mcp/connect`. Open it while
logged in, compare the code, approve. The agent stores a 90-day bearer token (sliding on use) and exits.

```text
Sign in to https://lan.example
Open https://lan.example/mcp/connect in your browser
and enter the code: K7QF-2M9R

Waiting for approval…
Signed in as Rene. Config written to /home/rene/.config/lanomat/agent.json
Next: lanomat-agent mcp install
      lanomat-agent skills sync
      lanomat-agent model setup
```

| Dark | Light |
|---|---|
| ![MCP code entry with the agents already paired, dark](/screens/mcp-connect-dark.png) | ![MCP code entry with the agents already paired, light](/screens/mcp-connect-light.png) |
| `PAIRING · CODE ENTRY · CONNECTED AGENTS` | `PAIRING · LIGHT` |

The token lives in:

| OS | Path |
|---|---|
| Linux | `~/.config/lanomat/agent.json` (file `0600`, directory `0700`) |
| Linux with `XDG_CONFIG_HOME` | `$XDG_CONFIG_HOME/lanomat/agent.json` |
| Windows | `%USERPROFILE%\.config\lanomat\agent.json` (or `$XDG_CONFIG_HOME` if set) |

`LANOMAT_CONFIG_DIR` overrides the directory. It is scoped to your user. Revoke the device under
**„Verbundene Geräte“** on `/mcp/connect`; the next call answers `401` and you pair again.

`login` ends by naming the next three commands; the first one wires the MCP server into pi:

```bash
lanomat-agent mcp install
lanomat-agent skills sync
lanomat-agent model setup
```

The separate **Verknüpfungen** settings page links game accounts (Steam, Twitch) and offers the
desktop app download; the agent's token is not listed there.

| Dark | Light |
|---|---|
| ![Account connections page with Steam, Twitch and the desktop app download, dark](/screens/connections-dark.png) | ![Account connections page with Steam, Twitch and the desktop app download, light](/screens/connections-light.png) |
| `CONNECTIONS · STEAM · TWITCH · DESKTOP APP` | `CONNECTIONS · LIGHT` |

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

| Dark | Light |
|---|---|
| ![Doctor output: config, server, MCP tools, model catalog and Doctor: OK, dark](/screens/agent-doctor-terminal-dark.png) | ![Doctor output: config, server, MCP tools, model catalog and Doctor: OK, light](/screens/agent-doctor-terminal-light.png) |
| `DOCTOR · CONFIG · SERVER · MCP · MODEL` | `DOCTOR · LIGHT` |

`doctor` is the check before the LAN: the required **Config** and **Server** lines plus the advisory
ones (skills, `smbclient`, Venice key, `models.json`, model catalog, tool-calling probe).

## 4. Sync the recipe skills

```bash
lanomat-agent skills sync
```

`sync` pulls every published recipe into the pi skills directory as one sub-skill per recipe:
`~/.agents/skills/lanomat-<slug>/SKILL.md` plus `recipe.json`. It is additive: only changed skills
are rewritten (`22 unchanged` on a second run). Pass `--dest <dir>` for another directory and
`--prune` to delete `lanomat-*` skills whose recipe vanished. There is no `skills list`; `ls
~/.agents/skills` is the list.

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

| Dark | Light |
|---|---|
| ![Dry-run install plan for unreal-tournament-2004 with files, byte sizes, steps and avatars, dark](/screens/agent-install-terminal-dark.png) | ![Dry-run install plan for unreal-tournament-2004 with files, byte sizes, steps and avatars, light](/screens/agent-install-terminal-light.png) |
| `INSTALL PLAN · DRY RUN · FILES · STEPS` | `INSTALL PLAN · LIGHT` |

The plan names every file with its LAN size, the steps with their verification checks, and the
avatars as a multi-select. `--dry-run` prints the same output without downloading a byte.

### Avatar multi-select

Avatars are not a yes/no question. The interactive prompt lists every LAN match:

```text
LANoMAT avatars  (space toggle · a all · enter confirm · q cancel)
> [x] VRM avatars (every .vrm file on the LAN file host) (unknown, 18.0 MB)
1 selected
```

One entry per selectable requirement (the matcher takes the largest LAN file that fits its glob);
the UT2004 recipe declares one broad `*.vrm` requirement, so its list has one line. A recipe with one
entry per avatar lists one line each.

In a script, repeat `--avatar <id>` (`--avatar all` selects every avatar with a LAN copy) or pass
`-y` to select none and skip the question. The standalone `lanomat-agent avatars` command downloads
selections without an install. The agent never invents a file; it only offers what
`recipe_avatars` returned.

## 6. Day-to-day commands

```bash
lanomat-agent login                 # pair again / switch server
lanomat-agent install ut2004        # same as the game page's Install button
lanomat-agent launch ut2004         # start an installed game
lanomat-agent plan ridge-racer-type-4
lanomat-agent recipes
lanomat-agent ask "install ut2004 for me on my pc"
lanomat-agent skills sync
lanomat-agent model roles
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
| Skills | `~/.agents/skills/lanomat-<slug>/` | < 5 MB |
| Downloads | `<target>/.lanomat-downloads/` (the plan names it) | the recipe's bytes |
| Install target | recipe-specific (`C:\Games\UT2004`, `~/Games/…`) | the game |

The agent never writes into `Program Files` unless the recipe's target says so, and never modifies
the LANoMAT server.

<div class="callout-live">
  <strong>One version, one server.</strong> The agent talks to the server you paired with;
  `GET /api/v1/me` names that server and its version, and `doctor` prints both. A server that moved
  to a new address needs a new `login --url`.
</div>
