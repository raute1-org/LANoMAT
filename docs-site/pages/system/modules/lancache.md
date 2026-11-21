---
title: LanCache
description: Bootstrap and health-check LanCache on its own host, over SSH.
sidebar_icon: database
---

`Lancache` provisions [LanCache](https://lancache.net) on a separate machine. LanCache needs to own
DNS and ports 53/80/443 on its network segment, so it does not run inside LANoMAT's compose stack;
LANoMAT registers the machine as a `RemoteHost` and bootstraps the container over SSH.

## Who uses it

- **Orga** registers the LanCache host with role `lancache`, probes it, then runs the setup and
  health check.
- **Teilnehmer** never see the module; they just download games at LAN speed.

## Key screens

- Filament **Remote hosts** — role `lancache`, probe.
- Filament action **LanCache setup** — apply, verify, probe.
- Preflight — LanCache reachability (when enabled).

| Dark | Light |
|---|---|
| ![Remote hosts table with role, status and last-probe columns, dark](/screens/admin-remote-hosts-dark.png) | ![Remote hosts table with role, status and last-probe columns, light](/screens/admin-remote-hosts-light.png) |
| `LANCACHE · REMOTE HOST` — the machine gets role `lancache` and is probed first | `LANCACHE · LIGHT` — the same host table |
| ![Filament dashboard with the Preflight tile that reports LanCache reachability, dark](/screens/admin-dark.png) | ![Filament dashboard with the Preflight tile that reports LanCache reachability, light](/screens/admin-light.png) |
| `LANCACHE · PREFLIGHT` — reachability as one check, not a separate dashboard | `LANCACHE · LIGHT` — the same tile in light mode |

## Guards and rules

- The action authorizes `isOrga()` and rejects a host whose role is not exactly `lancache`.
- The `docker run` command is built from configuration (`LancacheConfig`), never from free-typed
  input.
- The host key must be pinned by a prior probe; otherwise the first connection trusts whatever key
  it sees.
- `VerifyLancacheStack` checks the container, DNS and a cache hit; `ProbeLancache` checks
  reachability.
- The cache volume is not deleted by a re-apply.
- LanCache requires the Hosts module.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Why a separate host?</strong> Sharing a host with Traefik would mean two things claiming
  ports 80/443 and DNS. The separation is the feature.
</div>
