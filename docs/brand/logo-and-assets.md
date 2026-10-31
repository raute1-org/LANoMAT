# Logo and assets

Never recreate, redraw or "improve" the mark. Use the files below as they are, or raise the gap with the owner.

## Inventory (evidence in repo)

The mark is an L of five nodes with the foot tip lit amber. Geometry, clear space, minimum sizes, colour rules, do/don't and the regeneration recipe for raster assets live in [`docs/logo.md`](../logo.md); they are not repeated here.

| Asset | Path | What it is | Status |
|-------|------|------------|--------|
| Logo mark (component) | `resources/js/components/AppLogoIcon.vue` | inline SVG, `viewBox 0 0 24 24`; structure `currentColor`, amber node `fill-primary` | ready |
| Logo lockup (component) | `resources/js/components/AppLogo.vue` | mark (`size-7`, no container behind it) + wordmark "LANoMAT" (`font-semibold`), sidebar header | ready |
| Standalone mark | `public/logo-mark.svg` | canonical geometry, light/dark via `prefers-color-scheme` | ready |
| Favicon (SVG) | `public/favicon.svg` | same as `logo-mark.svg` | ready |
| Favicon (ICO) | `public/favicon.ico` | 16/32/48, light mark + bright amber on transparent | ready |
| Apple touch icon | `public/apple-touch-icon.png` | 180x180, opaque dark graphite, mark at 60% | ready |
| Filament brand | `resources/views/filament/brand.blade.php` | derives from `logo-mark.svg`, adds the admin badge; favicon set in `AdminPanelProvider` | ready |
| Parity guard | `tests/Feature/ShellParity` | asserts `AppLogoIcon.vue` and `favicon.svg` are identical to `logo-mark.svg` | ready |
| Fonts | none self-hosted; loaded from Bunny Fonts via `vite.config.ts` (`bunny('Space Grotesk', 400/500/600/700)`, `bunny('JetBrains Mono', 400/500/600)`), injected by `@fonts` in `resources/views/app.blade.php` | | ready (external dependency) |
| Tokens | `resources/css/app.css` | see [visual.md](visual.md) | ready |
| OG / social image | none (`app.blade.php` has no `og:` meta) | | **GAP** |
| Print templates, posters, flyers | none | | **GAP** |
| Sponsor logos | beamer `SceneSponsors.vue` renders uploaded sponsor assets (Storage); not brand assets | | n/a |

## Usage rules

Mark rules (clear space, minimum size, one-colour variant, backgrounds, no container/outline/gradient) are defined in [`docs/logo.md`](../logo.md). Additional facts evidenced by the files:

| Rule | Evidence |
|------|----------|
| Wordmark is the text "LANoMAT" in Space Grotesk 600, set beside the mark | `AppLogo.vue`, `docs/logo.md` |
| The amber node follows the `--primary` token (`#a85a00` light, bright amber `#ffb020` dark) | `AppLogoIcon.vue`, `app.css` |
| Change the circles in `logo-mark.svg`, `favicon.svg` and `AppLogoIcon.vue` together | parity test |

## Open gaps (do not invent)

| Gap | Needed decision |
|-----|-----------------|
| Standalone wordmark (SVG) and horizontal/stacked lockups | none exist (wordmark is live text beside the mark) |
| Auth layouts (`AuthSimpleLayout`, `AuthCardLayout`, `AuthSplitLayout`) colour the mark with hard-coded classes (`text-black`/`text-white`/foreground) instead of the sidebar tokens | not yet aligned with `AppLogo.vue` |
| OG image (1200x630), social avatars | asset needed |
| Print / poster templates | none exist |
