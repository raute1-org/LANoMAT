---
title: Gallery
description: Per-event photos, moderation, public serving, zip download and the beamer slideshow.
sidebar_icon: image
---

`Gallery` collects the LAN's photos. Any registered participant can submit phone photos; a helper
or orga approves them; approved photos feed the participant gallery, the beamer slideshow, the
recap and a zip download.

## Who uses it

- **Teilnehmer** upload photos (also after the LAN, for the recap), retract their own, browse
  approved photos.
- **Helpers / orga** approve, reject, highlight and delete.
- **Guests** see approved photos of publicly visible events through the public photo routes and the
  beamer; the gallery page itself requires login.

## Key screens

- `/events/{slug}/gallery` — approved photos, upload, my pending photos.
- Filament **Photos** — moderation queue, highlights.
- Beamer `Gallery` scene — slideshow of approved photos.

| Dark | Light |
|---|---|
| ![Gallery, dark](/screens/gallery-dark.png) | ![Gallery, light](/screens/gallery-light.png) |
| `GALLERY · DARK` — approved photos, highlights | `GALLERY · LIGHT` |

## Guards and rules

- Upload requires a non-cancelled event registration — deliberately looser than the Jukebox's
  checked-in gate, because uploading is low-risk and also happens after the LAN.
- EXIF is stripped by **forced re-encode** through GD, not by metadata surgery. Switching the image
  driver away from GD would reintroduce GPS leakage.
- Both original and thumbnail land on the private disk; a row starts `Pending`.
- Pending photos are visible only to the uploader and staff.
- Public serving is a separate route pair: `Approved` **and** publicly visible event, otherwise 404.
- Zip download is gated to `finished`/`archived` events.
- The uploader or orga can retract a photo.

## Docs for agents

No MCP tool yet.

<div class="callout-live">
  <strong>Public means approved.</strong> The beamer and recap never see a pending or rejected
  photo, and never see the uploader's name.
</div>
