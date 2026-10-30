# Logo and assets

Never recreate, redraw or "improve" the mark. Use the files below as they are, or raise the gap with the owner.

## Inventory (evidence in repo)

| Asset | Path | What it is | Colour | Status |
|-------|------|------------|--------|--------|
| Logo mark (component) | `resources/js/components/AppLogoIcon.vue` | inline SVG, `viewBox 0 0 40 42`, `fill="currentColor"` | inherits (`text-sidebar-primary-foreground`) | **stock Laravel starter-kit glyph** (GAP: not LANoMAT-specific) |
| Logo lockup (component) | `resources/js/components/AppLogo.vue` | 32px (`size-8`) rounded-md tile in `bg-sidebar-primary` + mark (`size-5`) + wordmark "LANoMAT" text (`text-sm font-semibold`) | tile = amber primary | used in sidebar, auth layouts, welcome, PublicShell |
| Favicon (SVG) | `public/favicon.svg` | `166x166` hex-cube glyph, same shape as the mark | fill `#FFB020` (recoloured in `ae5b112`) | on-brand colour, stock shape |
| Favicon (ICO) | `public/favicon.ico` | 32x32, 1 icon | stock Laravel (not recoloured) | **GAP** |
| Apple touch icon | `public/apple-touch-icon.png` | 166x166 PNG | stock Laravel red `#FF2D20`-family on white | **GAP: off-brand** |
| Fonts | none self-hosted; loaded from Bunny Fonts via `vite.config.ts` (`bunny('Space Grotesk', 400/500/600/700)`, `bunny('JetBrains Mono', 400/500/600)`), injected by `@fonts` in `resources/views/app.blade.php` | | | ready (external dependency) |
| Tokens | `resources/css/app.css` | see [visual.md](visual.md) | | ready |
| OG / social image | none (`app.blade.php` has no `og:` meta) | | | **GAP** |
| Print templates, posters, flyers | none | | | **GAP** |
| Sponsor logos | beamer `SceneSponsors.vue` renders uploaded sponsor assets (Storage); not brand assets | | | n/a |

## Usage rules evidenced by the files

| Rule | Evidence |
|------|----------|
| Mark sits inside a rounded (6px, `rounded-md`) amber tile, mark in `primary-foreground` colour | `AppLogo.vue` |
| Wordmark is the text "LANoMAT" in Space Grotesk 600, set beside the tile, `text-sm` | `AppLogo.vue` |
| Mark is monochrome (`currentColor`); never multi-colour | `AppLogoIcon.vue` |
| Favicon is amber `#FFB020` on transparent | `public/favicon.svg` |
| Light/dark: tile colour follows `--sidebar-primary` (`#a85a00` light, `#ffb020` dark) | `app.css` |

## Open gaps (do not invent)

| Gap | Needed decision |
|-----|-----------------|
| Is the stock hex-cube glyph the intended LANoMAT mark, or a placeholder? | owner |
| Minimum size, clear space, background restrictions | none recorded |
| Standalone wordmark (SVG) and horizontal/stacked lockups | none exist |
| Replace `favicon.ico` and `apple-touch-icon.png` (still stock red) | asset needed |
| OG image (1200x630), social avatars | asset needed |
| Print / poster templates | none exist |
