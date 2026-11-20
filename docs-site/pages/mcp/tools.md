---
title: Tools & resources
description: The MCP tool catalogue, recipe resources, prompts and argument examples.
sidebar_icon: list
---

The MCP surface is intentionally small. Ten tools cover identity, the recipe catalogue, planning,
files and a contribution draft; two resource templates expose recipes and their skills; four prompts
package the common conversations.

## Tool catalogue

| Tool | Arguments | Returns | REST equivalent |
|---|---|---|---|
| `lanomat_me` | — | User, role, current event | `GET /api/v1/me` |
| `lanomat_list_recipes` | `platform?`, `q?` | Recipe summaries | `GET /api/v1/recipes` |
| `lanomat_get_recipe` | `slug` | Full recipe (YAML as JSON) | `GET /api/v1/recipes/{slug}` |
| `lanomat_get_recipe_skill` | `slug` | `SKILL.md` as Markdown | `GET /api/v1/recipes/{slug}/skill` |
| `lanomat_plan_recipe` | `slug`, `platform`, `optional?`, `avatars?` | Install plan | `POST /api/v1/recipes/{slug}/plan` |
| `lanomat_list_recipe_files` | `slug` | Files with size/hash/role | `GET /api/v1/recipes/{slug}/files` |
| `lanomat_list_recipe_avatars` | `slug` | Avatar options for multi-select | `GET /api/v1/recipes/{slug}/avatars` |
| `lanomat_list_files` | `q?`, `type?` | LAN file host entries | `GET /api/v1/files` |
| `lanomat_file_download_url` | `file` | Short-lived signed URL | `GET /api/v1/files/{file}/download` |
| `lanomat_contribute_recipe` | `recipe` (object) | Draft recipe with id | `POST /api/v1/recipes` |

Every tool is read-only except `lanomat_contribute_recipe`, which creates a **draft**. Nothing in the
catalogue publishes, deletes or edits an event.

## Call examples

```json
{
  "jsonrpc": "2.0",
  "id": 10,
  "method": "tools/call",
  "params": {
    "name": "lanomat_list_recipes",
    "arguments": { "platform": "windows", "q": "tournament" }
  }
}
```

```json
{
  "jsonrpc": "2.0",
  "id": 11,
  "method": "tools/call",
  "params": {
    "name": "lanomat_plan_recipe",
    "arguments": {
      "slug": "unreal-tournament-2004",
      "platform": "windows",
      "optional": ["ut2004vr"],
      "avatars": ["ayla", "brutus"]
    }
  }
}
```

The plan result is a structured object, not prose:

```json
{
  "recipe": "unreal-tournament-2004",
  "platform": "windows",
  "target": "C:\\Games\\UT2004",
  "required": [
    {"role": "iso", "file": "ut2004-dvd1.iso", "size_bytes": 4500000000},
    {"role": "patch", "file": "OldUnreal-UT2004Patch3374-Windows.zip", "size_bytes": 183000000}
  ],
  "optional": [
    {"role": "mod", "file": "UT2004VR.zip", "size_bytes": 24000000, "selected": true}
  ],
  "avatars": [
    {"slug": "ayla", "file": "ayla.vrm", "size_bytes": 18000000},
    {"slug": "brutus", "file": "brutus.vrm", "size_bytes": 21000000}
  ],
  "bytes": 4722130000,
  "steps": 6,
  "verification": 4
}
```

A missing required file is an error with the role and the glob that failed:

```json
{
  "error": {
    "code": -32602,
    "message": "required content not found: iso (ut2004*.iso)"
  }
}
```

## Resources

Resources are read with `resources/read` and a URI. They are the stable way to hand a full document
to the model without stuffing it into a tool result.

| URI | MIME | Content |
|---|---|---|
| `recipe://<slug>` | `application/yaml` | The recipe's `recipe.yaml` |
| `skill://<slug>` | `text/markdown` | The recipe's `SKILL.md` |

```json
{
  "jsonrpc": "2.0",
  "id": 20,
  "method": "resources/read",
  "params": { "uri": "skill://unreal-tournament-2004" }
}
```

`resources/list` returns the published recipes; a draft is visible only to orga. A resource URI for
an unknown slug is `-32602`, not an empty document.

<div class="callout-live">
  <strong>Read the skill before the plan.</strong> The recipe resource says what to fetch. The skill
  resource says how to ask about VR, avatars, verification and the known failure modes.
</div>

## Prompts

Prompts are parameterised starting points a client can offer in its UI.

| Prompt | Arguments | What it does |
|---|---|---|
| `install_game` | `game`, `platform`, `avatars?` | Resolves the recipe, reads the skill, plans and asks for confirmation |
| `pick_avatars` | `recipe` | Lists LAN avatar matches and asks for a multi-select |
| `contribute_recipe` | `slug`, `notes?` | Drafts `recipe.yaml` + `SKILL.md` and validates the shape |
| `troubleshoot_install` | `recipe`, `error` | Reads the skill's troubleshooting section and proposes the next check |

```json
{
  "jsonrpc": "2.0",
  "id": 30,
  "method": "prompts/get",
  "params": {
    "name": "install_game",
    "arguments": {
      "game": "ut2004",
      "platform": "windows"
    }
  }
}
```

## Tool naming and exposure

Pi exposes each tool as `mcp__lanomat__<tool>` with the default `codemode` exposure. If you want the
model to see the tools directly, set `"exposure": "direct"` on the server; for a large catalogue,
keep `deferred` and let tool search find the one it needs. The server itself does not care how the
client exposes it.

## Limits

- `lanomat_list_recipes` returns at most 100 summaries; use `q` to narrow.
- `lanomat_list_files` pages with `cursor`; the MCP result includes `nextCursor`.
- `lanomat_file_download_url` returns a URL valid for a few minutes; fetch it immediately.
- `lanomat_contribute_recipe` accepts at most 20 steps and 40 requirements, matching the schema.
- Rate limit: 60 tool calls per minute per token, `429` with `-32029` beyond that.
