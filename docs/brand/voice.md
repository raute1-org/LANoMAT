# Voice

UI copy is **German** (`lang/de/*.php`, Vue pages); code, comments, commits and docs are English (CLAUDE.md). All user-facing strings go through `lang/de/` keys.

## Address and tone

| Rule | Example (real) | Source |
|------|----------------|--------|
| Informal **du**, never Sie | "Du bist für dieses Event bereits angemeldet." | `lang/de/registration.php` |
| Plain, short, active voice | "Zeige diesen Code beim Check-in vor Ort." | `registration.php` |
| Sentence case for titles, buttons, labels | "Zum Event anmelden", "Team verlassen", "Ticket wählen" | `registration.php`, `teams.php` |
| Button label = what happens = toast text | button "Jetzt anmelden"; status "Status geändert auf: :status" | `registration.php`, `events.php` |
| Errors say what is wrong and the next step | "Music Assistant ist gerade nicht erreichbar. Bitte versuche es später erneut." | `jukebox.php` |
| Empty states invite action | "Noch nichts in der Warteschlange — durchsuche die Bibliothek und leg den nächsten Track auf." | `jukebox.php` |
| Confirm destructive actions with a full question | "Anmeldung wirklich stornieren?" | `registration.php` |
| Exclamation mark only for good news announcements | "Die Anmeldung für :event ist jetzt geöffnet!" | `discord.php`, `events.php` |
| No emoji, no hype, no apology | design.md "error: never a vague apology" | `docs/design.md` |
| Typographic quotes `„…“` for user-supplied titles | `- „:title“ (:game) von :user` | `discord.php` |
| Placeholders end with ` …` | "Track suchen …" | `jukebox.php` |

## Avoid / use (illustrative "avoid", real "use")

| Avoid (illustrative) | Use (real string) |
|----------------------|-------------------|
| "Oops, something went wrong!" | "Der Kapitän muss die Teamleitung übergeben, bevor er das Team verlassen kann." |
| "Keine Daten" | "Noch keine vergangenen Events." |
| "Sie sind bereits registriert." | "Du bist für dieses Event bereits angemeldet." |
| "Submit" / "OK" | "Jetzt anmelden" |
| "Not found" | "Turnier nicht gefunden." |

## Surfaces

| Surface | Rules |
|---------|-------|
| Bell notifications (`lang/de/notifications.php`) | `title` is a short noun phrase ("Anmeldung bestätigt"), `body` one sentence naming the event ("Deine Anmeldung für :event ist bestätigt."). Bell is the truth; Discord DM mirrors. |
| Discord slash commands | descriptions are short infinitive/noun phrases ("Turniere des aktuellen Events anzeigen"); replies are plain text lines; list items start `- `; help text lists `/command <arg> – description` |
| Discord embeds (match channel) | title `:entry1 vs. :entry2`; description starts "Willkommen in eurem Match-Channel!" then link lines; voice/server links under a bold heading |
| Discord announcements | one sentence, event name, exclamation allowed for openings |
| Beamer | single short line per scene; labels as in `lang/de/infoscreen.php` ("Nächste Matches", "Sieger-Einblendung") |

## Glossary (fixed terms, from `lang/de/`)

| Concept | Use | Do not use |
|---------|-----|------------|
| Sign-up for an event | **Anmeldung** (verb: anmelden) | Registrierung |
| Arrival confirmation | **Check-in** / "Eingecheckt" | Einchecken as noun |
| Tournament | **Turnier** (bracket: **Turnierbaum** / "Bracket" in Discord) | Wettbewerb |
| Seat / seating chart | **Sitzplatz**, **Sitzplan** (short label "Platz") | Seatmap in UI copy |
| Organisers | **Orga** (helpers: **Helfer**) | Admin (for people), Veranstalter |
| Team captain | **Kapitän** | Owner, Leader |
| Looking for group | **Mitspieler-Suche** | LFG (slash command name `/lfg` is the exception) |
| Info screen | **Beamer** (scene = **Szene**, "Infoscreen-Szene" in admin) | Projektor |
| Game server | **Spielserver** | Gameserver |
| Event | **Event** (marketing: **LAN**, "LAN-Party") | Veranstaltung |
| Now playing | **Läuft gerade** | |
| Notifications | **Benachrichtigungen** (bell) | |
| Live | **Live** | Online |

## Noted inconsistencies (do not copy)

| Issue | Where |
|-------|-------|
| `Check-in` vs `Einchecken` used for the same action | `discord.php` / `tournaments.php` |
| Some labels are "Event", others "LAN"/"LAN-Party" | `events.php` |
| German strings partly hardcoded outside `lang/` | not audited here; GAP |
