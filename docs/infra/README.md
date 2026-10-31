# Infrastructure runbooks

Reproducible, placeholder-based runbooks for the infrastructure around a
LANoMAT event. They are written so that a stranger can follow them, and so that
each step can later be turned into a LANoMAT provisioning feature.

This repository is public: every real address, hostname, key and account is a
placeholder (see [CONTRIBUTING.md](../../CONTRIBUTING.md), "Public repository
rules").

| Document                                                           | Topic                                                                                                                              |
| ------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| [proxmox-test-vm-lancache.md](proxmox-test-vm-lancache.md)         | Debian 13 VM on Proxmox VE, hardened, Docker, LanCache, egress isolation, plus the automation map.                                 |
| [wireguard-tunnel.md](wireguard-tunnel.md)                         | Point-to-point WireGuard tunnel between the app server and a venue host behind NAT, firewall and boot-order details.               |
| [pelican-panel-wings.md](pelican-panel-wings.md)                   | Pelican Panel (Docker) and Wings (systemd) on the venue host, LanCache DNS for game containers, `PelicanClient` contract check.    |
| [custom-game-servers-gmod-ttt.md](custom-game-servers-gmod-ttt.md) | Garry's Mod (TTT) on Pelican, SteamCMD through the LanCache, custom content, CS2 GunGame analysis, game server preset model.       |
| [lan-tls-certificates.md](lan-tls-certificates.md)                 | Wildcard TLS certificate for venue-only names through Traefik and the DNS-01 challenge (Cloudflare or Hetzner DNS), staging first. |
| [cloudflare-tunnel-migration.md](cloudflare-tunnel-migration.md)   | Plan for moving the app to the venue behind a Cloudflare Tunnel, cut-over, rollback and alternatives (untested).                   |

## Suggested reading order

1. `proxmox-test-vm-lancache.md`: the base host everything else builds on.
2. `wireguard-tunnel.md`: only needed when LANoMAT reaches the venue host over
   the internet; skip it if both are on the same LAN.
3. `pelican-panel-wings.md`: game server hosting; builds on 1 and, for remote
   access, on 2.
4. `custom-game-servers-gmod-ttt.md`: custom game servers and presets; builds
   on 3. Background for the `GameServers` module is in
   [`../gameservers-pelican.md`](../gameservers-pelican.md).

Every runbook ends with a "What LANoMAT automation would need" section that maps
its steps to existing modules and contracts (`Hosts`/`RemoteExecutor`,
`Lancache`, `GameServers`/`PelicanClient`, `HypervisorClient`). The Proxmox VM,
base system, Docker, ingress firewall, LanCache and egress isolation runbook is
implemented as the `Provisioning` module, see [`../provisioning.md`](../provisioning.md);
WireGuard, Pelican and the game server steps are not.

Related, older docs: [`../lancache-setup.md`](../lancache-setup.md) (LanCache
host runbook used by `ApplyLancacheSetup`), [`../three-server-setup.md`](../three-server-setup.md).
