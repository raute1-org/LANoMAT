---
title: Provisioning
description: Proxmox test-VM setup with dry-run plans, steps and queued runs.
sidebar_icon: cpu
---

`Provisioning` automates the setup of a test VM on Proxmox VE. It is an operator tool, not a
participant feature: define a plan, run it in dry-run mode first, then queue the real run and watch
the steps.

## Who uses it

- **Orga / infra** registers the hypervisor, chooses a plan, reviews the dry run and starts it.
- Nobody else sees the module; it is off in the `core` profile.

## Key screens

- Filament **Provisioning** — plans, dry run, runs, step log.
- Preflight — hypervisor and host-key checks.

## Guards and rules

- `HypervisorClient` is a contract; `NullHypervisorClient` is bound when the module is off and
  `ProxmoxApiClient` when it is on. Tests use a fake.
- Every plan has a dry run that lists each step and its commands without executing them.
- Runs are queued; a failed step stops the run and leaves the previous steps visible.
- SSH host keys are pinned and crossed against the hypervisor's reported keys.
- Secrets are redacted in the step log (`Redactor`).
- Provisioning requires Hosts and Lancache.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Dry run first.</strong> A provisioning plan that has never been dry-run is a guess. The UI
  keeps the two modes visibly separate.
</div>
