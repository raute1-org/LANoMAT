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
| "Set up my PC for the LAN." | `lanomat_me`, `lanomat_list_recipes`, proposes a short list, asks which game |
| "Install UT2004 for me." | reads `skill://unreal-tournament-2004`, plans, asks about VR and avatars |
| "Which avatars are on the LAN?" | `lanomat_list_recipe_avatars`, presents a multi-select, never invents files |
| "What's missing for the install?" | `lanomat_plan_recipe`, reports the failed role and glob |

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

`lanomat_plan_recipe` is pure. This is the safe way to check whether the LAN has the disc before
promising a friend an install.

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

`lanomat_list_files` searches by name; `lanomat_file_download_url` returns a short-lived signed URL.
The "after I confirm" clause keeps the URL from expiring before it is used.

## Draft a recipe

```text
Draft a LANoMAT recipe for a game called "Trackmania Nations Forever".
Read skill://unreal-tournament-2004 as the template.
Base game required, one optional mod, no avatars yet.
Validate the shape and submit it as a draft.
```

`lanomat_contribute_recipe` creates a draft, not a published recipe. The orga reviews it, runs the
JSON Schema validator and publishes.

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
Agent: [lanomat_list_recipes platform=windows]
       UT2004 (base + optional VR), Ridge Racer Type 4 (browser cabinet), F-Zero X VR…
       UT2004VR needs SteamVR and OldUnreal 3374. Ridge Racer needs a disc and Node 20.
You: UT2004 with VR, no avatars. Plan it first.
Agent: [skill://unreal-tournament-2004] [lanomat_plan_recipe …]
       4.72 GB: ISO 4.50 GB, patch 183 MB, UT2004VR 24 MB. 6 steps, 4 checks.
       Download now?
You: Yes.
Agent: [lanomat_file_download_url …] downloads, verifies, runs the steps, reports the bot match check.
```

<div class="callout-live">
  <strong>Prompts are guardrails.</strong> "Plan first", "use the LAN copy", "zero avatars is valid"
  and "don't invent a URL" are short sentences that prevent the three most common agent mistakes.
</div>
