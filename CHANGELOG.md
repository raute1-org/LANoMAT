# Changelog

All notable changes to LANoMAT v2 since the first production deployment. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); entries are grouped by merge date
(newest first) and then by Added, Changed and Fixed.

**How this file is maintained.** The project has no versioned releases after the `m13` tag, so
there is no `Unreleased` heading: every merged pull request gets one line under its merge date
and section (`feat` is Added, `fix` is Fixed, everything else is Changed; docs-only changes carry a
"Docs:" prefix). Take the title and number from `gh pr list --state merged`, keep it to one line,
and add the entry in the same pull request when possible or in the next docs sync. Earlier history
lives in the git log, the roadmap
([`docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md`](docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md))
and the phase tags `m0` to `m13`. This file starts at the pre-LAN state of 2026-10-30.

## 2026-11-01

### Added

- Desktop client: a Beitreten button on every place (voice room, game, server) of a person in Wo ist wer
- File host preflight check and certificate renewal (#109)
- Update watch for pinned third-party images (#110)
- Retract own gallery photos (uploader or orga) (#111)
- Dedicated MatchStatus::Live (#112)
- Native voice client API with device authorization pairing (#113)
- Rust core and CLI for native web voice (clients/voice) (#116)
- Tauri v2 desktop app on voice-core (#117)
- Chat, realtime auth and notifications on the native bearer API (#120)
- Event features in the native client API (#121)
- Native client play API (now feed, tournaments, matches, game servers) (#122)
- Profile, linked accounts and friends for the native client (#123)
- Core features: API clients, realtime chat, notifications, file transfers, command contract (#124)
- Profile, linked accounts and friends (Rust core + commands) (#125)
- Participant screens of the desktop client (#126)
- Where is everybody, and follow them (voice and game) (#127)
- Windows port and client release workflow (#128)
- Download the native client inside LANoMAT, always the server's version (#129)
- Freunde, Profil and Wo ist wer with Hinterherjoinen (#130)
- Desktop app entry points (nav, dashboard hint, settings, /app alias) (#131)
- Keep Discord avatars in LANoMAT and serve them from there (#132)

### Changed

- Untrack stray .pyc and ignore bytecode (#114)
- Docs: Sync roadmap and README with the finished backlog (#115)
- Docs: UX concept for participants (#119)

### Fixed

- Paper preset needs USER_AGENT (#118)

## 2026-10-31

### Added

- LANoMAT GMod egg, server presets, port pool and A2S readiness (#60)
- Hypervisor registration, dry-run plans and queued runs (module off in core) (#61)
- Give event-level game servers their own voice channel (#74)
- Voice channels next to the per-match text channel (#79)
- Cloud entry point that passes TLS through to an app at the venue (#83)
- Show the Mumble server password on the voice setup page (#90)
- Delete voice rooms only once nobody is in them (#91)
- Remove empty voice rooms at once, keep the ones with people (#92)
- Category per event, room per team, archive for match text channels (#93)
- Voice join links carry the user's own name (#97)
- Permanent game servers across events (Pelican, Docker, external) (#99)
- Browser voice and text chat with self-hosted LiveKit (#100)
- Trusted TLS for Music Assistant, the Pelican panel and Mumble (#102)
- Allow all file types, serve downloads from the LanCache file host (#103)
- Resumable big-file uploads straight to the file host (tus) (#104)
- Latency diagnostics, self-test and headset mode (#107)

### Changed

- Request body caps, discord-gateway lockfile, team logo metadata stripping (#62)
- Docs: Record the dependency decision before the first LAN (#64)
- Docs: Record the local failure tests for Redis, Postgres and the worker (#65)
- Docs: Round 2 reports for tournaments, seating/schedule/catering and infoscreen (#66)
- Docs: Record rulings AD-AS from the round 2 fixes (#71)
- Docs: Runbook for wildcard TLS certificates on venue-only names (#75)
- Docs: Resolve the venue TLS name through the LanCache DNS and the MikroTik (#76)
- Docs: Variant with the WireGuard tunnel on the venue router (#77)
- Docs: Checklist for switching on the Discord bot features (#80)
- Docs: No load test, the event size does not need one (#81)
- Docs: Plan for moving the app to the venue behind a Cloudflare Tunnel (#82)
- Docs: Record the verified Cloudflare DNS-01 run (#84)
- Docs: Test suite on the VM, planned venue hardware (#88)

### Fixed

- Decide the Traefik recreate from the last deployed commit (#63)
- Beamer bracket fit, live scene list, once-only tombola draws, bounded inputs (#67)
- Confirmed registration gate, item limit, price bounds, per-option counts and CSV export (#68)
- Phone seat map, confirmed-registration gate, stable ICS, reminders and change alarms (#69)
- Orga decision on any match, race guards, roster rules, round-robin ties (#70)
- Make the TeamSpeak sidecar work against a real server (#72)
- Make the Music Assistant client match the real API (#73)
- Keep match channels private and the bot inside them (#78)
- Venue Traefik settings in a static file, drop ignored flag (#85)
- Venue entry as socat forwarder, tunnel test recorded (#86)
- Default locale de, APP_LOCALE in the runbook (#87)
- TeamSpeak sidecar spun on a closed connection (#89)
- Retry a failed channel creation at once; bot rights in team rooms (#94)
- Store each provider's rooms at once, take back half-built ones (#95)
- Match card action buttons wrap instead of overflowing (#96)
- Self-host the fonts so the frontend build needs no network (#98)
- 404 for missing stored files; pages no longer claim a failed load after a rejected form (#101)
- Mumble admin sidecar for Mumble 1.5, server moved to 1.5.939 (#105)
- Certificate group for Mumble 1.5, exporter corrects mode and group (#106)
- Name match rooms like the Discord ones (#108)

## 2026-10-30

### Added

- Harden prod deploy for lan.raute1.org (#24)
- Add production deploy script (#26)
- Link to admin panel from user menu (#27)
- Require login for all event pages (#29)
- Restrict Discord login to members of one guild (#31)
- Unified app shell, useful dashboard, page layout rules (#33)
- New LANoMAT logo mark (L of nodes) (#34)
- Admin marker, back link and shell parity guard (#40)
- Self check-in via on-site QR and walk-in registration (#41)
- Arrival planning and orga forecast overview (#42)
- Restore drill script, restore runbook (drill passed against real backups) (#55)

### Changed

- Bump actions/setup-node from 7.0.0 to 7.1.0 in the github-actions group across 1 directory (#21)
- Docs: Document LANoMAT brand identity and adopt qcode standard (#32)
- Track newest PHP/Node patches, split CI into parallel jobs (#35)
- Guard the public repo (gitleaks, public-safety checker, gitignore) (#46)
- Docs: Public-safe recipe for the Proxmox test VM with LanCache (#48)
- Docs: WireGuard tunnel, Pelican panel/Wings and Garry's Mod TTT (public-safe) (#52)
- Docs: Roadmap, README and CLAUDE.md up to date (pre-LAN checklist), brand docs reflect the new logo (#54)
- Docs: Repeatable, public-safe QA handbook (plan, inventory, findings, runs, seed) (#56)
- Patch composer/npm advisories, route authorization matrix, hardening (#57)

### Fixed

- Bump Traefik to v3.6 for Docker 29 (#25)
- Redis numeric markers and Filament-native widget (#28)
- Steam link 500 on null approvedScopes (#30)
- Constrain panel logo, align navigation with the app (#39)
- QA round 1 (robustness, ops, tournament/registration rules, timezone) (#43)
- Accept tiny valid storage archives in backup verification (#45)
- Allowlist RFC1918 range constants in the infra recipe (#49)
- QA round 4 (queued notifications, Mehr nav, overlays, a11y, copy) (#50)
- Rebuild PelicanClient against the real Pelican API (#51)
- Recreate Traefik when its bind-mounted config changed (#53)
