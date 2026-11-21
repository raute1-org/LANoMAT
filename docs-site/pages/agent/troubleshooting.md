---
title: Agent troubleshooting
description: Pairing, model, tool calls, downloads, avatars and offline behaviour — the common failures.
sidebar_icon: wrench
---

Start with the doctor. It checks the things that actually break: token, MCP handshake, model call,
tool calling, disk space and the LAN file host.

```bash
lanomat-agent doctor
```

```text
✓ config        ~/.config/lanomat-agent/config.json (0600)
✓ login         lan.example, user rene, expires in 29 days
✓ mcp           connected, 29 tools, protocol 2025-06-18
✓ model         venice/e2ee-deepseek-v4-1-flash-1017-abliterated
✓ tool calls    lanomat_me → ok (412 ms)
✓ disk          84 GB free in ~/.cache/lanomat-agent
✓ file host     reachable, bearer-authenticated download URL accepted
```

| Dark | Light |
|---|---|
| ![Doctor report with config, server, MCP, model catalog, roles and the final Doctor: OK line, dark](/screens/agent-doctor-terminal-dark.png) | ![Doctor report with config, server, MCP, model catalog, roles and the final Doctor: OK line, light](/screens/agent-doctor-terminal-light.png) |
| `DOCTOR · CONFIG · LOGIN · MCP · MODEL · TOOL CALLS` | `DOCTOR · LIGHT` |

## Pairing and token

| Symptom | Cause | Fix |
|---|---|---|
| `401 unauthorized` | Token expired or revoked | `lanomat-agent login --url https://lan.example` |
| `authorization_pending` forever | Code not approved in the browser | Open `verification_uri`, compare the code, approve |
| Browser opens the wrong host | `APP_URL` differs from the address you use | Pass the exact `--server` URL you open in the browser |
| Works on the host, not on a laptop | Different network / DNS | Use the venue DNS name, not `localhost` |
| `device code expired` | Took longer than 10 minutes | Start `login` again |

The token is scoped to the user. If a participant token cannot read an orga draft recipe, that is
correct, not a bug.

The code entry and the devices already paired live on **Geräte & MCP**; **„Trennen“** is
what turns a working command into a `401`:

| Dark | Light |
|---|---|
| ![Agent and MCP pairing page with the eight-character code field and the list of connected devices, dark](/screens/mcp-connect-dark.png) | ![Agent and MCP pairing page with the eight-character code field and the list of connected devices, light](/screens/mcp-connect-light.png) |
| `GERÄTE & MCP · CODE · VERBUNDENE GERÄTE` | `GERÄTE & MCP · LIGHT` |

## Model and fuel

| Symptom | Cause | Fix |
|---|---|---|
| `401` from Venice | Missing/invalid `VENICE_API_KEY` | Export it in the same shell; `lanomat-agent doctor` |
| `model not found` | The id was retired | `lanomat-agent model setup` (lists live ids) and choose one |
| Chat works, tools fail | The model does not support tool calling | Use the fallback `venice/zai-org-glm-5` |
| `ECONNREFUSED` on a local LLM | Server not running / wrong port | Check `OPENAI_MODEL_BASE_URL`, e.g. `http://127.0.0.1:11434/v1` |
| Prompt leaves the LAN unexpectedly | A cloud model is still selected | `OPENAI_MODEL_*` or `LANOMAT_AGENT_MODEL` to the local model |
| Key appears in logs | A shell `set -x` or a wrapper | Export without tracing; the key never goes to LANoMAT |

The full precedence is on [Models & fuel](/docs/agent/venice). A CLI `--model` always wins; the
saved config is the last stop before the Venice default.

## MCP and tools

| Symptom | Cause | Fix |
|---|---|---|
| `mcp: disconnected` | Server URL/token, or module off | `pi mcp list`, check `/mcp`, `MODULE_MCP=true` |
| `404` on `/mcp` | MCP module disabled | Enable it, or use the REST API |
| `-32601 unknown tool` | Agent build older than the server | Update the agent; `lanomat-agent doctor` |
| `-32602 required content not found` | A required file is not on the LAN | Put it on the file host under the recipe's glob |
| `429` | More than 60 calls/minute | Back off; batch with `plan`, not repeated `list` |
| Certificate error at the venue | Venue wildcard cert not trusted | Install the venue CA; do not disable TLS |

`pi mcp list` connects and prints the state, tools and errors. Inside pi, `/mcp` shows the same and
lets you reconnect. After editing `mcp.json` outside a session, run `/reload`.

## Downloads and installs

| Symptom | Cause | Fix |
|---|---|---|
| Download URL `403` | Tampered or wrong signature | Ask for a fresh URL (`file_download_url`) |
| Download URL `410` | Expired | Request again immediately before use |
| Download stalls at 0 | File host unreachable from this PC | Check `https://files.<lan>.example:8443` reachability |
| Works on Wi-Fi, not Ethernet | Different VLAN / no route to the file host | Put the PC on the LAN segment that reaches the file host |
| `sha256 mismatch` | Corrupt or wrong file | Re-download; check the recipe's expected hash |
| Install asks for the disc | UT2004 patch not applied | Rerun the patch step; verify `System/D3D11Drv.dll` |
| No space left | ISO + install + downloads | Free space; delete the staging copy after verify |

The agent writes only to the recipe target and its downloads cache. A failed step leaves the files it
already verified, so a retry is cheap.

## Avatars

| Symptom | Cause | Fix |
|---|---|---|
| No avatars listed | No `.vrm` matches on the LAN | Put avatars on the file host, or pass local paths |
| An avatar is offered but 404s | The catalogue entry has no LAN file | Choose another; the recipe should not invent URLs |
| Conversion fails | `vrm2ut2004` not built | Build it on the LAN server; copy the packages to the client |
| Zero avatars selected | Valid | The base install does not depend on avatars |

Never let an agent download an avatar from an arbitrary website because a catalogue entry is
missing. The correct behaviour is to list the LAN matches, offer a multi-select, and accept none.

## Offline LAN

The venue may have no internet. What still works:

- `ls ~/.agents/skills` and reading the synced `lanomat-<slug>/SKILL.md` files.
- `lanomat-agent ask` **only** with a local model.
- MCP calls to the LAN server.
- Downloads from the LAN file host.

What stops:

- Venice or any cloud model.
- `skills sync` if the skill is not already local.
- Any step whose recipe explicitly downloads from the internet (UT2004VR, for example).

Run `lanomat-agent skills sync` and download the required content **before** the internet goes away.

The LANoMAT side keeps answering, because it is on the LAN: the dashboard after sign-in still shows
the current LAN, ticket, seat and teams.

| Dark | Light |
|---|---|
| ![Dashboard after sign-in on the LAN: current LAN, ticket, seat A3-1 and teams, dark](/screens/dashboard-dark.png) | ![Dashboard after sign-in on the LAN: current LAN, ticket, seat A3-1 and teams, light](/screens/dashboard-light.png) |
| `DASHBOARD · CURRENT LAN · TICKET · SEAT · TEAMS` | `DASHBOARD · LIGHT` |

## Windows SmartScreen

The agent and the voice client are unsigned. "Windows protected your PC" is expected. Compare the
SHA-256 on the download page, then **More info → Run anyway**. Do not disable SmartScreen globally.

## Collecting a report

```bash
lanomat-agent doctor --json > lanomat-doctor.json
lanomat-agent ask --debug "plan ut2004" 2> agent-debug.log
```

The debug log redacts the token and the model key. Attach both to an issue; do not paste a raw
`config.json` or `models.json` with a literal key.

<div class="callout-live">
  <strong>One question at a time.</strong> If the agent does something surprising, ask it to
  <em>plan only</em> and show the tool calls. Most "agent bugs" are a missing file or a model that
  cannot call tools.
</div>
