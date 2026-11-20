---
title: MCP
description: The streamable HTTP MCP server, device pairing, tools, resources and prompts.
sidebar_icon: bot
---

`Mcp` is the module behind `/mcp`. It exposes LANoMAT to agents over the Model Context Protocol:
streamable HTTP, bearer tokens, device-code pairing, a small tool catalogue and recipes as skills.

<div class="live">LIVE 17:42 · MCP session · 2 tools in flight</div>

## Who uses it

- **Agents** (pi, Claude, Cursor, any MCP client) pair once, then read recipes, files and identity.
- **Teilnehmer** approve a device code in the browser.
- **Orga** sees paired devices and can revoke a token.

## Key endpoints

| Endpoint | Purpose |
|---|---|
| `POST /api/mcp/device` | Start device pairing; returns `device_code`, `user_code`, `verification_uri` |
| `POST /api/mcp/token` | Exchange an approved device code for a bearer token |
| `POST /mcp` | Streamable HTTP MCP endpoint (bearer) |

## Guards and rules

- The token is a bearer credential; the MCP endpoint has no cookie session.
- The device code expires; the token exchange answers `authorization_pending` until approved.
- A token is scoped to the user who approved it. Revoking it invalidates the session.
- The server never exposes another user's private data; tools are read-mostly and policy-checked.
- Recipe content is served as resources and skills, not as arbitrary filesystem paths.
- The MCP server is optional: off in `core` unless `MODULE_MCP=true`.

## Docs for agents

This module **is** the server. Its catalogue is on [Tools & resources](/docs/mcp/tools);
`lanomat_me` is the first call after pairing.

<div class="callout-live">
  <strong>Read the docs for agents.</strong> The MCP section covers installation snippets, the
  JSON-RPC shape, prompt recipes and the security model in detail.
</div>
