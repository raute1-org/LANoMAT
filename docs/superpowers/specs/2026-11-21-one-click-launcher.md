# One-Click Agent-First Launcher — program spec (2026-11-21)

Status: **UT2004 pilot** (scope cut 2026-11-21). 32-agent design swarm, but only the
Unreal Tournament 2004 pilot ships now. Every other recipe and every other game is deferred to the
next session. **Flat only: VR is not surfaced at all** (no UT2004VR, no flat-vs-VR question, no VR
launch target, no VR chat/docs copy).

Working name: **LANoMAT Launcher** (UT2004/Epic-inspired brand world, not Signalpult).

## Pilot scope

- One game: `unreal-tournament-2004`. One mode: flat.
- Preferred install source: a ready-to-copy portable folder archive
  (`ut2004-portable-3374.zip`: patched 3374, x86-64, no Steam, no ISO mount). ISO remains a fallback.
- The existing `ut2k4+keygen.zip` is a nosTEAM **installer EXE**, not a portable folder; a clean
  portable archive is being built from the working install + OldUnreal 3374.
- Everything else (other recipes, macOS if it delays the pilot, sponsorship tiers beyond the pilot
  key, in-game hugs) is backlog unless it blocks the UT2004 pilot.

## Why

LANoMAT already has recipes, a file host, MCP tools, a game page with an install button and a
working UT2004 server. What is missing is the consumer-grade last mile: one download, one window,
one button, no terminal, no Steam requirement, and an agent that can answer "what can I do?" in
the browser and in the launcher.

## Live facts (2026-11-21)

- LANoMAT: `https://lan.raute1.org` (MCP `/mcp`, bearer token, 24 tools; OpenCode-compatible with
  `oauth:false`).
- UT2004 server: `<UT2004_HOST>:7777`, query `7778`, name "Raute1 LAN", DM-CORRUPTION, 16 slots.
- VRM converter/web: `http://<VRM_HOST>:8080` (vrm2ut2004 stack); packages at
  `/redirect/VRM<id>mod.u`.
- Installed locally and available on the server: CoolBanana, ChillPenguin, CoolPoo, Skull.
- Venice inference key is provisioned server-side/client-side (never in git); model
  `deepseek-v4-1-flash-1017-uncensored`, alias `ds41f`.
- Blizzard-style reference app: `/tmp/opencode/wow-forever-lan-countdown` (chat, MCP, Pi agent,
  LAN dashboard). The launcher's install/progress and chat UX must reach that bar.

## Product flow

> **UX status (2026-11-21, implemented — pilot).** The flow below is implemented for the UT2004
> pilot: the start-page chat with its typewriter suggestions and inline game/server/install cards,
> the `/games` rows with install/launch and recently played, the launcher download and one-time
> pairing, and the launcher's own library/install/ready windows with real percentages. The chat,
> `/games` and the launcher render the same read models; the deterministic fallback answers every
> intent when no model is reachable. Normative UI rules live in `docs/design.md`
> ("Start-page chat (the front door) — pilot"); the guide is
> `docs-site/pages/guides/launcher.mdx`. The multi-terminal model and the arcade QR sign-in are
> specified there too: one pairing, token and local install library per terminal, a shared
> catalogue, offline-startable installs, and a per-terminal device list with **online**,
> **aktuelles Spiel** and **zuletzt gesehen** under **Verbundene Geräte** on `/mcp/connect`.
> Still deferred (see *Pilot scope*): every other recipe,
> VR, the deeper browser↔launcher handoff, and the sponsorship slices.

0. **Homepage (`/`) above the fold:** the agent chat and the three very large primary
   tiles — **1) Spiele verwalten** → `/games`, **2) Serverbrowser** → `/servers`,
   **3) Voice-Chats** → `/talk` when live, otherwise the native voice client / download entry.
   A fourth, smaller **`?` Handbuch** button sits beside them and opens the user-facing handbook
   (docs, screenshots, usage around the three activities, food ordering, chat integration, and a
   preloaded live example conversation that shows how the app launches and how the launcher is
   installed). Everything currently on the landing page moves **below the fold**. A huge,
   always-visible **Launcher** button sits at the very top of the sidebar (above the nav), not only
   in the hero. The sidebar otherwise stays as it is. OS buttons: Windows, Linux, macOS. The chat
   greets the visitor, explains LANoMAT, and can already answer "what can I do?" / "install UT2004"
   with inline game, server and install cards.
1. Website `/games` lists every live recipe as a row with **Installieren**, server browser/join, and
   **Fragen** (chat). No launcher installed → the button downloads the launcher.
2. One launcher download (`LANoMAT-Setup.exe` / `.AppImage` / `.dmg`) → first run provisions via a
   one-time install ticket (website session/donation cookie) or one-click browser approval.
3. Launcher shows collected avatars, then a Sims-like install window:
   portable archive download → extract → patch/verify → avatars → verify. ISO is the fallback path
   (download → mount/extract → patch). Per-file/per-step progress, speed/ETA, resume, cancel.
4. Launcher installs OpenCode silently, writes `opencode.json` with Venice provider alias `ds41f`
   and LANoMAT MCP, syncs recipe skills, then opens the default LANoMAT chat: greeting, "you can
   install UT2004", links to game pages and server browsers, inline install buttons.
5. Play flat. Deep links `lanomat://install|launch|play/<slug>`.

### Live vs. experimental catalogue

- **Live build:** only `unreal-tournament-2004` is named on `/`, `/games` and the launcher library.
  Every other recipe stays **WIP**: present in the stack and docs (unnamed/undisclosed under the
  live build), never shown as a broken or coming-soon tile.
- **Experimental mode (dev only):** a toggle (`LAUNCHER_GAME_SCOPE=experimental`, query/env or
  settings) shows the complete recipe state, including unpublished/SMB recipes, missing files,
  validation errors and the real source `smb://<SMB_HOST>/games-recipes/`. It is off by default
  and can be turned off again. Docs weave the WIP catalogue without naming it on the live surface.
- Recipes are the bridge. For ISO-based games there is no update treadmill: download, verify the
  SHA-256 manifest, install, done. Re-verify on repair/launch, not on every start.

### Intent and state

- **Install state per game:** `not_installed → planning → downloading → verifying → installing →
  ready`; side states `paused`, `repair_needed`, `broken`, `offline`. Persisted locally with the
  manifest/hash and target path.
- **Launcher state:** `unpaired → provisioned → library → installing → playing`; `offline` and
  `sponsor_exhausted` are first-class.
- **Chat intents:** `install_game`, `launch_game`, `join_server`, `browse_games`, `show_inventory`,
  `hug_avatar`, `status`, `help`, `smalltalk`. The LLM returns a structured intent + entities;
  deterministic fallback handles every intent when the model/key is unavailable. Cards are rendered
  from tool/API data, never invented.

## Architecture decisions to confirm in the 32-agent wave

- **Launcher shell:** new `clients/launcher` Tauri v2 app, forked from `clients/voice`, shared
  auth/token/settings/transfer/update core, new game UI. Windows-first, unsigned first.
- **Recipe engine:** bundle the existing Node `clients/agent` runner as a sidecar (SEA) or port to
  Rust. Decision based on effort/reliability; progress event contract is language-neutral.
- **Provisioning:** one-time hashed install ticket, short TTL, single use; key delivered by
  `/api/launcher/v1/provision`; never in a shared static file or cookie.
- **Venice sponsorship:** LANoMAT holds an Admin key, mints per-user Inference keys with USD cap,
  period, expiry, description; revoke/rotate; donor wall and usage. Fallback to user key/local model.
- **Pilot install:** portable `ut2004-portable-3374.zip` first (one download, extract, verify,
  done); ISO + patch is the fallback. No Steam, no ISO mount in the happy path, no UT2004VR.
  Converted VRM packages come from the avatar inventory.
- **Avatars:** canonical `avatars` entity + per-user `avatar_inventory_items`; collect via hug in
  the VRM explorer; stable slug, not `game_recipe_file_id`; launcher installs only selected.
- **Chat:** new browser + launcher chat surface backed by OpenCode (or pi as fallback) with LANoMAT
  MCP tools; streaming, persisted conversation, inline game/server/install cards.

## Workstreams (32 slots)

1. Product flow and screen map — **implemented (pilot)**
2. UT2004/Epic brand and UX — **implemented (pilot)**
3. Tauri shell architecture
4. Recipe engine decision
5. Progress event contract
6. OpenCode bootstrap
7. Venice sponsorship backend
8. One-file install ticket
9. ISO-first hosting pipeline
10. Avatar inventory backend
11. VRM explorer + hug UX
12. In-game hug bridge
13. MCP/API extensions
14. Launcher release pipeline
15. Security threat model
16. E2E acceptance and rollout
17. Blizzard→Epic brand transfer (wow-forever reference)
18. Browser chat surface on lan.raute1.org — **implemented (pilot)**: greeting, suggestion typewriter, inline cards
19. Agent-installer skill
20. Venice model/key validation + ds41f config
21. Launcher static prototype (Epic UI)
22. Chat backend/proxy (OpenCode/pi, SSE, tools)
23. Chat UX (greeting, game/server cards, install buttons) — **implemented (pilot)**, see `docs/design.md`
24. Games list rows with install/server actions — **implemented (pilot)**, shared by `/games` and the launcher
25. OpenCode bootstrap script (PowerShell/bash)
26. Install-ticket implementation slice
27. Venice sponsorship implementation slice
28. Avatar inventory implementation slice
29. VRM explorer prototype
30. Launcher release workflow
31. Live server package seed/inventory
32. Integration coordinator / final spec

## Non-negotiables

- No secrets in git. Venice key + MCP tokens client-side, 0600; Admin key server-side only.
- Every new route in `RouteAuthorizationMatrixTest`; every authorization through a Policy.
- German UI copy, English code/docs. Modular monolith and optional-module gating.
- ISO path must work on a fresh Windows PC with no Steam, no Node, no OpenCode, no UT2004.
- The first-LAN MVP must install UT2004 and join `<UT2004_HOST>:7777` end to end.
