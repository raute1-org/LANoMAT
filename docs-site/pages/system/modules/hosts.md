---
title: Hosts
description: Managed remote hosts, encrypted SSH keys and host-key pinning.
sidebar_icon: network
---

`Hosts` is the registry of machines LANoMAT may touch: the LanCache box, the custom-server host, the
Proxmox node, the file host. It stores an address, an SSH user, an encrypted private key and the
pinned host-key fingerprint.

## Who uses it

- **Orga** registers a host, probes it once, and assigns a role (`lancache`, `custom_servers`,
  `file_host`, `provisioning`).
- Other modules use the host through `RemoteExecutor`; participants never see it.

## Key screens

- Filament **Remote hosts** — create, probe, pin fingerprint, set role.
- Preflight — host reachability and key status.

The registry shows the columns that matter for trust: hostname, SSH port and user, private key,
role, status and last probe.

| Dark | Light |
|---|---|
| ![Remote hosts admin table in dark theme with hostname, SSH port and user, role, status and last probe columns](/screens/admin-remote-hosts-dark.png) | ![Remote hosts admin table in light theme with hostname, SSH port and user, role, status and last probe columns](/screens/admin-remote-hosts-light.png) |
| `REMOTE HOSTS · DARK` — hostname, SSH port and user, role, last probe | `REMOTE HOSTS · LIGHT` |

Modules consume the registry instead of storing their own addresses — a custom server definition
points at a host row.

| Dark | Light |
|---|---|
| ![Custom servers admin table in dark theme with one remote host per definition](/screens/admin-custom-servers-dark.png) | ![Custom servers admin table in light theme with one remote host per definition](/screens/admin-custom-servers-light.png) |
| `CUSTOM SERVERS · DARK` — a host consumer, one remote host per definition | `CUSTOM SERVERS · LIGHT` |

## Guards and rules

- The SSH private key is stored with an `encrypted` cast and is write-only in the form.
- The first `Probe` pins the host key (trust-on-first-use). Every later connection verifies the
  fingerprint **before** authenticating; a changed key aborts the connection.
- Probe a newly registered host once before running any setup on it — that is the one-shot window.
- Role checks are explicit: LanCache actions reject a host whose role is not `lancache`,
  custom-server actions reject a wrong role.
- Commands are structured and `escapeshellarg`-quoted; no free-typed shell strings.
- `RemoteExecutor` is a contract: `SshRemoteExecutor` in production, `FakeRemoteExecutor` in tests.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Least privilege.</strong> The SSH account should be able to do exactly what its role
  needs. A file host's SFTP account can write one directory and has no shell.
</div>
