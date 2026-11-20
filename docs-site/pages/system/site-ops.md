---
title: Site & ops
description: "The venue side: hosts, custom servers, LanCache, provisioning, preflight and room audio."
sidebar_icon: server
---

Site & ops is for the person who owns the network, the servers and the beamer cabinet. Most of it
is optional and off in the `core` profile; the modules here either call another machine over SSH/HTTP
or observe the stack and report a traffic light.

## Modules in this group

| Module | What it does | Main users |
|---|---|---|
| [GameRecipes](/docs/system/modules/gamerecipes) | Recipes and the LAN installer: `recipe.yaml` + `SKILL.md`, files, avatars | Teilnehmer, Orga, agents |
| [Hosts](/docs/system/modules/hosts) | Managed remote hosts, SSH keys, host-key pinning | Orga |
| [CustomServers](/docs/system/modules/customservers) | Docker game servers on a managed host | Orga |
| [Lancache](/docs/system/modules/lancache) | Bootstrap and health-check LanCache on its own host | Orga |
| [Provisioning](/docs/system/modules/provisioning) | Proxmox test-VM setup with dry-run plans and queued runs | Orga |
| [Preflight](/docs/system/modules/preflight) | Health-check registry, `lanomat:preflight`, Filament tile, update watch | Orga |
| [RoomAudio](/docs/system/modules/roomaudio) | Denon AV receiver control from the admin panel | Orga |

## The remote-host pattern

`Hosts` stores an address, an SSH user and an encrypted private key. The first `Probe` pins the
host key fingerprint; every later connection verifies it before authenticating. Actions build a
structured command from configuration and run it through `RemoteExecutor` — no free-typed shell
strings. `SshRemoteExecutor` uses phpseclib in production; tests bind `FakeRemoteExecutor`.

```text
LANoMAT action ── RemoteExecutor ──SSH──► managed host
                                             ├── docker run (custom server, LanCache)
                                             ├── apt/systemd steps (provisioning)
                                             └── SFTP (file host uploads)
```

## The preflight traffic light

One registry, one command, one tile. Every check returns `ok`, `warn`, `down` or `skipped` with a
sentence that names the next step. `php artisan lanomat:preflight` prints it for a terminal;
the admin tile shows the same. Examples: database, Redis, queue heartbeat, scheduler, backup marker,
Discord gateway, Mumble sidecar, LiveKit, file host, desktop client download, certificate expiry,
pinned image updates.

<div class="callout-live">
  <strong>Nothing here is core.</strong> A LAN runs without provisioning, LanCache or room audio.
  Preflight is core because a silent queue is the one failure that looks like a working app until
  reminders do not fire.
</div>
