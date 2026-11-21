---
title: Identity
description: Discord-only login, roles, profiles, local avatars and linked accounts.
sidebar_icon: id-card
---

`Identity` is the user model and the front door. Participants log in with Discord — there is no
password login — and the module resolves roles, keeps avatars local, and optionally links Steam or
Twitch accounts on top.

## Who uses it

- **Everyone** logs in with Discord and edits their profile.
- **Orga/admin** roles are assigned in the admin panel; admins bypass policies via `Gate::before`.
- **Teilnehmer** optionally link Steam or Twitch for display names, ownership hints and friends.

## Key screens

- `/login` — Discord OAuth (plus demo buttons under `APP_ENV=local`).
- `/settings/profile` — name, bio, profile colour.
- `/settings/connections` — linked accounts, unlink and re-auth.
- `/users/{user}` — public profile; guests see no avatar picture.

| Dark | Light |
|---|---|
| ![Settings page with linked Steam and Twitch accounts, dark](/screens/connections-dark.png) | ![Settings page with linked Steam and Twitch accounts, light](/screens/connections-light.png) |
| `CONNECTIONS · DARK` — Steam and Twitch, not linked | `CONNECTIONS · LIGHT` — same cards |

| Dark | Light |
|---|---|
| ![Filament user list with role badges, dark](/screens/admin-users-dark.png) | ![Filament user list with role badges, light](/screens/admin-users-light.png) |
| `ADMIN · USERS · DARK` — one role per user | `ADMIN · USERS · LIGHT` — same list |

## Guards and rules

- `users.discord_id` is unique-nullable and the only authentication anchor.
- `DISCORD_LOGIN_GUILD_ID` optionally restricts login to one guild; admins are exempt, the check is
  fail-closed and needs no bot.
- Avatars are downloaded by `RefreshAvatarJob`, decoded, cropped to 256×256, re-encoded through GD
  (all metadata dropped) and served from `GET /avatars/{user}/{hash}` — never from the Discord CDN.
- `users.id` is the sole foreign-key anchor. `discord_id` is never a primary key.
- Linked account tokens are encrypted, never `$fillable`, never serialized to the frontend.
- Game ownership from a linked account is advisory only. It must never block tournament enrollment.

## Docs for agents

`lanomat_me` returns the authenticated identity, role and current event. This is the first call an
agent makes after pairing.

<div class="callout-live">
  <strong>One accent, one identity.</strong> Profile colours exist, but the app's amber is reserved
  for primary actions and live state — a profile colour never repaints the shell.
</div>
