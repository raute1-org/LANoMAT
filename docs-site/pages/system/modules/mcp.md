---
title: MCP
description: The streamable HTTP MCP server, device pairing, 24 tools, the recipe-skill resource and the install prompt.
sidebar_icon: bot
---

`Mcp` is the module behind `/mcp`. It exposes LANoMAT to agents over the Model Context Protocol:
streamable HTTP, bearer tokens, device-code pairing, a 24-tool catalogue and recipes as skills.

<div class="live">LIVE 17:42 · MCP session · 24 tools · recipe_install_plan in flight</div>

## Who uses it

- **Agents** (pi, Claude, Cursor, any MCP client) pair once, then read the event, the catalogue and
  the files, and act as the paired user.
- **Teilnehmer** approve a device code in the browser.
- **Orga** sees paired devices under `/mcp/connect` and can revoke a token.

## Key endpoints

| Endpoint | Purpose |
|---|---|
| `POST /api/mcp/device` | Start device pairing; returns `device_code`, `user_code`, `verification_uri`, `expires_in`, `interval` |
| `POST /api/mcp/token` | Exchange an approved device code for a bearer token |
| `POST /mcp` | Streamable HTTP MCP endpoint (bearer) |
| `GET /mcp/connect` | Browser page: approve a code, list and revoke devices |
| `GET /api/v1/…` | Bearer REST for the agent: me, recipes, avatars, files |

## Guards and rules

- The token is a bearer credential; `/mcp` and `/api/v1` never accept a cookie session.
- The device code expires after 10 minutes; the token exchange answers `authorization_pending` until
  approved, `slow_down` when polled too fast.
- A token is scoped to the user who approved it and slides to 90 days on use. Revoking it
  invalidates the session on the next call.
- Tools whose module is disabled are not registered; the rest stay policy-checked.
- Recipe content is served as a `lanomat://recipes/{slug}/skill` resource and through
  `recipe_get`, not as arbitrary filesystem paths.
- The MCP server is optional: off in `core` unless `MODULE_MCP=true`.

## Docs for agents

This module **is** the server. Its catalogue is on [Tools & resources](/docs/mcp/tools);
`lanomat_me` is the first call after pairing.

<div class="callout-live">
  <strong>Read the docs for agents.</strong> The MCP section covers installation snippets, the
  JSON-RPC shape, prompt recipes and the security model in detail.
</div>
