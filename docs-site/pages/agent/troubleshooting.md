---
title: Agent troubleshooting
description: Pairing, model, tool calls, downloads, avatars and offline behaviour — the common failures.
sidebar_icon: wrench
---

Start with the doctor. It checks the things that actually break: config, server, MCP handshake, pi,
skills, the key, `models.json`, the model catalogue and a real tool call.

```bash
lanomat-agent doctor
```

```text
✔ Config: /home/rene/.config/lanomat/agent.json (https://lan.example, user rene)
✔ Server: LANoMAT v1.4.2 at https://lan.example (participant)
✔ MCP endpoint: 24 MCP tool(s) available
✔ pi: binary not on PATH; the bundled SDK is used
✔ Skills: 2 synced recipe skill(s) in /home/rene/.agents/skills
✔ smbclient: /usr/bin/smbclient
✔ Venice key: set via VENICE_API_KEY
✔ models.json: provider "venice" configured at /home/rene/.pi/agent/models.json
✔ Model catalog: 31 text model(s) reachable
✔ Tool calls: tool calls work (venice/e2ee-deepseek-v4-1-flash-1017-abliterated)

Model: venice/e2ee-deepseek-v4-1-flash-1017-abliterated (source: config)
Roles:
  chat     venice/e2ee-deepseek-v4-1-flash-1017-abliterated  [config]
  decision (unset)  [null]
  stt      (unset)  [null]
  tts      (unset)  [null]

Doctor: OK
```

`--json` prints the same report as JSON. The check names are stable; the details are not.

## Pairing and token

| Symptom | Cause | Fix |
|---|---|---|
| `401 unauthorized` | Token expired or revoked | `lanomat-agent login --url https://lan.example` |
| `authorization_pending` forever | Code not approved in the browser | Open `/mcp/connect`, type the code, compare, approve |
| Browser opens the wrong host | `--url` differs from the address you use | Pass the exact origin you open in the browser |
| Works on the host, not on a laptop | Different network / DNS | Use the venue DNS name, not `localhost` |
| `The device code expired` | Took longer than 10 minutes | Start `login` again |
| `The login was denied` | Someone pressed deny | Start `login` again and approve the new code |

The token is scoped to the user. If a participant token cannot read an orga draft recipe, that is
correct, not a bug.

## Model and fuel

| Symptom | Cause | Fix |
|---|---|---|
| `401` from Venice | Missing/invalid `VENICE_API_KEY` | Export it in the same shell; `lanomat-agent doctor` |
| `model not found` | The id was retired | Read `GET /models`; `lanomat-agent model setup --model …` |
| Chat works, tools fail | The model does not support tool calling | Use the fallback `venice/zai-org-glm-5` |
| `ECONNREFUSED` on a local LLM | Server not running / wrong port | Check `OPENAI_MODEL_BASE_URL`, e.g. `http://127.0.0.1:11434/v1` |
| Prompt leaves the LAN unexpectedly | A cloud model is still selected | Set `OPENAI_MODEL_*` or `LANOMAT_AGENT_MODEL` to the local model |
| Key appears in logs | A shell `set -x` or a wrapper | Export without tracing; the key never goes to LANoMAT |

The full precedence is on [Models & fuel](/docs/agent/venice). A CLI `--model` always wins; the
saved config is the last stop before the Venice default.

## MCP and tools

| Symptom | Cause | Fix |
|---|---|---|
| `MCP handshake failed` | Server URL/token, or module off | `lanomat-agent doctor`, check `/mcp`, `MODULE_MCP=true` |
| `404` on `/mcp` | MCP module disabled | Enable it, or use the REST API |
| Tool missing from `tools/list` | Its module is disabled | Enable the module, or use a tool that does not need it |
| A tool says `Not found.` | Wrong event slug, id or foreign object | Re-read the id from the list call |
| `429` | More than 240 requests/minute | Back off; plan once instead of repeated list calls |
| Certificate error at the venue | Venue certificate not trusted | Install the venue CA; do not disable TLS |

`pi mcp list` connects and prints the state, tools and errors. Inside pi, `/mcp` shows the same and
lets you reconnect. After editing `mcp.json` outside a session, run `/reload`.

## Downloads and installs

| Symptom | Cause | Fix |
|---|---|---|
| Download `401` | Missing/expired bearer token | `lanomat-agent login`; the agent sends the token automatically |
| Download `403` | Policy refuses the file for this user | Check the file's visibility; ask the uploader/orga |
| Download stalls at 0 | File host unreachable from this PC | Check `https://files.<lan>.example:8443` reachability |
| Works on Wi-Fi, not Ethernet | Different VLAN / no route to the file host | Put the PC on the LAN segment that reaches the file host |
| `sha256 mismatch` | Corrupt or wrong file | Re-download; check the recipe's expected hash |
| `Missing required files` | LAN copy absent and no external URL | Put the file on the host, or `--allow-external` after asking |
| Install asks for the disc | UT2004 patch not applied | Rerun the patch step; verify `System/D3D11Drv.dll` |
| No space left | ISO + install + downloads | Free space; delete `<target>/.lanomat-downloads` after verify |

The agent writes only to the recipe target and its avatar/download folders. `--dry-run` shows the
plan and commands without touching either. A failed step leaves the files it already verified, so a
retry is cheap.

## Avatars

| Symptom | Cause | Fix |
|---|---|---|
| No avatars listed | No matching avatar/content entries on the LAN | Put `.vrm` files on the file host, or use `--avatar` with a known id |
| An avatar is offered but has no URL | The catalogue entry has no LAN match | Choose another; the recipe should not invent URLs |
| Conversion fails | `vrm2ut2004` not built | Build it on the LAN server; copy the packages to the client |
| Zero avatars selected | Valid | The base install does not depend on avatars |

`lanomat-agent avatars --recipe <slug>` lists and downloads packs outside a full install. Never let
an agent download an avatar from an arbitrary website because a catalogue entry is missing. The
correct behaviour is to list the LAN matches, offer a multi-select, and accept none.

## Offline LAN

The venue may have no internet. What still works:

- reading synced skills under `~/.agents/skills/lanomat-<slug>/`;
- `lanomat-agent ask` **only** with a local model;
- MCP calls to the LAN server;
- downloads from the LAN file host.

What stops:

- Venice or any cloud model;
- `skills sync` if the catalogue is not already local;
- any step whose recipe explicitly downloads from the internet (UT2004VR, for example) unless
  `--allow-external` is passed.

Run `lanomat-agent skills sync` and download the required content **before** the internet goes away.

## Windows

The agent and the voice client are unsigned. Windows SmartScreen may warn on a downloaded build; a
source checkout via npm is not marked. Compare the SHA-256 against the download page before running
an installer, then **More info → Run anyway**. Do not disable SmartScreen globally.

## Collecting a report

```bash
lanomat-agent doctor --json > lanomat-doctor.json
lanomat-agent ask --print-command "plan ut2004" > agent-command.txt
```

The doctor report redacts the token and never includes the key. Attach both to an issue; do not paste
a raw `agent.json`, `models.json` or `venice.env`.

<div class="callout-live">
  <strong>One question at a time.</strong> If the agent does something surprising, ask it to
  <em>plan only</em> and show the tool calls. Most "agent bugs" are a missing file or a model that
  cannot call tools.
</div>
