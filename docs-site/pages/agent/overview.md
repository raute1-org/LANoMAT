---
title: pi agent
description: The LANoMAT agent — pi with the LAN's recipes, files and identity, running on a participant's PC.
sidebar_icon: terminal
---

The LANoMAT agent is a thin, opinionated build of the [pi coding agent](https://pi.dev) that knows
this LAN. It connects to LANoMAT's MCP server, syncs the recipe catalogue as Agent Skills, and can
plan and run a game install from the LAN file host. It is not the voice client: the voice client
lives in `clients/voice/`, the agent in `clients/agent/`.

<div class="live">LIVE 17:42 · agent ready · 9 skills · LAN file host reachable</div>

## What it is

```text
lanomat-agent
├── login      device pairing against LANoMAT, token stored locally
├── ask        one prompt, with the LANoMAT MCP server attached
├── skills     sync / list recipe skills from the catalogue
├── model      choose the model provider, including Venice
└── doctor     check token, MCP, tool calling, disk, LAN file host
```

Under the hood it is pi: the same tools, the same MCP client, the same model providers. LANoMAT adds
three things:

1. **A server preset** for `https://lan.example/mcp` with the paired token.
2. **Skills sync** so every published recipe is available as a skill, offline.
3. **A model default** that works on the LAN (Venice), with any pi provider still allowed.

## What it can do

| Ask | It uses |
|---|---|
| "Install UT2004 for me." | `lanomat://recipes/unreal-tournament-2004/skill`, `recipe_install_plan`, bearer-authenticated download URLs |
| "Which avatars are on the LAN?" | `recipe_avatars` |
| "Plan Ridge Racer, don't download." | `recipe_install_plan` (pure) |
| "Who am I on this LAN?" | `lanomat_me` |
| "Find the UT2004 ISO." | `files_list` |
| "Draft a recipe for Trackmania." | `lanomat://recipes/{slug}/skill` as template, `recipe_contribute` |

It does not register you for a tournament, claim a seat, vote or change an event. Those remain
human actions in the app.

## Quickstart

```bash
# 1. Install (see Install & login for the platform details)
curl -fsSL https://lan.example/install-agent.sh | sh

# 2. Pair this PC with your LANoMAT account
lanomat-agent login --server https://lan.example

# 3. Set up the model (Venice is the default)
lanomat-agent model setup

# 4. Sync the recipe skills
lanomat-agent skills sync

# 5. Ask for the thing you actually want
lanomat-agent ask "install ut2004 for me on my pc"
```

The login prints a code and opens the browser; approve the device while logged in. The token is
stored in the agent's config (`~/.config/lanomat-agent/config.json` on Linux,
`%APPDATA%\lanomat-agent\config.json` on Windows) with user-only permissions.

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
- **Files come from the LAN.** The agent resolves files through MCP, then downloads from the file
  host over the venue network. No internet required for the bytes.
- **The model needs a path.** A cloud model needs internet; a local model (`http://host:11434/v1`)
  works fully offline. Without either, the agent can still list skills and files but cannot reason
  about them.
- **Tokens expire.** A 90-day token (sliding on use) covers a LAN weekend and the run-up to it. If `ask` says unauthorized, run
  `lanomat-agent login` again.

## Safety

The agent plans before it downloads, shows the byte count and the target folder, and asks about
optional content. It writes only to the install target and its downloads folder. Avatars are a
multi-select; zero is a valid answer. A contributed recipe is a draft, not a publication.

<div class="callout-live">
  <strong>Not the voice client.</strong> The desktop voice app (push-to-talk, overlay, "Wo ist wer")
  is a separate Tauri app in <code>clients/voice/</code>. The agent is a terminal tool; it shares the
  same account and token model, not the UI.
</div>
