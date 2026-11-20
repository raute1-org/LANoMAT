---
title: MCP server
description: Connect an agent to LANoMAT over streamable HTTP at /mcp, with device pairing and bearer tokens.
sidebar_icon: bot
---

LANoMAT exposes a **Model Context Protocol** server at `/mcp`. It speaks streamable HTTP, accepts a
bearer token, and gives an agent the same view of the LAN a participant has: events, tournaments,
teams, files, the games catalogue, polls, catering, LFG and the game recipes. It does not expose a
generic database or a shell.

<div class="live">LIVE 17:42 · MCP connected · 24 tools · 1 resource · 1 prompt</div>

## Endpoint

```text
https://lan.example/mcp
```

| Property | Value |
|---|---|
| Transport | Streamable HTTP (`POST`; `Accept: application/json, text/event-stream`) |
| Auth | `Authorization: Bearer <token>` |
| Protocol | MCP as negotiated on `initialize` (current clients use `2025-06-18`) |
| Module | `Mcp` — optional, off in the `core` profile unless `MODULE_MCP=true` |
| Router | `/mcp` is session-free; bearer only, never the browser cookie |

An unauthenticated call answers `401` with `WWW-Authenticate: Bearer`. A bad or revoked token is
`401`, never a redirect to the login page. A module that is off makes its tools disappear from
`tools/list`; if the whole `Mcp` module is off, `/mcp` is not routed at all.

## Pair a device

Agents do not use a browser session. They pair once with a device code, and the human approves it in
the browser while logged in.

```bash
# 1. Ask for a device code (device_name is optional but shows up in the app)
curl -s https://lan.example/api/mcp/device \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"device_name":"pi on gaming-pc"}'
```

```json
{
  "device_code": "6c1f0a9e…",
  "user_code": "K7QF-2M9R",
  "verification_uri": "https://lan.example/mcp/connect",
  "verification_uri_complete": "https://lan.example/mcp/connect?code=K7QF-2M9R",
  "expires_in": 600,
  "interval": 5
}
```

Open `verification_uri`, type the short code, compare it with the one the agent shows, and approve.
The page also lists every connected agent and lets you revoke one. The agent polls:

```bash
# 2. Exchange the approved code for a token
curl -s https://lan.example/api/mcp/token \
  -X POST \
  -H "Content-Type: application/json" \
  -d '{"device_code":"6c1f0a9e…"}'
```

```json
{
  "access_token": "lmcp_…",
  "token_type": "Bearer",
  "expires_in": 7776000
}
```

While the human has not approved yet, the exchange answers `400` with
`{"error":"authorization_pending"}`. An expired code answers `expired_token`, a denied one
`access_denied`, an unknown one `invalid_grant`. Polling faster than `interval` answers `slow_down`
with a raised interval.

The token belongs to the approving user and carries their role. There is no scope list: every tool
is policy-checked against that user. The token expires after 90 days by default and slides on use, so
an active LAN weekend keeps it alive; revoking the device in the browser invalidates it immediately.
Headless setups can mint a token with `php artisan lanomat:mcp-token <user>`.

<div class="callout-live">
  <strong>No password in a file.</strong> The token is the credential. Store it in an environment
  variable (`LANOMAT_TOKEN`) or the MCP client's secret store, never in the docs repository.
</div>

## Connect pi

The bundled agent does this for you, but any MCP client works. With pi:

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
    "arguments": { "query": "ut2004", "platform": "windows" }
  }
}
```

## What is exposed

| Surface | Count | Examples |
|---|---|---|
| Tools | 25 | `lanomat_me`, `recipe_install_plan`, `events_list`, `tournament_bracket` |
| Resources | 1 | `lanomat://recipes/{slug}/skill` |
| Prompts | 1 | `install-game` |

The full catalogue, argument schemas and examples are on [Tools & resources](/docs/mcp/tools).

## What is deliberately not exposed

- No SQL, no arbitrary file paths, no shell.
- No publishing: a contributed recipe is a **draft** for orga review.
- No admin-only actions: pairing as an admin does not turn MCP into a panel.
- No other user's private data: every call is scoped to the token's user and their policies.
- No session: the token is the only credential on `/mcp` and `/api/v1`.

## Errors you will see

| HTTP | Meaning |
|---|---|
| `401` | Missing, expired or revoked token; pair again |
| `403` | The user's role or a policy refuses the action |
| `404` | Unknown event, team, tournament, recipe or file id |
| `422` | Invalid arguments or a domain rule (window closed, no pending report) |
| `429` | Rate limited (240 requests per minute per token and IP); back off and retry |

Tool failures come back as readable tool errors, so the model can react instead of seeing a stack
trace.

<div class="callout-live">
  <strong>LAN-only is fine.</strong> The MCP server works on the venue network without internet. The
  agent's model may need internet, but recipe and file calls do not.
</div>
