---
title: Media
description: "Files, gallery, LAN radio and the beamer: the modules that carry the event's memory."
sidebar_icon: image
---

Media is what people look at when they are not playing: shared files, the photo gallery, the LAN
radio and the beamer. Three of the four follow the same submit → moderate → publish shape, and all
of them keep the app's privacy gradient: participants see more than guests, the beamer sees no PII.

## Modules in this group

| Module | What it does | Main users |
|---|---|---|
| [Files](/docs/system/modules/files) | Shared files, quota, any type except browser-executable, resumable tus uploads, LanCache file host | Teilnehmer, helpers |
| [Gallery](/docs/system/modules/gallery) | Per-event photos, moderation gate, public serving, zip download, beamer slideshow | Teilnehmer, helpers |
| [Jukebox](/docs/system/modules/jukebox) | Communal LAN radio: search, queue, up-vote, skip threshold, Music Assistant mirror | Teilnehmer, helpers |
| [Infoscreen](/docs/system/modules/infoscreen) | Rotating beamer scenes, announcements, tombola, winner moments | Orga, beamer |

## The moderation gate

Gallery and Files both start in a pending state. A helper or orga approves or rejects. The uploader
can retract their own photo; files have a quota and a visibility rule. The Jukebox has a different
gate: only checked-in participants may queue and vote, and a community skip threshold prevents one
person from skipping a track.

## The beamer

`/screen/{event}` is the one loud surface: near-black canvas, huge type, amber at full brightness,
celebration motion. It still follows rules — one focal thing per scene, fixed list caps, 5–300
seconds per scene, and a bracket that is scaled to fit rather than shrunk into illegibility. OBS
overlays are the same scenes without the background.

<div class="callout-live">
  <strong>Public means public.</strong> Beamer scenes never receive e-mail, Discord ids, user ids or
  network data. Presence and Jukebox broadcast empty payloads on public channels; the authorized
  page re-fetches the state it is allowed to see.
</div>
