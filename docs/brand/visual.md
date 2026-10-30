# Visual

Values below are copied from `resources/css/app.css` (source of truth). Machine-readable: [tokens.json](tokens.json), [tokens.css](tokens.css). UI implementation rules: [../design.md](../design.md).

## Architecture

Two tiers: **tier 1 primitives** (raw values, defined once, mode-agnostic) and **tier 2 semantic roles** (`:root` = light, `.dark` = dark) that only `var()`-reference primitives. Components use role utilities (`bg-primary`, `text-live`, `text-muted-foreground`), never primitives or hex. Retint by editing one primitive.

## Primitives (tier 1)

| Group | Token | Value | Note |
|-------|-------|-------|------|
| graphite | `--graphite-975` | `#0c0f12` | dark sidebar |
| | `--graphite-950` | `#0f1215` | dark background |
| | `--graphite-850` | `#171b20` | dark card/popover |
| | `--graphite-800` | `#1e242b` | dark muted/secondary |
| | `--graphite-700` | `#262c33` | dark accent/border |
| | `--graphite-650` | `#2b323a` | dark input |
| text on dark | `--mist-100` | `#e7ecef` | |
| | `--slate-400` | `#8a949e` | dark muted text |
| ink/paper | `--ink-950` | `#14181c` | light text |
| | `--slate-500` | `#5b646e` | light muted text |
| | `--paper-0` | `#ffffff` | |
| | `--paper-50` | `#f7f8fa` | light background |
| | `--paper-100` | `#eef1f4` | |
| | `--paper-150` | `#eaeef2` | |
| | `--paper-200` | `#e1e5ea` | light border |
| | `--paper-250` | `#dce1e7` | light input |
| **signal amber** | `--amber-bright` | `#ffb020` | dark accent + live |
| | `--amber` | `#e8930a` | light live/signal |
| | `--amber-deep` | `#a85a00` | light primary (AA with white) |
| | `--amber-ink` | `#17130a` | text on bright amber |
| status/chart | `--green-bright` / `--green-deep` | `#35c08a` / `#12a150` | |
| | `--gold-bright` / `--gold-deep` | `#e4b34a` / `#c77d0a` | |
| | `--red-bright` / `--red-deep` | `#e5484d` / `#dc2626` | |
| | `--blue-bright` / `--blue-deep` | `#5ea0e0` / `#2f6fb0` | chart only |
| | `--violet-bright` / `--violet-deep` | `#c98bdb` / `#8b5c9e` | chart only |
| | `--rose-bright` / `--rose-deep` | `#e5717a` / `#d1495b` | chart only |

## Semantic roles (tier 2)

| Role | Light | Dark |
|------|-------|------|
| `--background` | `#f7f8fa` | `#0f1215` |
| `--foreground` | `#14181c` | `#e7ecef` |
| `--card` / `--popover` | `#ffffff` | `#171b20` |
| `--primary` | `#a85a00` | `#ffb020` |
| `--primary-foreground` | `#ffffff` | `#17130a` |
| `--secondary` | `#eaeef2` | `#1e242b` |
| `--muted` | `#eef1f4` | `#1e242b` |
| `--muted-foreground` | `#5b646e` | `#8a949e` |
| `--accent` | `#eef1f4` | `#262c33` |
| `--destructive` | `#dc2626` | `#e5484d` |
| `--destructive-foreground` | `#ffffff` | `#ffffff` |
| `--border` | `#e1e5ea` | `#262c33` |
| `--input` | `#dce1e7` | `#2b323a` |
| `--ring` | `#a85a00` | `#ffb020` |
| `--live` | `#e8930a` | `#ffb020` |
| `--ok` | `#12a150` | `#35c08a` |
| `--warn` | `#c77d0a` | `#e4b34a` |
| `--down` | `#dc2626` | `#e5484d` |
| `--chart-1..5` | amber `#e8930a`, green `#12a150`, blue `#2f6fb0`, violet `#8b5c9e`, rose `#d1495b` | amber `#ffb020`, green `#35c08a`, blue `#5ea0e0`, violet `#c98bdb`, rose `#e5717a` |
| `--sidebar` | `#ffffff` | `#0c0f12` |
| `--sidebar-accent` | `#eef1f4` | `#171b20` |
| `--sidebar-border` | `#e1e5ea` | `#1e242b` |

`--accent-foreground`, `--card-foreground`, `--popover-foreground`, `--secondary-foreground` follow `--foreground`; `--sidebar-primary`/`--sidebar-ring` follow `--primary`. Full list in `tokens.json`.

## Amber rationing rule

| Amber means | Never |
|-------------|-------|
| the primary action of a screen (`bg-primary`) | decoration, backgrounds, illustration |
| keyboard focus ring (`--ring`, `focus-visible` only) | a second "highlight" colour |
| live / happening now (`--live`, `LiveIndicator`) | ad-hoc yellow (winner colour aligns to `--live`) |

Light mode uses deep amber `#a85a00` for filled controls (white text, AA) and `#e8930a` for dots/highlights.

## Typography

| Role | Face | Weights | Token | Use |
|------|------|---------|-------|-----|
| Display + body | Space Grotesk | 400/500/600/700 | `--font-sans` | everything except machine data |
| Machine data | JetBrains Mono | 400/500/600 | `--font-mono` | seat labels, ports/IPs, scores, round numbers, timers, QR payloads, IDs; usually `tabular-nums` |

| Scale | Utility |
|-------|---------|
| caption | `text-xs` / `text-sm` |
| body | `text-base` |
| section | `text-lg` / `text-xl` |
| page title | `text-2xl` / `text-3xl tracking-tight` |
| beamer | `text-6xl`+ |

Headings 600/700 with `tracking-tight`; body 400. No third face. Space Grotesk has no italic (no synthetic italics). Fonts load from Bunny Fonts via `laravel-vite-plugin` (`vite.config.ts`), not self-hosted.

## Shape, spacing, depth

| Item | Value |
|------|-------|
| `--radius` | `0.375rem` (6px); `-md` = radius - 2px, `-sm` = radius - 4px |
| Borders | hairlines preferred over shadows |
| Shadows | `shadow-sm` max in app |
| Spacing | Tailwind scale, aligned to a grid; content widths `max-w-3xl` to `max-w-6xl` |

## Motion

| Rule | Source |
|------|--------|
| Live dot pulses (`animate-ping`) with `motion-reduce:animate-none` | `LiveIndicator.vue` |
| Global reduced-motion rule: animation/transition duration `0.01ms`, iteration 1 | `app.css` bottom |
| Celebratory motion (confetti, tombola, scene transitions) only on beamer | design.md "Beamer" |
| GAP: no named duration/easing tokens | not defined anywhere |

## Iconography

Lucide via `@lucide/vue` (`qcode.json` detected; shadcn-vue default). GAP: no stroke-width or size convention documented beyond component defaults.

## Imagery

| Rule | Source |
|------|--------|
| `width`/`height` or `aspect-ratio` + `loading="lazy"` off-screen | design.md "Quality floor" |
| Uploads (team logos, gallery) live in Laravel Storage | CLAUDE.md |
| GAP: no photographic/illustration style guide, no hero imagery | none exists |

## Accessibility floor

Responsive to 375px; visible keyboard focus (amber ring); `prefers-reduced-motion` respected; AA contrast; all four states (empty/loading/error/normal) on every data surface; skip link. See [../design.md](../design.md) "Quality floor".

## Known inconsistency

`resources/views/app.blade.php` pre-paint inline style uses `oklch(1 0 0)` / `oklch(0.145 0 0)` for `html` background (starter-kit leftover), not `--background` (`#f7f8fa` / `#0f1215`). Produces a brief flash colour mismatch. Recorded in [sources.md](sources.md); not changed here.
