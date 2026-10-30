# Surfaces

Shared base for all surfaces: tokens in [visual.md](visual.md), voice in [voice.md](voice.md), implementation rules in [../design.md](../design.md).

| Surface | Route / code | Theme | Rules | Status |
|---------|--------------|-------|-------|--------|
| Participant web app | Inertia pages, `AppLayout` | light + dark, system default (`AppearanceTabs`) | calm; all four states; role utilities only; one amber primary per screen. See design.md "Palette", "States", "Quality floor" | ready |
| Guest landing | `/` (`Welcome.vue`, `lang/de/welcome.php`) | light/dark | only event-free content + Discord login; all event data is login-gated (`routes/web.php`) | ready |
| Admin panel | `/admin`, Filament v5 (`AdminPanelProvider`) | Filament default | `brandName('LANoMAT')`, primary `#a85a00` only. Fonts, radius, dark palette are **Filament defaults, not Signalpult** | partial: GAP |
| Beamer | `/screen/{event}` (`Screen/Show.vue`, `components/scenes/*`) | forced `dark` (`SceneFrame.vue`) | near-black canvas, huge type (`text-6xl`+), full-bright amber, one focal thing per scene, confetti/tombola allowed, winner colour = `--live`. See design.md "Beamer". No-auth, no app chrome | ready |
| Beamer remote | `/screen/{event}/control` | app theme | helper tool, app rules apply | ready |
| OBS overlays | `/overlay/tournament/{id}/bracket`, `/overlay/match/{id}/scoreboard` | forced `dark`, `bg-transparent` (`OverlayFrame.vue`) | transparent background (never `bg-background`), render-only, public data only, no chrome | ready |
| Discord messages / embeds | `app/Modules/Discord`, `lang/de/discord.php` | Discord client | plain text + minimal embed (title + description); no embed colour set (GAP); German, see [voice.md](voice.md) | partial |
| In-app notifications | bell, `lang/de/notifications.php` | app theme | title + one-line body; category prefs | ready |
| Email | none (no `toMail` / Mailable in `app/`) | n/a | GAP: no mail templates exist; if added, reuse tokens (light palette, Space Grotesk fallback to system sans) and voice | gap |
| Print / posters / flyers | none | n/a | GAP | gap |
| Social media / OG | none | n/a | GAP | gap |

## Per-surface reminders

| Surface | Must | Must not |
|---------|------|----------|
| Web app | use `bg-primary`, `text-live`, `font-mono` for machine data; `LiveIndicator` for live state | hardcode hex, add a third font, use amber decoratively |
| Beamer | be legible at distance, high contrast | show more than one focal thing, use ad-hoc yellow |
| Overlay | stay transparent | add backgrounds or navigation |
| Discord | stay short, du-form, German | add emoji hype, English copy |
