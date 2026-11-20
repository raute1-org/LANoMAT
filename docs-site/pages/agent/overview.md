---
title: pi agent
description: The LANoMAT agent — pi with the LAN's recipes, files and identity, running on a participant's PC.
sidebar_icon: terminal
---

The LANoMAT agent is a thin, opinionated wrapper around the [pi coding agent](https://pi.dev) that
knows this LAN. It connects to LANoMAT's MCP server, syncs the recipe catalogue as Agent Skills, and
can plan and run a game install from the LAN file host. It is not the voice client: the voice client
lives in `clients/voice/`, the agent in `clients/agent/` (package `@lanomat/agent`).

<div class="live">LIVE 17:42 · agent ready · 2 published skills · LAN file host reachable</div>

## What it is

```text
lanomat-agent
├── login      device pairing against LANoMAT, token stored locally
├── logout     delete the local token, leave pi and skills alone
├── mcp        write the LANoMAT server into ~/.pi/agent/mcp.json
├── skills     sync / prune recipe skills from the catalogue
├── recipes    list recipes, show one, build a plan
├── install    the one-shot installer (plan → download → run → verify)
├── launch     start an installed game
├── open       handle a lanomat:// deep link
├── register   register the lanomat:// scheme with the desktop
├── avatars    download avatar/content packs from the LAN
├── model      Venice or any OpenAI-compatible endpoint; model roles
├── ask        one prompt, with the LANoMAT MCP server attached
└── doctor     check token, MCP, model, tool calling, skills, LAN file host
```

Under the hood it is pi: the same tools, the same MCP client, the same model providers. LANoMAT adds
three things:

1. **A server preset** for `https://lan.example/mcp` with the paired token.
2. **Skills sync** so every published recipe is available as a skill, offline.
3. **A model default** that works on the LAN (Venice), with any pi provider still allowed.

## What it can do

| Ask | It uses |
|---|---|
| "Install UT2004 for me." | `lanomat://recipes/unreal-tournament-2004/skill`, `recipe_install_plan`, LAN files |
| "Which avatars are on the LAN?" | `recipe_avatars` |
| "Plan Ridge Racer, don't download." | `recipe_install_plan` (pure) |
| "Who am I on this LAN?" | `lanomat_me` |
| "Find the UT2004 ISO." | `files_list` + `file_download_url` |
| "What's on tonight?" | `events_list`, `event_get`, `schedule_list` |
| "Draft a recipe for Trackmania." | the UT2004 skill as template, `recipe_contribute` |

## Quickstart

```bash
# 1. Install from source (see Install & login for the container path)
cd clients/agent
npm ci && npm run build && npm link

# 2. Pair this PC with your LANoMAT account
lanomat-agent login --url https://lan.example

# 3. Wire the MCP server into pi and sync the recipe skills
lanomat-agent mcp install
lanomat-agent skills sync

# 4. Set up the model (Venice is the default)
lanomat-agent model setup

# 5. Ask for the thing you actually want
lanomat-agent ask "install ut2004 for me on my pc"
```

The login prints a code and opens the browser; approve the device under `/mcp/connect` while logged
in. The token is stored in `~/.config/lanomat/agent.json` (mode `0600`; `%USERPROFILE%\.config\lanomat\agent.json`
on Windows unless `XDG_CONFIG_HOME` is set), never printed.

## Models and fuel

The default is **Venice AI**, OpenAI-compatible, with
`venice/e2ee-deepseek-v4-1-flash-1017-abliterated` as the LANoMAT model. You can point the agent at
any pi provider, including a local LLM, with the CLI, `LANOMAT_AGENT_MODEL` or the
`OPENAI_MODEL_*` variables. The key stays in the pi process; LANoMAT never receives it.

Read [Models & fuel](/docs/agent/venice) for the precedence, the generic OpenAI-compatible setup and
the privacy note.

## Offline and on the LAN

- **Skills are local.** After `skills sync`, the recipe skills are on disk. Reading them needs no
  network.
- **Files come from the LAN.** The agent resolves files through MCP, then downloads with the bearer
  token from the LAN host. No internet required for the bytes.
- **The model needs a path.** A cloud model needs internet; a local model (`http://host:11434/v1`)
  works fully offline. Without either, the agent can still list skills and files but cannot reason
  about them.
- **Tokens slide.** The token is valid for 90 days and extends on use, so a LAN weekend is fine. If
  `ask` says unauthorized, run `lanomat-agent login` again.

## Safety

The agent plans before it downloads, shows the byte count and the target folder, and asks about
optional content. It writes only to the install target and its avatar/download folders. Avatars are a
multi-select; zero is a valid answer. A contributed recipe is a draft, not a publication.

<div class="callout-live">
  <strong>Not the voice client.</strong> The desktop voice app (push-to-talk, overlay, "Wo ist wer")
  is a separate Tauri app in <code>clients/voice/</code>. The agent is a terminal tool; it shares the
  same account and pairing model, not the UI.
</div>
