---
title: Custom servers
description: Docker game servers on a managed host, start/stop and health probes.
sidebar_icon: container
---

`CustomServers` runs game servers that Pelican does not manage: a Docker container on a registered
`RemoteHost`, started and stopped through the same `RemoteExecutor` as the other remote actions.

## Who uses it

- **Orga** creates a server definition (image, ports, env, volume), starts/stops it and reads the
  probe result.
- **Teilnehmer** get a join link when the server is ready.

## Key screens

- Filament **Custom servers** — create, start, stop, probe, inspect logs.
- Match/event page — connect string when linked to a match.

## Guards and rules

- The host must have the `custom_servers` role; the action rejects any other role.
- Container names, images, ports and env values are validated; no free-form shell.
- A start is idempotent: an already-running container is not restarted.
- Stopping a server does not delete its volume; deletion is explicit.
- Port collisions are checked against the port pool before start.
- Probe failures are `warn`, not `down`, if the container is running but the game query is slow.
- A failed start leaves the previous state visible instead of claiming success.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Escape hatch, not a platform.</strong> Custom servers exist for the one game Pelican's
  eggs do not cover. If it becomes routine, it belongs in a recipe or a Pelican egg.
</div>
