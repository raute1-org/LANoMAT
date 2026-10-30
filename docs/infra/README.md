# Infrastructure runbooks

Reproducible, placeholder-based runbooks for the infrastructure around a
LANoMAT event. They are written so that a stranger can follow them, and so that
each step can later be turned into a LANoMAT provisioning feature.

This repository is public: every real address, hostname, key and account is a
placeholder (see [CONTRIBUTING.md](../../CONTRIBUTING.md), "Public repository
rules").

| Document                                                   | Topic                                                                                              |
| ---------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| [proxmox-test-vm-lancache.md](proxmox-test-vm-lancache.md) | Debian 13 VM on Proxmox VE, hardened, Docker, LanCache, egress isolation, plus the automation map. |

Related, older docs: [`../lancache-setup.md`](../lancache-setup.md) (LanCache
host runbook used by `ApplyLancacheSetup`), [`../three-server-setup.md`](../three-server-setup.md).
