---
title: Install the LANoMAT agent
description: Install the pi-based LANoMAT agent on Windows or Linux, pair it with the server, wire the MCP server and recipe skills, set the Venice model, and check everything with doctor.
sidebar_icon: bot
---

The agent is the program that installs games on **your** PC. It talks to the LANoMAT server
over `/api/v1` and `/mcp`, keeps the game recipes as pi skills, and runs the install plan it
gets from the server. It never needs your Discord password and never sends your model key to
LANoMAT.

## Requirements

| | |
|---|---|
| OS | Linux (x64/arm64), Windows 10/11 (x64), macOS (x64/arm64) |
| Runtime | Node.js **22.12+** (24 LTS recommended) |
| Disk | ~250 MB for the agent + model cache; games separately |
| Network | the LANoMAT server, reachable by name/IP; the LAN file host for downloads |
| Model | Venice AI key, or any OpenAI-compatible endpoint (see [Models & fuel](/docs/agent/venice)) |

## Install

**From the release tarball (Linux/macOS):**

```bash
curl -fsSL https://<lan-host>/downloads/lanomat-agent.tar.gz -o lanomat-agent.tgz
npm install -g ./lanomat-agent.tgz
lanomat-agent --version
```

**From the repository (all platforms):**

```bash
git clone https://github.com/raute1-org/LANoMAT
cd LANoMAT/clients/agent
npm ci && npm run build && npm link
```

**Windows (PowerShell, from a checkout):**

```powershell
cd LANoMAT\clients\agent
npm ci; npm run build; npm link
lanomat-agent --version
```

The `install.sh` / `install.ps1` bootstrap scripts in `clients/agent` do the same and register
the `lanomat://` deep link; use them if your LAN serves the release bundle.

## Pair with the LAN server

```bash
lanomat-agent login --url http://lan.local
```

It prints a code and the URL `http://lan.local/mcp/connect`; open it while logged in and
approve. The token is stored in `~/.config/lanomat/agent.json` (Windows:
`%APPDATA%\lanomat\agent.json`), mode `0600`, valid 90 days and sliding on use. Revoke it
any time on `/mcp/connect` or in the settings.

| Dark | Light |
|---|---|
| ![MCP pairing, dark](/screens/mcp-connect-dark.png) | ![MCP pairing, light](/screens/mcp-connect-light.png) |
| **`/mcp/connect`** — type the code, approve, see the connected agents. | |

## Wire pi

```bash
lanomat-agent mcp install        # writes ~/.pi/agent/mcp.json, keeps other servers
lanomat-agent skills sync        # writes ~/.agents/skills/lanomat-<slug>/SKILL.md
lanomat-agent doctor
```

`mcp install` stores `Authorization: Bearer ${LANOMAT_TOKEN}` literally; export the token in
your shell (the agent prints the line) or let the agent manage it. `skills sync` is
idempotent and only removes `lanomat-*` directories it wrote. `--prune` removes skills whose
recipe disappeared.

## Model

Venice AI is the default (OpenAI-compatible at `https://api.venice.ai/api/v1`):

```bash
export VENICE_API_KEY=...             # PowerShell: $env:VENICE_API_KEY = "..."
lanomat-agent model setup             # writes ~/.pi/agent/models.json + a default model
lanomat-agent doctor --json
```

The default model is `venice/e2ee-deepseek-v4-1-flash-1017-abliterated` (TEE/E2EE, 1M context,
tool calls verified). If a tool call fails on that model, `--model venice/zai-org-glm-5` is the
documented fallback. Any OpenAI-compatible endpoint works with
`OPENAI_MODEL_BASE_URL`, `OPENAI_MODEL_API_KEY`, `OPENAI_MODEL_NAME` (and
`LANOMAT_AGENT_MODEL` for the full `provider/model`); precedence is CLI → env → saved config
→ Venice default.

## First run

```bash
lanomat-agent recipes
lanomat-agent plan ut2004 --os windows
lanomat-agent install ut2004 --dry-run
lanomat-agent install ut2004
lanomat-agent launch ut2004
```

`install` asks for the avatar multi-select, downloads LAN files in parallel, verifies checksums
and runs the plan. `launch` opens a `play_url` in the browser or runs the recipe's local
command. `--yes` skips confirmations; `--allow-external` permits internet fallbacks (announced).

## Where things live

| Path | Contents |
|---|---|
| `~/.config/lanomat/agent.json` | server URL, token, model, roles (0600) |
| `~/.pi/agent/mcp.json` | the `lanomat` MCP server (merged, not replaced) |
| `~/.pi/agent/models.json` | the Venice / OpenAI-compatible provider |
| `~/.agents/skills/lanomat-<slug>/` | one Agent Skill per recipe (`SKILL.md`, `recipe.json`) |
| `~/.local/share/lanomat/avatars/` | downloaded VRM avatars (Windows: `%LOCALAPPDATA%\LANoMAT\avatars`) |

## Troubleshooting

| Symptom | Fix |
|---|---|
| `login` says `authorization_pending` forever | Open the printed URL, log in, approve the code; check the code matches. |
| `401 Unauthorized` after login | The token was revoked or expired; `lanomat-agent login` again. |
| `mcp install` overwrote another server | It merges by key; check `~/.pi/agent/mcp.json`, re-add the lost entry. |
| `pi` does not see the tools | `pi mcp list`; it must show `lanomat` connected; `/reload` in a running session. |
| Skills not loaded | `ls ~/.agents/skills/lanomat-*`; `lanomat-agent skills sync --prune`; pi reload. |
| `doctor` says tool calls fail | Use the documented fallback model, set `VENICE_API_KEY`, check `doctor --json`. |
| Downloads slow | Use the LAN URL, not the internet; `--jobs 8`; check the file host is on the same LAN. |
| Windows deep link does nothing | `lanomat-agent register` prints the `reg add` command; run it once. |

## Uninstall

```bash
lanomat-agent logout            # removes the token (keeps MCP config + skills)
lanomat-agent skills sync --prune
npm uninstall -g @lanomat/agent
```

Revoke the token on `/mcp/connect` if the machine is gone.
