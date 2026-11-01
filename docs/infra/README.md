# Infrastructure runbooks

Reproducible, placeholder-based runbooks for the infrastructure around a
LANoMAT event. They are written so that a stranger can follow them, and so that
each step can later be turned into a LANoMAT provisioning feature.

This repository is public: every real address, hostname, key and account is a
placeholder (see [CONTRIBUTING.md](../../CONTRIBUTING.md), "Public repository
rules").

| Document                                                           | Topic                                                                                                                                                                               |
| ------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| [proxmox-test-vm-lancache.md](proxmox-test-vm-lancache.md)         | Debian 13 VM on Proxmox VE, hardened, Docker, LanCache, egress isolation, plus the automation map.                                                                                  |
| [wireguard-tunnel.md](wireguard-tunnel.md)                         | Point-to-point WireGuard tunnel between the app server and a venue host behind NAT, firewall and boot-order details.                                                                |
| [pelican-panel-wings.md](pelican-panel-wings.md)                   | Pelican Panel (Docker) and Wings (systemd) on the venue host, LanCache DNS for game containers, `PelicanClient` contract check.                                                     |
| [custom-game-servers-gmod-ttt.md](custom-game-servers-gmod-ttt.md) | Garry's Mod (TTT) on Pelican, SteamCMD through the LanCache, custom content, CS2 GunGame analysis, game server preset model.                                                        |
| [lan-tls-certificates.md](lan-tls-certificates.md)                 | Wildcard TLS certificate for venue-only names through Traefik and the DNS-01 challenge (Cloudflare or Hetzner DNS), staging first.                                                  |
| [app-at-the-venue.md](app-at-the-venue.md)                         | Running the app at the venue behind the cloud server: TLS pass-through, cut-over, rollback, offline use.                                                                            |
| [test-suite-vm.md](test-suite-vm.md)                               | The full app (every module) on the test VM, wired to Pelican, LanCache, voice and Discord, reached through the cloud entry.                                                         |
| [local-service-tls.md](local-service-tls.md)                       | Trusted TLS for Music Assistant, the Pelican panel and Mumble below the venue domain, with one wildcard certificate.                                                                |
| [mumble-changelog-notes.md](mumble-changelog-notes.md)             | What Mumble's release changelogs since 1.4.230 mean for us: the 1.5 upgrade, the Ice module rename, security fixes, usable features.                                                |
| [livekit-web-voice.md](livekit-web-voice.md)                       | Self-hosted LiveKit SFU for the in-browser voice and text chat (`WebVoice` module): ports, latency settings, TLS, manual checklist.                                                 |
| [file-host.md](file-host.md)                                       | Shared files stored on and served from the LanCache host: chrooted SFTP account, nginx with signed expiring links, resumable big-file uploads (tusd, tus protocol), TLS, checklist. |
| [room-audio-denon.md](room-audio-denon.md)                         | Denon AV receiver at the venue for the `RoomAudio` module: the denon-admin sidecar, network placement, finding the `NET` source, ceiling, Preflight.                                |

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

## Update watch (pinned third-party images)

`lanomat:check-updates` (scheduled every six hours, Preflight is a core module) compares the image tags pinned in
`compose.yml` and `docker/file-host/compose.yml` with the latest GitHub release of the upstream project (Mumble,
LiveKit, tusd, Traefik) and caches the answer for 12 hours. The Preflight check `dependency_updates` ("Software-Updates"
in the Filament tile and `lanomat:preflight`) reads only that cache:

- `ok`: every watched component is on the latest minor line (patch releases do not count, being ahead of upstream is fine).
- `warn`: one or more components trail by a minor or major version; the message lists `pinned → latest`.
- `skipped`: nothing has been looked up yet or GitHub was unreachable. A failed lookup keeps the previous answer.

It never reports `down`: it is a heads-up to read the changelog before the event, not an outage. PostgreSQL, Redis and
nginx are listed with their pinned tag only (no release source; Docker Hub lookups are out of scope). A GitHub release tag
does not always equal the Docker image tag of a project, so treat a warning as a prompt to check, not as a fact.

To watch another component, add an entry to `config/updates.php` (`label`, `image` as written in the compose file, and
optionally `repo` as `org/project`); a compose file that is not listed in `compose_files` is not scanned. Tests use the
`FakeLatestRelease` and never call GitHub.
