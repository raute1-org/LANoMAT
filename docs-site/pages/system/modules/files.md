---
title: Files
description: Moderated LAN file sharing with quota, any type, resumable uploads and the LanCache file host.
sidebar_icon: folder
---

`Files` is the LAN's shared folder. Participants upload mods, maps, installers and ISOs; everyone
downloads at LAN speed. The module supports two stores: the private Laravel disk (default) and the
LanCache file host (nginx + tusd), where bytes never pass through the app.

<div class="live">LIVE 17:42 · 18.4 GB shared · 12 uploads in flight</div>

## Who uses it

- **Teilnehmer** upload within their quota, browse, download and resume.
- **Helpers / orga** moderate files, remove malware-looking uploads and manage quota.
- **Agents** list files and obtain a signed download URL.

## Key screens

- `/events/{slug}/files` — list, upload, download progress, quota.
- Filament **Shared files** — moderation, visibility, delete.
- Download route — 302 to a short-lived signed URL on the file host.

| Dark | Light |
|---|---|
| ![Files, dark](/screens/files-dark.png) | ![Files, light](/screens/files-light.png) |
| `FILES · DARK` — quota, moderation, downloads | `FILES · LIGHT` |

## Guards and rules

- Any file type except browser-executable ones (HTML, SVG, JS) — an uploaded page must not run on
  the app's origin.
- Per-user quota is enforced by `UploadQuota` on the server; the UI hint is not the guard.
- Uploads are moderated; a pending file is visible to its uploader and staff only.
- With `FILES_STORE=host` + `FILES_TUS_URL`, uploads are resumable tus uploads straight to tusd.
  The app issues a single-use ticket (`file_upload_reservations`) and answers tusd's hooks.
- Downloads are policy-checked, then redirected to a signed URL with an expiry; tampering answers
  403, expiry 410.
- `lanomat:sweep-file-uploads` releases abandoned reservations.
- A row remembers its store (`local` or `filehost`), so switching stores never strands files.

## Docs for agents

`files_list` and `file_download_url`.

<div class="callout-live">
  <strong>LAN-only by design.</strong> The file host is reachable on the venue network. A download
  from outside the LAN would get a redirect it cannot follow — that is expected.
</div>
