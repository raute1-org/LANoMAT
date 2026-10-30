# Sources and open gaps

Repo HEAD when written: `e43d909` (origin/main). Token commits: `ae5b112` (Signalpult M13.1), `e0c04df` (two-tier tokens), `e26f14c` (a11y hardening).

## Evidence

| Claim | Source |
|-------|--------|
| Design idea, rationing rule, typography, radius, beamer, states, quality floor | `docs/design.md` |
| All colour values, radius, font stacks, reduced-motion rule | `resources/css/app.css` |
| Fonts + weights, Bunny Fonts delivery | `vite.config.ts`, `resources/views/app.blade.php` (`@fonts`) |
| LiveIndicator signature | `resources/js/components/LiveIndicator.vue` |
| Logo wordmark, mark | `AppLogo.vue`, `AppLogoIcon.vue`, `docs/logo.md` |
| Favicon and touch icon | `public/favicon.svg`, `public/favicon.ico`, `public/apple-touch-icon.png`, `docs/logo.md` |
| Name spelling | `README.md`, `CLAUDE.md`, `AdminPanelProvider.php` (`brandName`), `lang/de/discord.php` |
| Tagline | `lang/de/welcome.php` |
| Participants login-gated; beamer/overlay public | `routes/web.php` (`auth` groups, "Public beamer screen", "Public, no-auth, transparent-background OBS overlay") |
| Beamer/overlay forced dark/transparent | `components/scenes/SceneFrame.vue`, `components/overlay/OverlayFrame.vue` |
| Filament primary colour | `app/Providers/Filament/AdminPanelProvider.php` |
| Voice rules and examples | `lang/de/registration.php`, `teams.php`, `jukebox.php`, `events.php`, `notifications.php`, `discord.php`, `infoscreen.php`, `seating.php`, `tournaments.php` |
| Discord embed structure | `app/Modules/Discord/Support/MatchEmbed.php` |
| No mail templates | `grep toMail|Mailable app/` (none) |
| Skip link | `resources/js/layouts/app/AppSidebarLayout.vue` |
| Icons (lucide) | `qcode.json` detection, `components/ui/sonner/Sonner.vue` imports `@lucide/vue` |

## Open gaps and questions for the owner

| # | Gap / question |
|---|----------------|
| 1 | Standalone wordmark SVG and lockup files (the mark, its rules and all icon assets are done, see `docs/logo.md`) |
| 2 | Auth layouts colour the mark with hard-coded classes rather than tokens; align with `AppLogo.vue` |
| 3 | OG / social image and social avatars; no `og:` meta tags |
| 4 | Print: posters, flyers, badges, tickets (PDF) have no templates |
| 5 | Email: no mail templates exist; decide if/how mail is branded |
| 6 | Admin panel (Filament) only carries primary colour; should it adopt Signalpult fonts/radius/dark palette? |
| 7 | Discord embeds set no colour; should embeds use amber `#ffb020`? |
| 8 | Name of the design system and tagline: "Signalpult" documented; English tagline not owner-approved |
| 9 | Motion: no duration/easing tokens; icon size/stroke convention undocumented |
| 10 | `app.blade.php` pre-paint background uses `oklch(1 0 0)` / `oklch(0.145 0 0)`, not `--background` |
| 11 | Voice: Check-in vs Einchecken, Event vs LAN; some German strings may be hardcoded outside `lang/` (not audited) |
| 12 | `qcode.json` `surface`: set to `internal` because every participant/event page is behind `auth` (Discord login); the guest landing, beamer, overlays and the public recap/gallery variants are public. Confirm `internal` is the right class, or use `public` for those parts |
