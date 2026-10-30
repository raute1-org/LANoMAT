# Identity

## What LANoMAT is

A modular LAN-party management tool (Laravel monolith): events, registration with QR tickets, seating, tournaments with brackets, schedule, catering, voting, LFG, infoscreens, Discord integration, voice, game servers. Source: `CLAUDE.md` "Project", `README.md`.

## Name

| Rule | Value |
|------|-------|
| Spelling | **LANoMAT** (capital L-A-N, lowercase "o", capital M-A-T) |
| Never | "Lanomat", "LAN-o-mat", "LANOMAT" in prose |
| Exceptions (technical, lowercase) | `lanomat:install`, `lanomat.local` (commands, domains, identifiers) |
| In UI | `LANoMAT` as plain 600-weight text next to the mark (`AppLogo.vue`); Filament `brandName('LANoMAT')` |
| Product generation | "LANoMAT v2" appears in repo/README only; user-facing copy says just "LANoMAT" |
| Fallback page title | `config('app.name')` (see `resources/views/app.blade.php`) |

## Positioning

- One-liner in the product (German, `lang/de/welcome.php`): **"Die LAN-Party-Zentrale."**
- Working description (English, docs): "The control desk for your LAN party."  GAP: not an owner-approved tagline; derived from the Signalpult idea.

## Audiences

| Audience | Surface | Needs |
|----------|---------|-------|
| Participants | web app (login-gated, Discord OAuth) | shortest path to seat, ticket, bracket |
| Orga / helpers / admin | web app + `/admin` Filament panel + `/screen/{event}/control` | density, clarity, no surprises |
| Beamer audience (the room) | `/screen/{event}` | legible at distance, one focal thing |
| Discord community | bot messages, slash commands, DMs | short, useful, German |
| Stream/OBS viewers | `/overlay/...` | transparent, render-only |

## Personality

From `docs/design.md`: **"The app is a quiet control desk. The beamer is the stage."**

| Trait | Means | Shows as |
|-------|-------|----------|
| Calm | the app recedes, content leads | graphite neutrals, hairline borders, no decoration |
| Precise | ports, seats, clocks are real | monospace for machine data only |
| Signal, not decoration | colour carries meaning | one rationed amber = primary action or live/now |
| Honest | real state, no dark patterns | bell is the truth, no fake progress |
| Loud only on stage | celebration is spent where it belongs | beamer: huge type, full-bright amber, confetti |
| Plain | clear German, du-form | see [voice.md](voice.md) |

## What the brand is NOT

| Not | Why (source) |
|-----|--------------|
| Gamer-neon, RGB, glitch, skulls | design.md: "as little design as possible", trend-free |
| Warm cream / template-default light mode | design.md: light is cool paper |
| Amber everywhere | design.md: "If everything is amber, nothing is." |
| A third typeface or decorative mono | design.md: "No third face." |
| Loud app chrome | loudness is reserved for `/screen` |
| Corporate / formal ("Sie") | UI copy uses du |
