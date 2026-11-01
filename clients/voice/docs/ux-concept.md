# LANoMAT desktop client: UX concept for participants

Binding for every screen of `clients/voice/app`. Written from the seat of a participant at a LAN party: they sit
at their PC, have a game and a headset, the event is loud and busy, and they want to play, not to administer.
The design system is the same as the website (`docs/design.md`, "Signalpult"); this document says what the
client shows, when and how little.

## Principles

1. **One question per screen: "What do I do now?"** The first screen is not a menu. It answers what matters in
   the next minutes (your match, a vote that closes, your food) and offers the one next action as a big button.
2. **Context beats navigation.** Things appear when they are relevant and disappear when they are not. A
   feature the event does not use is not shown at all (the client reads the enabled modules from the server).
3. **Few places, plain words.** Five tabs at most, German everyday words, no jargon (not "Room", "SFU",
   "Provisioning": "Sprachraum", "Server", "Bestellung"). Every list has an empty state that says what to do.
4. **Never lose voice.** Push-to-talk, mute and the current room are reachable from every screen and from the
   overlay and tray, even while another tab is open or the window is hidden.
5. **Interrupt rarely, and only for the user's own things.** Notifications default to what concerns *me*:
   my match is ready, my food is ready, a vote I have not cast closes soon, a direct message. Everything else
   is a quiet badge. Quiet automatically while I talk or am in a game window (do-not-disturb), with a one-click
   "Ruhe" toggle in the tray.
6. **One tap to play.** From "your match is ready" to being in the right voice room with the game's join
   command copied or launched is one button. Missing game: say how to get it (LanCache hint) in the same card.
7. **Every state is designed:** loading, empty, error (with a retry and the reason in plain words), offline
   (the app says the server is unreachable and keeps trying; voice stays up as long as the SFU connection does).
8. **Safe defaults, no setup maze.** First run: connect (code), then a 20-second guided check: pick headset,
   see the input level move, hear a test tone, choose push-to-talk key or open mic. Everything else has a
   sensible default and lives in Einstellungen.
9. **Respect the person:** keyboard operable, readable at 125% scale, high contrast available, reduced motion,
   no sound effects unless chosen, no dark patterns, and no data collection beyond what the server already has.

## Navigation: five tabs

| Tab | Content | Badge |
| --- | --- | --- |
| **Jetzt** | The prioritised "now" cards (below). Default screen. | number of cards needing action |
| **Sprache** | Voice rooms (general + my match rooms), the live room with roster, mute and big push-to-talk; text chat of the current room beside it | unread chat mentions |
| **Spielen** | My matches and the tournament view (my bracket position, check-in), game servers (event servers and the permanent ones) with join and copy | match ready / check-in open |
| **Essen** | Menu and ordering, my orders and their status | order status change |
| **Mehr** | Abstimmungen, Dateien (download, upload), Benachrichtigungen (history, categories), Einstellungen, Gerät/Konto | open votes count |

Hidden when the module is off for the event: the tab or the Mehr entry disappears; with one module off
nothing else moves. Orga/admin get the diagnostic panel under Einstellungen only.

## "Jetzt": the cards, most urgent first (maximum five shown, rest under "Mehr anzeigen")

1. **Check-in / match ready**: "Dein Match startet in 4 Min gegen *Team X*" with **Beitreten** (voice room of the
   match) and, when the server is ready, **Server beitreten** (launch or copy). Lines: map/mode, address,
   "Spiel fehlt? So kommst du ran".
2. **Abstimmung läuft**: question, the options as buttons right in the card, closes in N min.
3. **Essen**: ordering window closes in N min with the favourite item as a one-tap re-order; or "Deine Bestellung
   ist fertig" with the pick-up info.
4. **Dein Voice-Raum**: current room, who talks, mute and push-to-talk (always shown when connected).
5. **Neu für dich**: the latest unread notifications (at most three), each with its action.
When nothing needs attention, the screen says so in one calm line and shows the schedule's next item.

## Game servers

* List per event plus the permanent servers (Minecraft, UT2004 and so on), each as a card: name, game, status
  light (running, starting, offline), players/slots, map.
* Actions in order of importance: **Beitreten** (launches `steam://connect/<ip>:<port>` when the game has a
  Steam join link and Steam is installed; else copies `<ip>:<port>` and shows it in a mono-spaced line),
  **Adresse kopieren**, **So kommst du ran** (install hint, LanCache link). Password/connect string included
  where the server has one, never for servers the user may not see.
* Match servers appear on "Jetzt" and in the match; other participants' match servers are not listed.
* Offline server: say so and, for permanent servers, when it was last seen. No "join" button for an offline server.

## Notifications

* Source: the server's in-app notifications (bearer API + realtime). The client turns them into system
  notifications for the categories the user chose; defaults on: match, food, direct mention, vote closing; off:
  everything else (it still lands in the bell list).
* Grouping: at most one system notification per category per minute; the rest is a count.
* Do-not-disturb: while push-to-talk is held or the user talks, while a fullscreen window is active (best
  effort), and by the tray toggle. Missed ones are in the list.
* Every notification opens the matching screen or card, never just the app.

## Chat

* Text next to voice in the same screen; per room history, newest at the bottom, send with Enter, 500 characters,
  the server's rate limit shown as a calm hint, not an error wall. Moderators see delete.
* Mentions of me are highlighted and raise a notification (category "direct").

## Files

* List with name, size, who, when; **Herunterladen** (signed link from the file host: fastest source on the LAN,
  progress and the final path shown), and **Hochladen** by drag and drop or picker with resumable upload,
  progress, pause/continue, and the plain-words moderation note "Wird von der Orga geprüft".
* Show the user's quota as one bar, not numbers first.

## Voting and ordering

* A vote is one card: question, options, one tap to vote, visible confirmation, results as the web page rules say.
* Ordering: menu grouped as on the web, quantity steppers, a running total, one **Bestellen** button, a clear
  confirmation, order status as a short timeline, cancel while allowed with the reason when it is not.

## Quality bar

* Latency of the interface: every list shows cached data at once and refreshes in the background.
* Realtime where it matters (chat, notifications, server status); polling elsewhere at a calm interval.
* All strings German in one place, ready for later translation.
* A short "Hilfe" sheet per tab (what is this, what can I do) instead of tooltips everywhere.
