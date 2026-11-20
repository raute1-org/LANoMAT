---
title: Tools & resources
description: The MCP tool catalogue, recipe resources, prompts and argument examples.
sidebar_icon: list
---

The MCP surface is deliberately explicit: **24 tools**, **one prompt** (`install-game`) and **one
resource template** (`lanomat://recipes/{slug}/skill`). The table below is the contract — it matches
what the served server registers in `app/Modules/Mcp/Server/LanomatServer.php`. If a tool is not in
this table, the server does not have it.

## Tool catalogue

| Tool | Arguments | Returns | REST equivalent |
|---|---|---|---|
| `lanomat_me` | — | User, role, enabled modules, agent default model | `GET /api/v1/me` |
| `events_list` | `include_finished?` | Events with the caller's registration state | — |
| `event_get` | `slug` | Event, my registration, tournaments, schedule | — |
| `event_register` | `slug`, `ticket_type?` | Registration status | — |
| `teams_list` | `query?` | Teams with member count and my membership | — |
| `tournament_list` | `event_slug` | Tournaments with my entry state | — |
| `tournament_bracket` | `tournament_id` | Tournament, matches, scores, join info | — |
| `tournament_checkin` | `tournament_id` | My entry's check-in status | — |
| `match_result` | `match_id`, `action`, `score1?`, `score2?`, `lock_version?` | Match and report state | — |
| `games_search` | `query?` | Games catalogue (name, slug, team size) | — |
| `files_list` | `event_slug`, `query?` | Files with size, visibility and download URL | `GET /api/v1/files` |
| `file_download_url` | `file_id` | Bearer download URL for one file | `GET /api/v1/files/{file}/download` |
| `servers_list` | `event_slug?` | Permanent or per-event game servers | — |
| `schedule_list` | `event_slug` | Schedule items in order | — |
| `polls_list` | `event_slug` | Polls, options and my vote | — |
| `poll_vote` | `poll_id`, `option_id` | Cast vote | — |
| `catering_menu` | `event_slug` | Open and scheduled food orders with prices | — |
| `lfg_list` | `event_slug` | Active looking-for-group posts | — |
| `lfg_create` | `event_slug`, `title`, `game`, `body?`, `slots_needed?` | The created post | — |
| `recipes_list` | `query?`, `platform?` | Recipe summaries | `GET /api/v1/recipes` |
| `recipe_get` | `slug`, `include_skill?` | Full recipe, requirements, steps, file matches | `GET /api/v1/recipes/{slug}` |
| `recipe_install_plan` | `slug`, `os?`, `event_slug?` | Install plan (pure, no download) | `POST /api/v1/recipes/{slug}/plan` |
| `recipe_avatars` | `slug?`, `query?` | Avatar/content packs with LAN status | `GET /api/v1/recipes/{slug}/avatars` |
| `recipe_contribute` | `slug`, `title`, `summary?`, `description?`, `platforms?`, `aliases?`, `requirements?`, `steps?`, `skill_description?` | Draft recipe | `POST /api/v1/recipes` |

Every tool is read-only except `event_register`, `match_result`, `poll_vote`, `lfg_create` and
`recipe_contribute`; `recipe_contribute` only creates a **draft**. Nothing in the catalogue
publishes, deletes or edits an event.

Tools appear only when their module is enabled (`recipes_*` need `GameRecipes`, `files_*` need
`Files`, `servers_list` needs `GameServers`, and so on); `lanomat_me` reports the enabled set.

## Call examples

```json
{
  "jsonrpc": "2.0",
  "id": 10,
  "method": "tools/call",
  "params": {
    "name": "recipes_list",
    "arguments": { "platform": "windows", "query": "ut2004" }
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
    "arguments": { "slug": "unreal-tournament-2004", "os": "windows" }
  }
}
```

The plan result is a structured object, not prose:

```json
{
  "recipe": { "slug": "unreal-tournament-2004", "title": "Unreal Tournament 2004", "version": "3374", "skill": "ut2004" },
  "os": "windows",
  "target_dir": "C:\\Games\\Unreal Tournament 2004",
  "downloads_dir": "C:\\Games\\Unreal Tournament 2004\\.lanomat-downloads",
  "files": [
    { "requirement": "iso", "label": "UT2004 DVD 1", "status": "matched", "source": "lan", "name": "UT2004-DVD1.iso", "size_bytes": 4500000000 },
    { "requirement": "patch", "label": "OldUnreal UT2004 patch 3374", "status": "missing", "required": true }
  ],
  "downloads": [
    { "requirement": "iso", "name": "UT2004-DVD1.iso", "size_bytes": 4500000000 }
  ],
  "avatars": [
    { "label": "VRM avatar · Ayla", "size_bytes": 18000000, "available_on_lan": true }
  ],
  "missing": ["OldUnreal UT2004 patch 3374"],
  "steps": [
    { "id": "install-base", "title": "Install the base game from Disc 1", "optional": false }
  ]
}
```

(Abbreviated: every entry also carries its ids, hash and fallback URL.)

A missing required file is listed in `missing` by label; the matching `files[]` entry has
`"status": "missing"` and the requirement's glob patterns.

## Resources

Resources are read with `resources/read` and a URI. The server registers exactly one resource
template: the recipe's `SKILL.md`.

| URI template | MIME | Content |
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

A URI for an unpublished or unknown slug returns an error, not an empty document.

<div class="callout-live">
  <strong>Read the skill before the plan.</strong> The skill says how to ask about VR, avatars,
  verification and the known failure modes; `recipe_get` carries the YAML facts.
</div>

## Prompts

The server registers one prompt.

| Prompt | Arguments | What it does |
|---|---|---|
| `install-game` | `game` | Resolves the recipe, reads the skill, plans and asks for confirmation |

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

## Tool naming and exposure

Pi exposes each tool as `mcp__lanomat__<tool>` with the default `codemode` exposure. If you want the
model to see the tools directly, set `"exposure": "direct"` on the server; for a large catalogue,
keep `deferred` and let tool search find the one it needs. The server itself does not care how the
client exposes it.

## Limits

- `recipes_list` returns at most 100 summaries; narrow with `query`.
- `files_list` returns the visible files of one event (no cursor paging).
- `file_download_url` returns a bearer-authenticated URL: fetch it with the same token, not a browser.
- `recipe_contribute` creates a draft and validates it against the schema: 1–20 steps, unknown keys rejected.
- Rate limit: 240 MCP API requests per minute per token and IP, `429` beyond that.
