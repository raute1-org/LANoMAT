---
title: Event & people
description: "The modules that make one LAN edition: sign-up, seats, teams, schedule, food, votes, presence."
sidebar_icon: users
---

This group is the participant's LAN. Everything here is scoped to one `Event` (except Identity,
Teams and Friends, which are cross-event), and every entry point is gated by the event's lifecycle
and the viewer's policy.

## How the lifecycle drives the UI

| Event status | What changes |
|---|---|
| `draft` | Invisible to everyone but orga (public page 404) |
| `announced` | Public page, registration hint, no sign-up button |
| `registration` | Sign-up open while `RegistrationAvailability` says `open`; seats open with the registration |
| `live` | Walk-in registration continues while spots remain; check-in and all live modules run |
| `finished` / `archived` | Read-only, recap link, arrival plan read-only |

The one rule for "registration open" is `RegistrationAvailability::for($event)` — not a comparison
of `EventStatus` scattered across controllers.

## Modules in this group

| Module | What it does | Main users |
|---|---|---|
| [Events](/docs/system/modules/events) | Event CRUD, lifecycle, current-event context, public page | Orga, guests |
| [Registration](/docs/system/modules/registration) | Sign-up, ticket tiers, QR ticket, check-in, arrival plan | Teilnehmer, Orga |
| [Seating](/docs/system/modules/seating) | Grid editor, seat claim/release, per-seat network metadata | Teilnehmer, Orga |
| [Teams](/docs/system/modules/teams) | Cross-event teams, join requests, ownership transfer | Teilnehmer |
| [Friends](/docs/system/modules/friends) | Requests, blocking, LAN-native suggestions | Teilnehmer |
| [Identity](/docs/system/modules/identity) | Discord login, roles, profile, linked accounts, avatars | Everyone |
| [Notifications](/docs/system/modules/notifications) | In-app bell + Discord DM channel, category preferences | Everyone |
| [Schedule](/docs/system/modules/schedule) | Timeline, favourites, ICS export, change alarms | Teilnehmer, Orga |
| [Catering](/docs/system/modules/catering) | Menus, per-person orders, cost split, readiness | Teilnehmer, Orga |
| [Lfg](/docs/system/modules/lfg) | Looking-for-group posts per event | Teilnehmer |
| [Voting](/docs/system/modules/voting) | Game votes, generic polls, MVP of the night | Teilnehmer, Orga |
| [Presence](/docs/system/modules/presence) | Who is online, playing, free; beamer scene | Teilnehmer, beamer |
| [Recap](/docs/system/modules/recap) | Post-LAN projection: podiums, photos, songs, MVP | Guests, Orga |
| [News](/docs/system/modules/news) | Global orga news posts on the homepage | Orga, guests |

## The people model

`users.id` is the sole foreign-key anchor across the schema. `users.discord_id` is the login
anchor and the routing key for DMs and interactions, but never the merge anchor. A future
user-fusion feature can repoint foreign keys; nothing depends on Discord's id as a primary key.

Roles are `participant`, `orga` and `admin`. Helpers (a role used by Files/Gallery/Jukebox
moderation) are treated as staff in those modules. Admin `Gate::before` bypasses policies for
admins only — orga is explicit, not a superuser.

<div class="callout-live">
  <strong>Screens first.</strong> The event page, seat map and bracket are the three surfaces a
  participant stands in front of. The rest of this group supports them; see the
  <a href="/start/tour">screen tour</a> for light/dark pairs.
</div>
