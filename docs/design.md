# LANoMAT Design System — "Signalpult"

The visual identity for LANoMAT (M13 design polish). One idea runs through everything:

> **The app is a quiet control desk. The beamer is the stage.**

The participant and admin UI stays calm, precise and out of the way (Rams: *unobtrusive*, *as little design as possible*). All the loudness — colour, scale, motion, celebration — is spent on the `/screen/{event}` beamer, where it belongs. A single **signal-amber** accent is the through-line: it always means *primary action* or *live/now*, nothing else.

This document is the source of truth. Every colour and type decision derives from the tokens in `resources/css/app.css`; don't hardcode hex values in components — use the token utilities (`bg-primary`, `text-muted-foreground`, `text-live`, `font-mono`, …).

## Palette

Graphite neutrals + one signal accent + a small operational-status set. Tokens are shadcn-vue roles, so changing them here re-skins the whole app.

**Two-tier architecture** (in `resources/css/app.css`): tier 1 is **palette primitives** — raw values each defined exactly once (`--amber-bright`, `--graphite-950`, `--paper-50`, `--green-deep`, …). Tier 2 is the **semantic roles** (`:root` = light, `.dark` = dark) which only ever `var()`-reference a primitive, never a raw hex. To retint the brand, edit one primitive (e.g. `--amber-bright`) and it propagates to every role that references it (primary, ring, live, sidebar, chart-1). Components must use the semantic role utilities (`bg-primary`, `text-live`, …), not primitives or hex.

### Dark (the signature look)

| Role | Token | Hex | Use |
|------|-------|-----|-----|
| Background | `--background` | `#0F1215` | app canvas (graphite ink) |
| Surface | `--card` / `--popover` | `#171B20` | cards, popovers, sheets |
| Muted surface | `--muted` / `--secondary` | `#1E242B` | quiet fills |
| Hover surface | `--accent` | `#262C33` | ghost/hover, dropdown items |
| Text | `--foreground` | `#E7ECEF` | primary text |
| Muted text | `--muted-foreground` | `#8A949E` | metadata, captions |
| Border | `--border` | `#262C33` | hairlines, dividers |
| **Primary / accent** | `--primary` / `--ring` / `--live` | **`#FFB020`** | primary buttons, focus ring, live state |
| on-primary | `--primary-foreground` | `#17130A` | text on amber |
| OK | `--ok` | `#35C08A` | healthy status |
| Warn | `--warn` | `#E4B34A` | degraded status |
| Down / destructive | `--down` / `--destructive` | `#E5484D` | outage, destructive |

### Light (coherent daytime variant)

Cool paper (not warm cream — that's a template default we avoid). Primary is a **deeper** amber (`#A85A00`) so filled controls keep white text at AA contrast; the bright signal amber lives on as `--live` (`#E8930A`) for dots/highlights.

| Role | Hex |
|------|-----|
| Background `--background` | `#F7F8FA` |
| Surface `--card` | `#FFFFFF` |
| Text `--foreground` | `#14181C` |
| Muted text `--muted-foreground` | `#5B646E` |
| Border `--border` | `#E1E5EA` |
| Primary `--primary` (white text) | `#A85A00` |
| Live/signal `--live` | `#E8930A` |
| OK / Warn / Down | `#12A150` / `#C77D0A` / `#DC2626` |

**Rule:** amber is rationed. If everything is amber, nothing is. Use it for *the* primary action on a screen and for genuinely-live state — never as decoration.

## Typography

Two faces, two jobs. No third face.

- **Space Grotesk** (`--font-sans`, weights 400/500/600/700) — display **and** body. A precise geometric grotesk with enough character to not read as a neutral default. Headings 600/700 with tight tracking (`tracking-tight`); body 400.
- **JetBrains Mono** (`--font-mono`, 400/500/600) — reserved for **machine data**: seat labels, switch ports / IPs, scores, match/round numbers, timers, QR payloads, `lock_version`, IDs. This is a *structural* choice, not decoration: LANoMAT's world is literally ports, seats and clocks, so numbers-as-mono encodes something true (Rams: *structure is information*). Apply with `font-mono` (+ often `tabular-nums`).

Type scale (Tailwind): captions `text-xs`/`text-sm`, body `text-base`, section `text-lg`/`text-xl`, page title `text-2xl`/`text-3xl tracking-tight`. Beamer scenes go much larger (`text-6xl`+) — that's the stage.

## Shape & density

- `--radius: 0.375rem` (6px) — crisper than the shadcn 8px default; control-desk precision.
- Hairline borders over shadows. Shadows stay subtle (`shadow-sm` max) in the app.
- Generous, consistent spacing from the Tailwind scale; align to a grid. Constrained content widths (`max-w-3xl`…`max-w-6xl`) as already used.

## The signature: live-state treatment

The one memorable element. Anything that is *happening now* — a live match, an open check-in window, a running poll, a degraded service — is marked consistently:

- a small **signal dot** in `--live` (or `--ok`/`--warn`/`--down` for health), gently pulsing (respecting `prefers-reduced-motion` → no pulse, static dot);
- a short **mono label** next to it (`LIVE`, a countdown, a timestamp) in `font-mono` uppercase.

Example intent: `● LIVE  17:42` — amber dot + mono clock. The seating grid and the tournament bracket should read like a control diagram, not a marketing graphic. Provide a small shared component/util for this so it stays identical everywhere.

## States are not optional (Rams: *understandable*, *honest*)

Every list/data surface ships all four states, in the interface's voice (German UI copy via `lang/de`):

- **empty** — an invitation to act, not just muted text ("Noch kein Programm — Orga legt gleich los.");
- **loading** — skeletons (`ui/skeleton`) for async, never a bare jump;
- **error** — says what went wrong and the next step, never a vague apology;
- **success/normal** — the content itself.

No fake progress, no dark patterns. The in-app bell is the truth; Discord mirrors (see "Discord verstärkt, ersetzt nie").

## Quality floor (Rams: *thorough to the last detail* + *environmentally friendly*)

Non-negotiable, unannounced:

- responsive to mobile (test 375px);
- visible keyboard focus everywhere (the amber `--ring`, `focus-visible` only);
- `prefers-reduced-motion` respected by every animation;
- images carry width/height (or `aspect-ratio`) + `loading="lazy"` off-screen → no layout shift;
- "environmentally friendly" for software = lean: small bundles, few dependencies, fast loads, good a11y/contrast (AA), low data/energy use.

## Beamer (the loud surface)

`/screen/{event}` is the exception to "quiet". Near-black canvas, huge type, the amber accent at full brightness, celebratory motion (winner confetti, tombola reveal) — but still disciplined: one focal thing per scene, legible at distance, high contrast. The winner/celebration colour aligns to `--live` amber (not an ad-hoc yellow).

## Rams' ten principles → how they land here

1. **Innovativ** — uses the live web platform deliberately (Reverb live state, Inertia), not novelty for its own sake.
2. **Brauchbar** — serves the 10-minute principle; the shortest path stays shortest.
3. **Ästhetisch** — one calm token system, graphite + a single rationed accent.
4. **Verständlich** — clear hierarchy, all four states, plain German copy.
5. **Unaufdringlich** — the app recedes; content (bracket, schedule, seats) leads.
6. **Ehrlich** — real state, no dark patterns; bell = truth.
7. **Langlebig** — token-based, trend-free, maintainable.
8. **Konsequent bis ins Detail** — focus/keyboard/dark-mode/beamer-legibility.
9. **Umweltfreundlich** (→ Software) — lean bundles, performance, a11y.
10. **So wenig Design wie möglich** — reduction before addition; amber rationed; two faces only.
