# Sources and open gaps

Repo HEAD when written: `e43d909` (origin/main). Token commits: `ae5b112` (Signalpult M13.1), `e0c04df` (two-tier tokens), `e26f14c` (a11y hardening).

## Evidence

| Claim | Source |
|-------|--------|
| Design idea, rationing rule, typography, radius, beamer, states, quality floor | `docs/design.md` |
| All colour values, radius, font stacks, reduced-motion rule | `resources/css/app.css` |
| Fonts + weights, Bunny Fonts delivery | `vite.config.ts`, `resources/views/app.blade.php` (`@fonts`) |
| LiveIndicator signature | `resources/js/components/LiveIndicator.vue` |
| Logo tile/wordmark, mark glyph | `AppLogo.vue`, `AppLogoIcon.vue` |
| Favicon colour `#FFB020`; ICO/PNG stock | `public/favicon.svg`; `file public/favicon.ico`, `public/apple-touch-icon.png` (viewed) |
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
| 1 | Is the stock hex-cube glyph the intended LANoMAT logo, or a placeholder from the Laravel starter kit? |
| 2 | Logo rules: minimum size, clear space, allowed backgrounds; standalone wordmark and lockup files |
| 3 | `favicon.ico` and `apple-touch-icon.png` are still stock Laravel (red); replace |
| 4 | OG / social image and social avatars; no `og:` meta tags |
| 5 | Print: posters, flyers, badges, tickets (PDF) have no templates |
| 6 | Email: no mail templates exist; decide if/how mail is branded |
| 7 | Admin panel (Filament) only carries primary colour; should it adopt Signalpult fonts/radius/dark palette? |
| 8 | Discord embeds set no colour; should embeds use amber `#ffb020`? |
| 9 | Name of the design system and tagline: "Signalpult" documented; English tagline not owner-approved |
| 10 | Motion: no duration/easing tokens; icon size/stroke convention undocumented |
| 11 | `app.blade.php` pre-paint background uses `oklch(1 0 0)` / `oklch(0.145 0 0)`, not `--background` |
| 12 | Voice: Check-in vs Einchecken, Event vs LAN; some German strings may be hardcoded outside `lang/` (not audited) |
| 13 | `qcode.json` `surface`: set to `internal` because every participant/event page is behind `auth` (Discord login); the guest landing, beamer, overlays and the public recap/gallery variants are public. Confirm `internal` is the right class, or use `public` for those parts |
