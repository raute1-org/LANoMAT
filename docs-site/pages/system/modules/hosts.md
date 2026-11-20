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
