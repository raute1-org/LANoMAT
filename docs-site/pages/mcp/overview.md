---
title: MCP server
description: Connect an agent to LANoMAT over streamable HTTP at /mcp, with device pairing and bearer tokens.
sidebar_icon: bot
---

LANoMAT exposes a **Model Context Protocol** server at `/mcp`. It speaks streamable HTTP, accepts a
bearer token, and gives an agent a small, deliberate surface: identity, game recipes, recipe skills,
files and a contribution draft. It does not expose a generic database or shell.

<div class="live">LIVE 17:42 · MCP connected · 24 tools · 1 resource · 1 prompt</div>

## Endpoint

```text
https://lan.example/mcp
```

| Property | Value |
|---|---|
| Transport | Streamable HTTP (`POST`; `Accept: application/json, text/event-stream`) |
| Auth | `Authorization: Bearer <token>` |
| Protocol | MCP `2025-06-18` (negotiated on `initialize`) |
| Session | `Mcp-Session-Id` header returned by `initialize`, echoed on later calls |
| Module | `Mcp` — off in `core` unless `MODULE_MCP=true` |

An unauthenticated call answers `401` with `WWW-Authenticate: Bearer`. A bad token is `401`, never
a redirect to the login page.

## Pair a device

Agents do not use a browser session. They pair once with a device code, and the human approves it in
the browser while logged in.

```bash
# 1. Ask for a device code
curl -s https://lan.example/api/mcp/device -X POST
```

```json
{
  "device_code": "dc_6f1c…",
  "user_code": "K7QF-2M9R",
  "verification_uri": "https://lan.example/mcp/connect",
  "expires_in": 600,
  "interval": 5
}
```

Open `verification_uri`, confirm the code matches the one the agent shows, and approve. The agent
polls:

| Dark | Light |
|---|---|
| ![Pairing approval, dark](/screens/mcp-connect-pending-dark.png) | ![Pairing approval, light](/screens/mcp-connect-pending-light.png) |
| `Agent & MCP verbinden · DARK` — agent name, code, approve/deny, connected agents | `Agent & MCP verbinden · LIGHT` |

```bash
# 2. Exchange the approved code for a token
curl -s https://lan.example/api/mcp/token \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"device_code":"dc_6f1c…"}'
```

```json
{
  "access_token": "lmcp_…",
  "token_type": "Bearer",
  "expires_in": 7776000
}
```

While the human has not approved yet, the exchange answers `400` with
`{"error":"authorization_pending"}`. An expired code answers `expired_token`. The token belongs to
the approving user and carries their role; revoking the device in the browser invalidates it.
The response carries no scope: a reserved `abilities` column exists on the token row, but no mint
path sets it and no request enforces it yet.

<div class="callout-live">
  <strong>No password in a file.</strong> The token is the credential. Store it in an environment
  variable (`LANOMAT_TOKEN`) or the MCP client's secret store, never in the docs repository.
</div>

## Connect pi

```bash
export LANOMAT_TOKEN="lmcp_…"
pi mcp add lanomat \
  --url https://lan.example/mcp \
  --bearer-token-env-var LANOMAT_TOKEN \
  --description "LANoMAT recipes, files and event identity for the LAN" \
  --exposure deferred
pi mcp list
```

`pi mcp add` writes the user-level `~/.pi/agent/mcp.json`. The equivalent file:

```json
{
  "mcpServers": {
    "lanomat": {
      "url": "https://lan.example/mcp",
      "headers": {
        "Authorization": "Bearer ${LANOMAT_TOKEN}"
      },
      "description": "LANoMAT recipes, files and event identity for the LAN",
      "exposure": "deferred"
    }
  }
}
```

Project-level `.pi/mcp.json` works too (pi reads it after project trust). Keep credentials in the
user-level file.

## Speak JSON-RPC directly

If you are building your own client, the wire format is ordinary MCP:

```bash
curl -s https://lan.example/mcp \
  -H "Authorization: Bearer $LANOMAT_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {
      "protocolVersion": "2025-06-18",
      "capabilities": {},
      "clientInfo": {"name": "curl", "version": "0.1"}
    }
  }'
```

Then `notifications/initialized`, then `tools/list`, `resources/list`, `prompts/list` and the calls
themselves. The server answers with `application/json` or an SSE stream, as requested by `Accept`.

```json
{
  "jsonrpc": "2.0",
  "id": 2,
  "method": "tools/call",
  "params": {
    "name": "recipes_list",
    "arguments": { "platform": "windows" }
  }
}
```

## What is exposed

| Surface | Count | Examples |
|---|---|---|
| Tools | 24 | `lanomat_me`, `recipes_list`, `recipe_install_plan`, `files_list` |
| Resources | 1 | `lanomat://recipes/{slug}/skill` |
| Prompts | 1 | `install-game` |

The full catalogue, argument schemas and examples are on [Tools & resources](/docs/mcp/tools).

## What is deliberately not exposed

- No SQL, no arbitrary file paths, no shell.
- Writes are limited to your own registration, match result, vote, LFG post and a draft recipe. No seat, event or tournament edits, no publish.
- No other user's private data: every call is scoped to the token's user and their role.
- No publish action: a contributed recipe is a **draft** for orga review.

## Errors you will see

| Case | What you get |
|---|---|
| Missing or expired token | HTTP `401` with `WWW-Authenticate: Bearer`; pair again |
| Unknown tool, resource or prompt, or bad arguments | JSON-RPC `-32602` (invalid params) |
| Policy refusal or an unknown id inside a tool | A tool result with an error text (the model can react); never a stack trace |
| Rate limited | HTTP `429` (240 requests per minute per token and IP); back off and retry |
| Unexpected server error | JSON-RPC `-32603` or a `Response::error` text |

<div class="callout-live">
  <strong>LAN-only is fine.</strong> The MCP server works on the venue network without internet. The
  agent's model may need internet, but recipe and file calls do not.
</div>
