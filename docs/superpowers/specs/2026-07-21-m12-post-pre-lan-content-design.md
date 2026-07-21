# M12 — Post-/Pre-LAN-Content — Design

**Status:** Approved (2026-07-21). Roadmap milestone #13.

**Goal:** Give people reasons to visit the site *between* and *before* LANs — a per-event photo gallery, an auto-generated recap, light orga news, a pre-event countdown/hype mode, and a "player of the evening" vote. Built on data and modules that already exist (M1 events, M2 registration/seating, M3 tournaments, M4 voting, M5 infoscreen, M6 stats/badges, M11 jukebox).

**Scope (settled with the user):** roadmap items **#15 (Gallery/Recap/News) + #16 (Countdown/Hype mode) + #17 (MVP-of-the-night vote)**. Item **#18 (LAN-bingo/challenges) is deferred** to a later phase (explicitly "kein Muss").

## Guiding constraints (project-wide, binding)

- Code/comments/commits/docs in **English**; all UI copy in **German** via `lang/de/`.
- **Every authorization goes through a Policy**; acting user is always `auth()->user()`, never a client-supplied id.
- **Privilege/state fields are never `$fillable`** (`visibility`, `is_highlight`, poll `status`) — set via Actions/`forceFill`.
- **Modular monolith:** new work in `app/Modules/<Name>/{Models,Actions,Policies,Filament,Http,Support,Events,Console}`; modules talk via events/read-models, never reach into another module's tables.
- **External systems only via contracts + fakes** (n/a here except image/zip libs, which are local — see below).
- **Uploads go to Laravel Storage**, never Base64 in the DB (existing convention).
- **Design system is binding** — "Signalpult" (`docs/design.md`): calm app / loud beamer, rationed signal-amber, `font-mono` for machine data only, all four states (empty/loading/error/normal), `LiveIndicator` for live state, quality floor (responsive/focus/reduced-motion/lazy+sized images/AA). Invoke `frontend-design` before any new UI or beamer scene.
- **Reverb `event.{id}` is PUBLIC** — any broadcast carries only already-public data; the established invariant is `broadcastWith()` returns `[]` and authorized reloads deliver the real payload.
- TDD; Conventional Commits; `composer check` (pint --test, phpstan level 8, pest **sequential** — never `--parallel`) green after every task; frontend gates green for any Vue change.

## Grounding facts (verified in code, 2026-07-21)

- `Event`: `starts_at`/`ends_at` (nullable datetime), `status` (`EventStatus`: Draft → Announced → Registration → Live → Finished → Archived), `location`, `settings` (jsonb), `max_participants`. `isPubliclyVisible()` = status ≠ Draft (used everywhere for public gating → 404).
- `Voting`: `Poll`/`PollOption`/`PollVote`; poll `status` is not `$fillable` (state field). Live polls broadcast `PollUpdated` on `event.{id}` (`.poll.updated`), consumed via `useEventChannel`.
- `Stats`: `LeaderboardQuery` (read-model), `BadgeCalculator` (badges are **computed, never stored** — e.g. `first_win`/`hattrick`/`veteran`), `CompetitorKind` enum, `StatsPageController`.
- Homepage: `/` → `EventPageController@home`.
- Moderation-gate precedent (mirror exactly): M7.3 Files — `FileVisibility` Pending/Approved/Rejected, orga/helper approval, private `local` disk, authorized download route, Filament resource with Freigeben/Ablehnen + `viewAny=isOrga`, draft-event upload 404-gated, per-event/user quota via `pg_advisory_xact_lock`.
- Beamer-scene precedent (mirror exactly): M11 Task 8 — `SceneType` enum case + German `infoscreen.type.<value>` label; `ScenePayload::dataFor` match arm building a **no-PII read-model payload**; a `Scene<Name>.vue` in the loud beamer register registered in `Screen/Show.vue`'s `sceneComponents` map; live updates via a `.<name>.updated` listener on the existing `event.{id}` channel (no new channel).

## Feature 1 — #15a Gallery (`app/Modules/Gallery/`)

**Schema** `event_photos`: `event_id` (FK cascade), `uploaded_by` (FK users, cascade), `path`, `thumb_path`, `width`, `height`, `caption` (nullable), `is_highlight` (bool, default false — orga-curated), `visibility` (`PhotoVisibility` enum: Pending/Approved/Rejected, default Pending), `timestamps`. Index `(event_id, visibility)`.

**Storage & processing:** private `local` disk; served through an **authorized route** (mirrors M7.3 download). **On upload** (in the `UploadPhoto` action): strip EXIF (re-encode) and generate a thumbnail, both via **`intervention/image` v3** — store the EXIF-stripped original + the thumbnail, both private. This adds the **`gd`** extension to `docker/Dockerfile` (both builder + app stages — the QR path deliberately avoided gd, so this is the first image-processing need). `->maxSize` set within the 1 GB PHP limit raised in M11 prod-test.

**Moderation gate (mirror M7.3 exactly):** uploads land `Pending` (invisible to other participants, visible to the uploader + orga/helper); orga/helper approve/reject. `visibility`/`is_highlight` non-`$fillable`, flipped only via Actions. `GalleryPolicy`: `upload` = checked-in participant (or registered — see open detail below), `moderate` = `isHelper` (orga/helper), `view` approved = anyone who can see the event.

**Participant UI** `/events/{event}/gallery`: thumbnail grid (approved only for normal viewers) + lightbox (full-res fetched on click), a mobile-friendly multi-file upload control (only when the viewer may upload), empty/loading/error/normal states, German copy. Uploader sees their own Pending photos with a "wird geprüft" marker.

**Orga/Helfer Filament** `EventPhotoResource`: approval queue (thumbnail, uploader, caption), Freigeben/Ablehnen actions, `is_highlight` toggle, `viewAny=isOrga`-gated. Draft-event upload 404-gated.

**Zip download:** once the event is `Finished`/`Archived`, an authorized route streams a zip of the event's **approved** photos (streamed, no temp file — `intervention`/native `ZipStream`, verify-before-build).

**Infoscreen slideshow scene:** `SceneType::Gallery` — a no-PII beamer payload (approved photo URLs + captions only, via a `GalleryQuery` read-model; module-boundary clean like M11's `JukeboxQueue`) rotating approved photos; `SceneGallery.vue` loud register; registered in `Screen/Show.vue`; refreshes via a `.gallery.updated` listener on `event.{id}` when photos are approved.

## Feature 2 — #15b Recap (`app/Modules/Recap/`)

**Read-model:** pure, IO-free-shaped `RecapProjection::forEvent(Event): RecapBoard` (same discipline as `PresenceProjection`), aggregating **already-public** data from other modules **via their read-models** (never raw tables): podiums/winners + leaderboard (`Stats\LeaderboardQuery`), event counts (participants, tournaments, matches; optionally songs played from M11), top photos (`is_highlight` first, else most-recent approved, via `GalleryQuery`), and the MVP-of-the-night result (#17). No PII beyond public display names.

**Surfaces:** a **public** standalone page `/events/{event}/recap` (event-visibility gated → 404, like the public event page) available when status ∈ {Finished, Archived}; **plus** a beamer `SceneType::Recap` scene (end-of-night recap, loud register, no-PII payload). Both consume `RecapProjection`.

## Feature 3 — #15c News-light (`app/Modules/News/`)

**Schema** `news_posts`: `title`, `body` (text), `published_at` (nullable — draft vs published), `author_id` (FK users), `timestamps`. Global (not per-event). Filament `NewsPostResource` (orga CRUD). Published posts render on the **homepage** (`/` → `EventPageController@home`) as a small "Neuigkeiten / Nächste LAN am …" block. Deliberately minimal — no comments, no per-event scoping, no reactions.

## Feature 4 — #16 Countdown/Hype mode (extends `Events`)

A **status-dependent section** on the existing public event page, active when status ∈ {Announced, Registration} and `starts_at` is in the future. Shows: a **countdown** to `starts_at`; **who's coming** (registration count, reusing existing registration data); the **currently running game-vote** (the event's active M4 poll, if any); **arrival info**. Add a nullable **`Event.arrival_info`** field (text, orga-maintained; `location` already exists). **No new write path** — pure display; the **pre-LAN jukebox wishlist is intentionally out of scope** (deferred), so M11's checked-in-only gate is untouched.

## Feature 5 — #17 MVP-of-the-night vote (extends `Voting` + `Stats`)

Reuse the **Voting** module: one `Poll` per event flagged as the MVP poll (a `kind`/flavour marker so Recap + `BadgeCalculator` can find it), options = event participants (auto-seeded helper or orga-picked — see open detail). After the last tournament, orga opens it; the community votes (existing Voting flow + `event.{id}` live updates); orga closes it → winner. The reveal reuses the **M5.8 show-draw** (beamer tombola-style reveal). `BadgeCalculator` gains a computed **`mvp_of_the_night`** badge (derived from the closed MVP poll's winner — consistent with badges being computed, never stored). "Barely extra code."

## Cross-cutting

- **New deps (verify-before-build in the plan):** `intervention/image` v3 (thumbnails + EXIF strip) + `gd` in `docker/Dockerfile`; a streamed-zip approach for the gallery download. Confirm current stable versions/APIs against official docs before installing.
- **Design:** invoke `frontend-design` before the gallery UI, recap page, and each beamer scene; grid/recap calm app register, beamer scenes loud.
- **Tests:** feature tests for the moderation gate (pending invisible to others, approve/reject, draft-404), authorized photo/zip routes, the pure `RecapProjection`/`GalleryQuery` read-models (exhaustive, like `PresenceProjection`), no-PII beamer payloads (assert absence of PII keys), the countdown-mode gating, and the MVP-poll→badge path.

## Open details (decide at plan time, non-blocking)

- Gallery `upload` policy: checked-in participants only vs. any registered participant (gallery submission is lower-risk than jukebox; likely **registered** so photos can be added during/after the LAN even without an active check-in). Default: registered participant of the event.
- MVP poll options: auto-seed from event participants vs. orga-picked shortlist. Default: orga opens with an auto-seeded participant list, editable.
- "Songs played" count in Recap: include only if cheap to derive from M11 `jukebox_items` (status Played) via a Jukebox read-model; otherwise omit.

## Task decomposition (estimate, ~13–15 tasks)

Gallery schema/models/policy → upload action (EXIF strip + thumbnail, gd/intervention) → gallery participant UI → Filament approval resource → zip download → slideshow beamer scene → `RecapProjection` read-model → recap public page + beamer scene → News model + Filament + homepage block → #16 countdown mode + `arrival_info` → #17 MVP poll flavour + `BadgeCalculator` badge + reveal → docs (architecture + roadmap Erkenntnisse M12). Exact task boundaries and code are derived in the implementation plan.
