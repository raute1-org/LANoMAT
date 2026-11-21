---
title: Vibecoding recipes
description: Prompt recipes that make an agent useful at the LAN — install, plan, pick avatars, contribute.
sidebar_icon: sparkles
---

"Vibecoding" here means something practical: you say what you want, the agent reads the right skill,
plans, asks the one question that matters and then acts. These are prompt recipes that work with the
tool catalogue — no imaginary tools, no magic.

<div class="live">LIVE 17:42 · agent session · 2 installs planned · 1 running</div>

## The four moves

| You say | The agent does |
|---|---|
| "Set up my PC for the LAN." | `lanomat_me`, `recipes_list`, proposes a short list, asks which game |
| "Install UT2004 for me." | reads `lanomat://recipes/unreal-tournament-2004/skill`, plans with `recipe_install_plan`, asks about VR and avatars |
| "Which avatars are on the LAN?" | `recipe_avatars`, presents a multi-select, never invents files |
| "What's missing for the install?" | `recipe_install_plan`, reports the failed role and glob |

| Dark | Light |
|---|---|
| ![MCP docs page with the endpoint, a pairing JSON response and the page links, dark](/screens/docs-mcp-dark.png) | ![MCP docs page with the endpoint, a pairing JSON response and the page links, light](/screens/docs-mcp-light.png) |
| `MCP · DARK` — endpoint, pairing call and the screenshot pairs; the page behind every prompt | `MCP · LIGHT` |

<div class="callout-live">
  <strong>Live example.</strong> The operator runs the deployed UT2004VR/VRM instance for this recipe
  at <a href="https://ut.raute1.org/">ut.raute1.org</a> — a live counterpart to the prompts below.
</div>

## Install a game

```text
Install UT2004 for me on this PC. Base game and patch only, no VR.
Use the LAN copy, not the internet. Tell me the download size before you start.
```

Why it works: the recipe marks the base and patch as required, VR as optional, and the agent has to
report the plan's byte count before moving anything.

With an explicit avatar choice:

```text
Install UT2004 with the VR mod and the Ayla and Brutus avatars.
If those avatars are not on the LAN, show me what is and let me pick.
```

The second sentence matters: it forbids inventing a download URL for a catalogue entry that has
none.

## Games library prompts

The `/games` page speaks the same vocabulary; these prompts are what people actually type when they
are standing in front of it.

```text
Install CS 1.6 for me. It is a draft recipe on the server — if it is not published yet,
tell me and use the UT2004 recipe as the fallback.
```

```text
Join the UT2004 server. Find the live server, print the join string, and tell me the map
and how many players are on it.
```

```text
Announce a round of UT2004 for tonight. Use the game page's Runde ankündigen, then give me
the voice link for /talk.
```

The agent should resolve the recipe, read the skill and show the plan; for the server prompt it
reads the live server read-model and prints the same join string the game page shows; for the
announcement it opens the game page flow rather than inventing an LFG post. See
[Games library](/docs/system/games-library) for the exact buttons.

## Plan without downloading

```text
Plan the UT2004 install for Windows and show me every required and optional file with its size.
Do not download anything yet.
```

`recipe_install_plan` is pure. This is the safe way to check whether the LAN has the disc before
promising a friend an install. `recipe_get` says whether the native launcher can install the recipe
(`launcher_supported`) and whether it is a portable archive (`portable`), so the agent plans an
install only where one exists and opens `play_url` for a browser game.

| Dark | Light |
|---|---|
| ![Terminal window running lanomat-agent install --dry-run with planned files, steps and avatars, dark](/screens/agent-install-terminal-dark.png) | ![Terminal window running lanomat-agent install --dry-run with planned files, steps and avatars, light](/screens/agent-install-terminal-light.png) |
| `lanomat-agent install --dry-run · DARK` — files by role, steps, avatars, not a byte fetched | `lanomat-agent install --dry-run · LIGHT` |

The transcript is what a good prompt should produce: `[LAN]` and `[EXTERNAL]` tags, the byte total,
four checks, and the avatars offered as a multi-select instead of a yes/no chain.

## Pick avatars

```text
List every VRM avatar on the LAN for the UT2004 recipe, with sizes.
Ask me for a multi-select, not yes/no questions. Zero is a valid answer.
```

The recipe skill says avatars are multi-select; the prompt makes sure the model does not turn it
into a chain of confirmations.

## Find content

```text
Find the UT2004 ISO on the LAN file host. Show the file name, size and upload date.
Give me a download URL only after I confirm.
```

`files_list` searches by name; `file_download_url` returns a bearer-authenticated download URL.
The "after I confirm" clause keeps the agent from fetching before the human has seen the size.

## Draft a recipe

```text
Draft a LANoMAT recipe for a game called "Trackmania Nations Forever".
Read lanomat://recipes/unreal-tournament-2004/skill as the template.
Base game required, one optional mod, no avatars yet.
Validate the shape and submit it as a draft.
```

`recipe_contribute` creates a draft, not a published recipe. Say `portable: true` when the required
base is a ready-to-run archive that only needs unpacking, and pass `launch_targets` so the launcher
can start it; `play_url` marks a browser game that needs no install. A `requires[]` entry with
`shared_file_id` pins a LAN file you may download (your own upload or an approved file of a public
event). The answer carries the draft's `status`, `launcher_supported` and its `pull_request` — null
for anyone but the contributor. The orga reviews it, runs the JSON Schema validator and publishes.

One participant may hold `RECIPES_MAX_DRAFTS_PER_USER` drafts waiting for review at a time (20 by
default; the orga is exempt). If the orga enabled recipe pull requests, the draft's text is proposed
on the publish by default (`RECIPES_PR_TRIGGER=publish`); for that, the `SKILL.md` must be 60–150
lines and a draft must have 3–6 steps plus the sentence that it needs verification on the LAN —
otherwise the proposal is **Nicht gesendet** with the exact reasons. The tool has no
`skill_markdown`; when the rendered skill is too short, the orga completes it in the panel.

| Dark | Light |
|---|---|
| ![Filament admin dashboard with the Preflight tiles and the orga sidebar, dark](/screens/admin-dark.png) | ![Filament admin dashboard with the Preflight tiles and the orga sidebar, light](/screens/admin-light.png) |
| `ADMIN · DARK` — the panel where a submitted draft recipe is reviewed and published | `ADMIN · LIGHT` |

## Import a menu from a PDF or web page

For orga and admin accounts. The agent reads the menu itself; LANoMAT receives structured data only.

```text
Here is the menu of the pizza place as a PDF (karte.pdf). Create the restaurant
"Pizzeria Beispiel" with it, then a food round for event lan-herbst-2026,
deadline Saturday 18:00, cash payment.
Preview first, show me the warnings and the result, and only import after I say so.
Do not guess prices or invent dishes; ask me when a line is unclear.
```

The agent follows the `import-menu` prompt (or reads `lanomat://skills/import-menu`): it calls
`restaurants_list`, `catering_menu_preview`, shows errors and warnings (duplicates, price 0, unusually high
prices, ignored fields), then `catering_menu_import` into the restaurant. The round is a separate step
(`catering_round_create`) and is created as a **draft**; opening it for orders stays a human step in the admin
panel. To fix a price or add a dish later, ask for `catering_menu_get` followed by `replace` (ordered dishes must
keep their keys) or `merge`.

## Troubleshoot

```text
The UT2004 install says "Please insert the UT2004 CD" after the patch.
Read the recipe skill and tell me the next check, in one step.
```

A good agent quotes the skill's troubleshooting entry, not a generic web answer: the patch was
applied to the wrong folder, or the game was started from the old 32-bit shortcut.

## A full session

```text
You: I have a headset and 30 minutes. What can I install?
Agent: [recipes_list platform=windows]
       UT2004 (base + optional VR), Ridge Racer Type 4 (browser cabinet), F-Zero X VR…
       UT2004VR needs SteamVR and OldUnreal 3374. Ridge Racer needs a disc and Node 20.
You: UT2004 with VR, no avatars. Plan it first.
Agent: [lanomat://recipes/unreal-tournament-2004/skill] [recipe_install_plan …]
       4.72 GB: ISO 4.50 GB, patch 183 MB, UT2004VR 24 MB. 6 steps, 4 checks.
       Download now?
You: Yes.
Agent: [file_download_url …] downloads, verifies, runs the steps, reports the bot match check.
```

<div class="callout-live">
  <strong>Prompts are guardrails.</strong> "Plan first", "use the LAN copy", "zero avatars is valid"
  and "don't invent a URL" are short sentences that prevent the three most common agent mistakes.
</div>
