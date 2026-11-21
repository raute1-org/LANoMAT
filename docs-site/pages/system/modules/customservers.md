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

Filament **Custom servers** lists one row per definition: name, remote host, event, Docker image,
container name and status.

| Dark | Light |
|---|---|
| ![Custom servers admin table in dark theme with name, remote host, Docker image, container name and status columns](/screens/admin-custom-servers-dark.png) | ![Custom servers admin table in light theme with name, remote host, Docker image, container name and status columns](/screens/admin-custom-servers-light.png) |
| `CUSTOM SERVERS · DARK` — name, remote host, image, container, status | `CUSTOM SERVERS · LIGHT` |

The remote host comes from the host registry; a definition may only target a host that carries the
`custom_servers` role.

| Dark | Light |
|---|---|
| ![Remote hosts admin table in dark theme with the role column a custom server checks](/screens/admin-remote-hosts-dark.png) | ![Remote hosts admin table in light theme with the role column a custom server checks](/screens/admin-remote-hosts-light.png) |
| `REMOTE HOSTS · DARK` — the role column a custom server checks | `REMOTE HOSTS · LIGHT` |

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
