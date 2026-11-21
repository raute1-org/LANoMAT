---
title: Tools & resources
description: The MCP tool catalogue, recipe resources, prompts and argument examples.
sidebar_icon: list
---

The MCP surface is deliberately explicit: **29 tools**, **two prompts** (`install-game`, `import-menu`) and **two
resources** (the template `lanomat://recipes/{slug}/skill` and `lanomat://skills/import-menu`). The table below is the contract — it matches
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
| `catering_menu` | `event_slug` | Open and closed food orders with prices (drafts for orga/admin only) | — |
| `restaurants_list` | — | Orga/admin: restaurants with slug, dish count, rounds and contact data | — |
| `catering_menu_get` | `restaurant` (or deprecated `event_slug` + `order_id`) | Orga/admin: the restaurant's menu in the import shape, with ordered counts per dish | — |
| `catering_menu_preview` | `mode`, `restaurant?`, `name?`, `items` | Orga/admin dry run: errors, warnings, resulting menu, changes; writes nothing | — |
| `catering_menu_import` | same as preview | Orga/admin: creates a restaurant or replaces/merges its menu, atomically | — |
| `catering_round_create` | `event_slug`, `restaurant`, `opens_at`, `closes_at`, `payment_method`, `title?`, `payment_link?` | Orga/admin: creates a food round as a **draft**; never opens it | — |
| `lfg_list` | `event_slug` | Active looking-for-group posts | — |
| `lfg_create` | `event_slug`, `title`, `game`, `body?`, `slots_needed?` | The created post | — |
| `recipes_list` | `query?`, `platform?` | Recipe summaries | `GET /api/v1/recipes` |
| `recipe_get` | `slug`, `include_skill?` | Full recipe, requirements, steps, file matches | `GET /api/v1/recipes/{slug}` |
| `recipe_install_plan` | `slug`, `os?`, `event_slug?` | Install plan (pure, no download) | `POST /api/v1/recipes/{slug}/plan` |
| `recipe_avatars` | `slug?`, `query?` | Avatar/content packs with LAN status | `GET /api/v1/recipes/{slug}/avatars` |
| `recipe_contribute` | `slug`, `title`, `summary?`, `description?`, `platforms?`, `aliases?`, `requirements?`, `steps?`, `skill_description?` | Draft recipe | `POST /api/v1/recipes` |

Every tool is read-only except `event_register`, `match_result`, `poll_vote`, `lfg_create`,
`recipe_contribute` and `catering_menu_import`; `recipe_contribute` and `catering_menu_import` only create or
change **drafts** (a round is never opened by the tool). Nothing in the catalogue
publishes, deletes or edits an event.

A tool result carries the same values the participant app renders — event state, ticket, seat and
teams:

| Dark | Light |
|---|---|
| ![Participant dashboard with event, ticket and seat, dark](/screens/dashboard-dark.png) | ![Participant dashboard with event, ticket and seat, light](/screens/dashboard-light.png) |
| `DASHBOARD · EVENT · TICKET · SEAT` | `DASHBOARD · LIGHT` |

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

An agent prints that plan in its own terminal, downloads included:

| Dark | Light |
|---|---|
| ![Agent terminal printing the recipe install plan with files and steps, dark](/screens/agent-install-terminal-dark.png) | ![Agent terminal printing the recipe install plan with files and steps, light](/screens/agent-install-terminal-light.png) |
| `AGENT · RECIPE_INSTALL_PLAN · DRY RUN` | `AGENT · LIGHT` |

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

The draft waits in the Filament admin until an orga reviews it:

| Dark | Light |
|---|---|
| ![Filament admin dashboard with the Preflight check, dark](/screens/admin-dark.png) | ![Filament admin dashboard with the Preflight check, light](/screens/admin-light.png) |
| `ADMIN · RECIPE DRAFT · ORGA REVIEW` | `ADMIN · LIGHT` |

## Menu import (orga/admin)

The agent reads the menu itself (PDF, web page, text, photo) and passes **structured data**. LANoMAT never
fetches a URL or parses a document for this, and treats every string as plain data (control characters are
flattened, markup is stored as text, nothing is executed). The data model is flat: a dish has a `key`, a `name`,
a price in integer cents and an optional short `description`. There are no categories or allergen flags; the
preview reports such fields as ignored, and qualifiers such as "vegetarisch" belong into the name. **Photos are
not imported through MCP**: they are uploaded per dish in the admin panel or assigned in bulk with the artisan
command `catering:import-dish-media`.

Item fields: `name` (required, max 255), exactly one of `price_cents` (integer, 0 to 100000) or `price_eur`
(text with at most two decimals, `"8,50"`), optional `key` (`a-z0-9_-`, max 64, derived from the name when
omitted), optional `description` (plain text, max 600 characters; a longer text is shortened with a warning).
At most 300 dishes. The three tools share their arguments:

```json
{
  "name": "catering_menu_preview",
  "arguments": {
    "mode": "create",
    "name": "Pizzeria Beispiel",
    "items": [
      { "name": "Pizza Margherita", "price_eur": "8,50" },
      { "name": "Pizza Salami", "price_cents": 950 },
      { "name": "Cola 0,5l", "price_eur": "2,50" }
    ]
  }
}
```

```json
{
  "valid": true, "applied": false, "mode": "create", "created": false, "unchanged": false,
  "errors": [], "warnings": [],
  "changes": { "added": 3, "kept": 0, "removed": 0, "changed": 0 },
  "dish_count": 3,
  "restaurant": { "id": null, "slug": null, "name": "Pizzeria Beispiel" },
  "menu": [
    { "key": "pizza-margherita", "name": "Pizza Margherita", "price_cents": 850 },
    { "key": "pizza-salami", "name": "Pizza Salami", "price_cents": 950 },
    { "key": "cola-0-5l", "name": "Cola 0,5l", "price_cents": 250 }
  ]
}
```

`catering_menu_import` takes the same arguments and returns the same shape with `applied: true` and the restaurant id and slug.

| `mode` | Behaviour |
|---|---|
| `create` | New restaurant with `name` (required) and this menu. Importing the same name and menu again returns the existing restaurant (`unchanged: true`); the same name with another menu is refused. |
| `replace` | Swaps the menu of `restaurant`; sets descriptions, and a dish that keeps its key keeps its photo. Dishes that were already ordered in any round must stay in the menu with the same key, otherwise the import is refused with the list of missing keys (take the keys from `catering_menu_get`). |
| `merge` | Appends dishes whose name is new; existing dishes and prices stay untouched (a differing price is a warning). The one exception: a description is filled in when the existing dish has none (a differing description is a warning). |

Menus belong to the **restaurant** (event-spanning), not to a round. A round is created separately with
`catering_round_create` (always a draft; opening it is a human step). `event_slug` + `order_id` still work on
get/preview/import as a **deprecated** way to address the restaurant of that round.

`catering_menu_get` returns `items` in exactly this shape (with the stored keys and descriptions; `has_image: true`
marks a dish with a photo and is ignored on import), so get, edit, import is a round trip. Only orga and admin accounts may call the three tools (`RestaurantPolicy`); the write is one
transaction with the restaurant row locked, and an audit log line (`catering.menu_import`: user, restaurant, mode,
dish count, no dish text) is written.
