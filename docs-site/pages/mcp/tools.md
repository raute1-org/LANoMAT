---
title: Tools & resources
description: The 24 MCP tools, the recipe-skill resource, the install-game prompt and argument examples.
sidebar_icon: list
---

LANoMAT exposes **24 tools**, **one resource template** and **one prompt**. The tools mirror the
participant app: events, tournaments, files, the games catalogue, the recipe catalogue and the
everyday LAN surfaces. Tools whose module is off are not registered at all, so `tools/list` is the
truth for a given deployment.

<div class="live">LIVE 17:42 · tools/list · 24 tools · 1 resource · 1 prompt</div>

## Tool catalogue

| Tool | Arguments | Returns |
|---|---|---|
| `lanomat_me` | — | User, role, server version, enabled modules and the agent default model |
| `events_list` | `include_finished?` | Visible events with dates, status and the caller's registration |
| `event_get` | `slug` | One event with tournaments, schedule and the caller's registration |
| `event_register` | `slug`, `ticket_type?` | Registers the token owner (capacity and window rules apply) |
| `teams_list` | `query?` | Cross-event teams with member count and the caller's role |
| `tournament_list` | `event_slug` | Tournaments of an event with format, status and the caller's entry |
| `tournament_bracket` | `tournament_id` | Rounds, entrants, scores, status and join info |
| `tournament_checkin` | `tournament_id` | Checks the caller's own entry in (inside the window only) |
| `match_result` | `match_id`, `action`, `score1?`, `score2?`, `lock_version?` | Reports, confirms, disputes or withdraws a match result |
| `games_search` | `query?` | Tournament game catalogue: name, slug, team size |
| `files_list` | `event_slug`, `query?` | Approved files of one event plus the caller's pending uploads |
| `file_download_url` | `file_id` | Bearer-authenticated download URL for one file |
| `servers_list` | `event_slug?` | Permanent servers, or the servers of one event |
| `schedule_list` | `event_slug` | Ordered schedule with type, time, location and description |
| `polls_list` | `event_slug` | Polls with options and the caller's own vote |
| `poll_vote` | `poll_id`, `option_id` | Casts the caller's vote (one per poll) |
| `catering_menu` | `event_slug` | Open and scheduled food orders with options and prices in cents |
| `lfg_list` | `event_slug` | Active looking-for-group posts |
| `lfg_create` | `event_slug`, `title`, `game`, `body?`, `slots_needed?` | Creates an LFG post for the caller |
| `recipes_list` | `query?`, `platform?` | Published recipe summaries (aliases match too) |
| `recipe_get` | `slug`, `include_skill?` | One recipe with requirements, steps, files and LAN matches |
| `recipe_install_plan` | `slug`, `os?`, `event_slug?` | Ordered install plan with LAN URLs and missing content |
| `recipe_avatars` | `slug?`, `query?` | Selectable avatar/content packs with LAN availability |
| `recipe_contribute` | `slug`, `title`, `summary?`, `description?`, `platforms?`, `aliases?`, `requirements?`, `steps?`, `skill_description?` | A draft recipe for orga review |

Tool names are flat, not prefixed with `lanomat_` (except `lanomat_me`). An MCP client may namespace
them with the server name; the wire name is always the one in this table.

Every tool is read-only except `event_register`, `match_result`, `poll_vote`, `lfg_create` and
`recipe_contribute`. Nothing publishes, deletes or edits an event.

## Call examples

```json
{
  "jsonrpc": "2.0",
  "id": 10,
  "method": "tools/call",
  "params": {
    "name": "recipes_list",
    "arguments": { "query": "ut2004", "platform": "windows" }
  }
}
```

```json
{
  "jsonrpc": "2.0",
  "id": 11,
  "method": "tools/call",
  "params": {
    "name": "recipe_install_plan",
    "arguments": {
      "slug": "ut2004",
      "os": "windows",
      "event_slug": "lan-raute-1"
    }
  }
}
```

`slug` accepts the recipe slug or any alias, so `ut2004`, `ut2k4` and
`unreal-tournament-2004` all resolve to the same recipe.

The plan result is a structured object, not prose:

```json
{
  "recipe": {
    "slug": "unreal-tournament-2004",
    "title": "Unreal Tournament 2004",
    "version": "3374",
    "skill": "ut2004"
  },
  "os": "windows",
  "target_dir": "C:\\Games\\Unreal Tournament 2004",
  "downloads_dir": "C:\\Games\\Unreal Tournament 2004/.lanomat-downloads",
  "steps": [
    {
      "id": "patch-3374",
      "title": "Apply OldUnreal patch 3374 (Windows x64)",
      "os": "windows",
      "optional": false,
      "detail": "…",
      "commands": [{ "shell": "powershell", "run": "…" }],
      "verify": [{ "type": "file_exists", "path": "{{target}}/System/D3D11Drv.dll" }]
    }
  ],
  "files": [
    {
      "recipe_file_id": 12,
      "requirement": "iso",
      "label": "UT2004 DVD 1 (or GOG/Steam install)",
      "status": "matched",
      "required": true,
      "source": "lan",
      "name": "ut2004-dvd1.iso",
      "size_bytes": 4500000000,
      "shared_file_id": 9182,
      "download_url": "https://lan.example/api/v1/files/9182/download"
    }
  ],
  "downloads": ["… the files with status matched or external …"],
  "avatars": [
    {
      "id": "recipe-file-18",
      "label": "VRM avatar · Ayla (agile scout)",
      "available_on_lan": true,
      "download_url": "https://lan.example/api/v1/files/9201/download"
    }
  ],
  "missing": [],
  "generated_at": "2026-11-20T19:42:00+00:00"
}
```

Files that are required but absent land in `missing`; a file with an external `url` has
`status: "external"` and `source: "external"`. The agent must ask before downloading an external
file. A required file that exists neither on the LAN nor at a URL makes the plan incomplete.

## Resources

Resources are read with `resources/read` and a URI template. LANoMAT registers one:

| URI | MIME | Content |
|---|---|---|
| `lanomat://recipes/{slug}/skill` | `text/markdown` | The recipe's `SKILL.md` |

```json
{
  "jsonrpc": "2.0",
  "id": 20,
  "method": "resources/read",
  "params": { "uri": "lanomat://recipes/unreal-tournament-2004/skill" }
}
```

Only **published** recipes are readable; an unknown slug answers an error. The full recipe YAML is
available through `recipe_get`; the resource exists so an agent can pull the skill text without
spending a tool call.

<div class="callout-live">
  <strong>Read the skill before the plan.</strong> The recipe says what to fetch. The skill says how
  to ask about VR, avatars, verification and the known failure modes.
</div>

## Prompts

One prompt packages the flagship conversation:

| Prompt | Arguments | What it does |
|---|---|---|
| `install-game` | `game` | Resolves the recipe from the user's words, reads the skill, plans, offers avatars, asks before external downloads |

```json
{
  "jsonrpc": "2.0",
  "id": 30,
  "method": "prompts/get",
  "params": {
    "name": "install-game",
    "arguments": { "game": "ut2004" }
  }
}
```

## Module gating

| Tool group | Registered when |
|---|---|
| `lanomat_me`, `games_search` | Always |
| `events_list`, `event_get`, `event_register`, `teams_list` | Always (core modules) |
| `tournament_list`, `tournament_bracket`, `tournament_checkin`, `match_result` | Always (core module) |
| `files_list`, `file_download_url` | Files enabled |
| `servers_list` | GameServers enabled |
| `schedule_list` | Schedule enabled |
| `polls_list`, `poll_vote` | Voting enabled |
| `catering_menu` | Catering enabled |
| `lfg_list`, `lfg_create` | Lfg enabled |
| Recipe tools and the skill resource | GameRecipes enabled |

## Limits

- `recipes_list`, `teams_list`, `games_search` and `files_list` return the full visible set; use the
  query argument to narrow, there is no cursor.
- `recipe_contribute` creates a **draft**; an orga reviews it under `/admin` before it is published.
- Rate limit: 240 requests per minute per token and IP, `429` beyond that.
- The token slides on use: every ~5 minutes of activity extends it to 90 days.
