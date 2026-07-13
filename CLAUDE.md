# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

LANoMAT v2 — a modular LAN party management tool (events, registration/tickets, seating, tournaments with brackets, schedule, catering, voting, LFG, infoscreens, Discord integration, Mumble voice, game servers via Pelican). Complete rebuild of the previous Nuxt/NestJS system as a **Laravel monolith**.

## Current state

**Pre-scaffold: the application does not exist yet.** This repo currently contains only the approved design and implementation plans. All work follows them — read before building anything:

- `docs/superpowers/specs/2026-07-13-lanomat-v2-rebuild-design.md` — approved architecture & module design
- `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` — master roadmap, phases M0–M6 with task numbers
- `docs/superpowers/plans/2026-07-14-m0-fundament.md` — fully detailed, executable plan for phase M0

Detailed plans for M1–M6 are derived from the roadmap just-in-time at each phase start, in the same format as the M0 plan. Update the roadmap when a phase produces new insights (it is a living document).

## Guiding principle: state of the art, 2026 best practices

Every technology decision and implementation must follow **current (2026) best practices** — not patterns memorized from older training data:

- **Verify before you build.** Before installing a package, scaffolding, or using a framework API, check the current official docs (WebFetch/WebSearch) for the latest stable version and recommended approach. If a plan step conflicts with current official guidance, follow the docs and note the deviation in the commit message.
- **Prefer first-party.** Laravel ecosystem first (Reverb over third-party websockets, Socialite, Pest, Pint, official starter kits). Third-party only when there is no first-party equivalent.
- **No legacy patterns.** No deprecated APIs, no packages that are unmaintained or superseded, no workarounds for problems the framework already solves.

## Target stack

PHP 8.4 · Laravel 13 · Filament v5 (admin panel at `/admin`) · Inertia v2 + Vue 3 + Tailwind v4 + shadcn-vue (participant UI) · Reverb (websockets) · Pest (tests) · PostgreSQL 16 · Redis · Docker Compose · Mumble (voice, via Ice REST sidecar) · Pelican Panel (game servers)

External systems (Discord, Mumble, Pelican) are accessed **only** through contracts (`DiscordClient`, `MumbleClient`, `PelicanClient`) with fake implementations for tests — never call real APIs in tests.

## Commands (once scaffolded in M0)

```bash
docker compose up -d          # postgres + redis (dev)
composer run dev              # app + vite dev server
composer check                # pint --test, larastan (level 8), pest — must be green after every task
./vendor/bin/pest --filter=X  # run a single test
npm run lint && npm run build # frontend checks
php artisan lanomat:install --admin-discord-id=<id>   # migrate + create admin
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
- Each phase ends with green CI, its acceptance checklist fulfilled, and a git tag (`m0`, `m1`, …).
