---
title: Assistant
description: The orga-only AI chat that reads tournament and game-server state and proposes changes for approval.
sidebar_icon: sparkles
---

`Assistant` is an orga chat at `/orga/assistant`: an orga writes what they want, the assistant reads
the current state through read tools and proposes changes. **Nothing runs before the orga approves a
preview card**, and every proposal and its outcome is written to `assistant_actions`.

The module is optional and off in `core`. It has no hard dependency: each tool is hidden while the
module it wraps (`Tournaments`, `GameServers`) is off.

## Who uses it

- **Orga** asks for a tournament, a server start, a map change or a console command and approves the
  card.
- **Participants** never see the page (`role:orga` on the whole route group).

## Key screens

- `/orga/assistant` — the chat with the conversation list and the preview cards.
- Filament **Assistent-Protokoll** (`/admin`) — every proposed, executed or rejected action with
  user, tool, arguments and result.

## What it can read

`events_list`, `games_list`, `tournaments_list`, `tournament_get`, `servers_list`,
`server_settings_get`, `server_logs`. The system prompt forbids guessing ids: they come from these
tools.

## What it can propose

| Tool | Effect after approval |
|---|---|
| `tournament_create`, `tournament_update`, `tournament_advance` | Create a tournament, change title, format, team size, entry cap or check-in window, or move its lifecycle step (announce, open enrollment, open check-in, start, back to draft) |
| `server_start_preset` | Start an event server from a game's preset |
| `server_create_permanent` | Create a permanent server |
| `server_settings_update` | Change name, mode, map, workshop id, player cap or password |
| `server_change_map`, `server_restart_round`, `server_power`, `server_console` | Act on a running server |

It cannot delete a server or a tournament. A new server password is typed by the orga on the card,
never asked of the model, and server-log and console output is passed through a secret scrubber
before the model sees it.

## Guards and rules

- The approval card shows the tool's arguments before anything runs; **Ausführen** executes,
  **Ablehnen** changes nothing.
- A new message closes every card that has not run yet, and tool arguments are never taken from the
  client on approval — they are the ones stored when the card was proposed.
- Every tool call is authorized as the logged-in orga and goes through the owning module's action, so
  a change cannot do more than the panel could.
- Results of tools are data, not instructions; the system prompt says so explicitly.
- Chat and decision calls are throttled per orga (`ASSISTANT_THROTTLE_PER_MINUTE`, 20 per minute) and
  a prompt is bounded to `ASSISTANT_MAX_STEPS` model/tool round trips (12).
- The provider is configuration: `ASSISTANT_PROVIDER` (`anthropic` by default, `openai`, or `ollama`
  for the offline case) with `ASSISTANT_MODEL` / `ASSISTANT_OLLAMA_MODEL`. The Preflight check
  `assistant_provider` reports whether the provider answers.

<details>
<summary>Full runbook</summary>

Providers, keys and the failure modes are in
[docs/infra/assistant.md](https://github.com/raute1-org/LANoMAT/blob/main/docs/infra/assistant.md).

</details>

<div class="callout-live">
  <strong>The human stays the authority.</strong> The assistant proposes; the orga runs. Nothing it
  suggests reaches a server or a bracket without the card.
</div>
