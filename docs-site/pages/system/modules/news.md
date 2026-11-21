---
title: News
description: Global orga news posts on the homepage — "the next LAN is…".
sidebar_icon: newspaper
---

`News` is a small, global announcement board, not scoped to an event. It exists for the one post
that is bigger than the current LAN: the date of the next one, a venue change, a call for helpers.

## Who uses it

- **Orga** writes, publishes and unpublishes posts in Filament.
- **Guests and Teilnehmer** read the latest posts on the homepage/event page.

## Key screens

- Homepage/event page — a "Neuigkeiten" block with the latest published posts.
- Filament **News posts** — title, body, publish/unpublish.

The block sits below the event actions and shows the two newest published posts with date and body;
nothing unpublished ever reaches it.

| Dark | Light |
|---|---|
| ![Event page Neuigkeiten block with published posts, dark](/screens/event-page-dark.png) | ![Event page Neuigkeiten block with published posts, light](/screens/event-page-light.png) |
| `NEWS · EVENT · DARK` — published posts on the event page | `NEWS · EVENT · LIGHT` |

Orga works in Filament **News posts**: title, author, publish date, and the withdraw/edit actions per
row.

| Dark | Light |
|---|---|
| ![Filament news posts table with withdraw and edit actions, dark](/screens/admin-news-dark.png) | ![Filament news posts table with withdraw and edit actions, light](/screens/admin-news-light.png) |
| `NEWS · ADMIN · DARK` — publish, withdraw, edit | `NEWS · ADMIN · LIGHT` |

## Guards and rules

- `published_at` is a state field flipped only by the publish action via `forceFill()`; it is not
  mass-assignable.
- `author_id` is set from the authenticated actor, never from a client-supplied value.
- `NewsQuery::published()` is the single definition of "published" (timestamp set and not in the
  future) and of the ordering (newest first).
- There is no participant write path and no comments.
- Requires Gallery (a deployment dependency, not a data dependency).

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Less than a blog.</strong> If a post needs an archive, tags or an author page, it is
  probably an event page section, not news.
</div>
