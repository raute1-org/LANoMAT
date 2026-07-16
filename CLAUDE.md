# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

LANoMAT v2 — a modular LAN party management tool (events, registration/tickets, seating, tournaments with brackets, schedule, catering, voting, LFG, infoscreens, Discord integration, Mumble voice, game servers via Pelican). Complete rebuild of the previous Nuxt/NestJS system as a **Laravel monolith**.

## Current state

**M0–M3 are implemented** (the `m3` tag marks this as the project's MVP). The Laravel app lives in this repo root: Vue starter kit (Inertia v2 + Vue 3 + Tailwind v4 + shadcn-vue + Pest 4), Fortify session auth with Discord-only OAuth (SocialiteProviders), Docker Compose dev stack (Postgres 16 + Redis 7), `User` model with `discord_id`/`role`/`avatar_url`, role middleware + admin `Gate::before`, Filament v5 panel at `/admin`, `lanomat:install` console command, and CI (pint, phpstan level 8, pest, eslint, prettier, vue-tsc, build) — that's M0.

M1 added the `Event` aggregate (lifecycle enum + transitions), the public event page, and profile editing. M2 added: event registration with QR-code tickets and orga check-in (`Registration` module), a seating chart with a participant SVG grid and a standalone Filament `SeatResource` for grid bulk-creation/per-seat network metadata (`Seating` module — a dedicated resource, not an Event tab; see the roadmap's M2 insights for why), in-app notifications with a bell dropdown and category preferences (`Notifications` module), and a Discord base: `DiscordClient` contract + `HttpDiscordClient`/`FakeDiscordClient`, a per-user DM notification channel, and outbox-deduplicated event announcements/reminders driven by a scheduler command (`Discord` module).

M3 added: cross-event **teams** (`Teams` module — create/request/respond/leave/transfer actions, owner-cannot-leave-without-transfer guard, logo upload to Storage, Filament `TeamResource`); the full **tournament** stack (`Tournaments` module) — a pure, IO-free bracket engine (`app/Modules/Tournaments/Domain/`: single-elimination n=2..64, double-elimination for n ∈ {2,4,6,8,16}, round-robin, all exhaustively property-tested before any lifecycle/UI use), enrollment/check-in/start lifecycle with row-locking against double-start and double-enrollment, a submit/confirm/dispute match-result flow with optimistic `lock_version` locking and an orga-override path, `MatchProgression` as the sole domain↔DB bridge for played results (mirrored by `BracketPersister` for initial generation); **Reverb** realtime (compose service on non-default host port 8081) broadcasting `TournamentStarted`/`MatchReady`/`MatchCompleted`/`TournamentCompleted` to a public `tournament.{id}` channel, driving a live bracket UI with SVG connectors and report/confirm actions; **Discord interactions** — an Ed25519-verified HTTP Interactions endpoint, a slash-command router (`/tournament list|info|checkin|bracket`, `/help`) with deferred + follow-up jobs, and per-match Discord text channels (created on `MatchReady`, cleaned up after a delay on `MatchCompleted`) via a transient-failure-only retrying `HttpDiscordClient` plus a queued-sends/outbox-sweep delivery guarantee; and **Mumble voice** (`Voice` module) — a `MumbleClient` contract backed by a purpose-built FastAPI Ice-REST sidecar (`docker/mumble-admin/`, `murmur-rest` evaluated and rejected as unmaintained), tournament/team/match channel orchestration, and `mumble://` join links on the match page and in the Discord embed. See `docs/architecture.md` for the module list and data-model sketch, and the roadmap's M3 insights section for the notable implementation decisions (`GameMatch` naming, the Mumble sidecar path, the bracket-persistence bye decision, and more).

Design and planning docs (still the source of truth for M3–M6):

- `docs/superpowers/specs/2026-07-13-lanomat-v2-rebuild-design.md` — approved architecture & module design
- `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` — master roadmap, phases M0–M6 with task numbers, including "Erkenntnisse" (insights) sections after each completed phase
- `docs/superpowers/plans/2026-07-14-m0-fundament.md` — fully detailed, executable plan for phase M0 (completed)

Detailed plans for M3–M6 are derived from the roadmap just-in-time at each phase start, in the same format as the M0 plan. Update the roadmap when a phase produces new insights (it is a living document).

## Guiding principle: state of the art, 2026 best practices

Every technology decision and implementation must follow **current (2026) best practices** — not patterns memorized from older training data:

- **Verify before you build.** Before installing a package, scaffolding, or using a framework API, check the current official docs (WebFetch/WebSearch) for the latest stable version and recommended approach. If a plan step conflicts with current official guidance, follow the docs and note the deviation in the commit message.
- **Prefer first-party.** Laravel ecosystem first (Reverb over third-party websockets, Socialite, Pest, Pint, official starter kits). Third-party only when there is no first-party equivalent.
- **No legacy patterns.** No deprecated APIs, no packages that are unmaintained or superseded, no workarounds for problems the framework already solves.

## Target stack

PHP 8.4 · Laravel 13 · Filament v5 (admin panel at `/admin`) · Inertia v2 + Vue 3 + Tailwind v4 + shadcn-vue (participant UI) · Reverb (websockets) · Pest (tests) · PostgreSQL 16 · Redis · Docker Compose · Mumble (voice, via Ice REST sidecar) · Pelican Panel (game servers)

External systems (Discord, Mumble, Pelican) are accessed **only** through contracts (`DiscordClient`, `MumbleClient`, `PelicanClient`) with fake implementations for tests — never call real APIs in tests.

## Commands

```bash
docker compose up -d           # postgres (host port 5434) + redis (host port 6380) — dev, non-default ports
composer run dev               # app + queue + vite dev server (via `php artisan dev`)
composer check                 # pint --test, phpstan (level 8), pest — must be green after every task
./vendor/bin/pest --filter=X   # run a single test
npm run lint:check             # eslint
npm run format:check           # prettier --check
npm run types:check            # vue-tsc --noEmit
npm run build                  # vite build — frontend checks, all must be green
php artisan lanomat:install --admin-discord-id=<id>   # migrate + promote/create admin
```

## MCP servers

- **context7** (`.mcp.json`, project-scoped): up-to-date docs for any library (Laravel, Filament, Inertia, Tailwind, shadcn-vue, …). Use it to satisfy the "verify before you build" rule.
- **laravel-boost**: installed in M0 Task 1 right after scaffolding (`composer require laravel/boost --dev && php artisan boost:install`) — first-party MCP with tinker, database queries, log reading and Laravel-ecosystem docs search. Prefer its docs tools over context7 for Laravel-ecosystem packages once available.

## Architecture rules

- **Modular monolith:** `app/Modules/<Name>/{Models,Actions,Policies,Filament,Jobs,Events,Contracts}`; tests mirrored in `tests/{Feature,Unit}/<Name>/`. Modules communicate via Laravel events and explicit service interfaces — never reach into another module's tables.
- **`Event` is the aggregate root** for everything organizational (tournaments, seating, catering, schedule, votes, LFG). Users, teams, and games are cross-event.
- **Every authorization goes through a Policy.** Never trust client-supplied user IDs.
- **Actions pattern:** one class per use case (`RegisterForEvent`, `SubmitMatchReport`); controllers and Filament resources stay thin.
- **No bot process:** Discord runs via REST + HTTP Interactions endpoint (Ed25519-verified route). No gateway connection.
- **Bracket engine is pure domain code** (`app/Modules/Tournaments/Domain/`) — no IO, exhaustively tested with Pest before any UI work.
- Uploads go to Laravel Storage, never Base64 into the database.

## Conventions

- Code, comments, commits, and docs in **English**; UI copy in German via `lang/de/`.
- **Conventional Commits** (`feat(scope): …`). TDD: failing test first wherever there is a testable behavior; frequent commits.
- PHP: Pint (Laravel preset), Larastan level 8, no `mixed` returns in own code, enums over magic strings.
- Vue: `<script setup lang="ts">`, no `<style>` blocks, Tailwind + shadcn-vue only.
- **Design system is binding.** All UI MUST follow the "Signalpult" design system documented in [`docs/design.md`](docs/design.md): the two-tier tokens in `resources/css/app.css` (use semantic role utilities like `bg-primary`/`text-muted-foreground`/`text-live`/`font-mono` — never raw hex), Space Grotesk + JetBrains Mono (mono for machine data only), the one rationed signal-amber accent, calm app / loud beamer, the `LiveIndicator` signature for live state, all four states (empty/loading/error/normal), and the Rams quality floor (responsive, visible focus, `prefers-reduced-motion`, lazy/sized images, AA contrast). **For any new UI or reshaping of existing UI, invoke the `frontend-design` skill first** and design against `docs/design.md`. Extend the token system rather than hardcoding one-off styles.
- Each phase ends with green CI, its acceptance checklist fulfilled, and a git tag (`m0`, `m1`, …).
- `.env.testing` is committed with a fixed `APP_KEY`. This is intentional and not a secret leak: it only encrypts ephemeral session/cookie data in CI and local test runs against a throwaway test database, never production data.
