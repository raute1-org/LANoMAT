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
- **Agents** list files and obtain a bearer-authenticated download URL.

## Key screens

- `/events/{slug}/files` — list, upload, download progress, quota.
- Filament **Shared files** — moderation, visibility, delete.
- Download route — the file itself: streamed by the app from the private disk, or a `302` to a
  short-lived signed URL on the file host.

| Dark | Light |
|---|---|
| ![Files page in dark theme: the upload card with its quota bar, then the own uploads newest first — the pending zip, the archive and the patch archive each with "Als Spiel teilen", a disc image, VRM avatars, a team logo and a PDF](/screens/files-dark.png) | ![Files page in light theme with the same upload card and own files](/screens/files-light.png) |
| `FILES · DARK` — quota, own uploads, "Als Spiel teilen" on the archives | `FILES · LIGHT` — same list |

The upload card is the participant's side of the flow: a quiet quota bar, a file picker and one
button. A newly uploaded row waits for moderation before anyone else sees it.

Both upload paths — the resumable ticket and the multipart form — store the same cleaned name
(`UploadFileName`): control characters (C0, DEL, C1), the line and paragraph separators, the invisible
formatting characters (zero-width, byte order mark, bidirectional overrides — a name that would read
as `exe.png` while being `gnp.exe`) and every path segment up to the last separator are removed, and
the name is cut to 255 characters. A name that cleans to nothing is refused: the form answers
`files.errors.invalid_name`, and the resumable path now says the same instead of "blocked type".
Everything else survives, because a name the share page cannot quote is refused there, not silently
rewritten. A download cleans the stored name once more on the way out, so a row from before this
cleaning still arrives under a usable `Content-Disposition` name ("file" if nothing readable is left).

A ready-to-run game archive is an upload too. On your own `.zip` or `.tar.bz2` row, **Als Spiel
teilen** opens `/games/share?file=<id>` with the archive selected, where you describe the game and
the orga reviews it. The server decides per row (`shareableAsGame`, from the `ShareableGameArchive`
contract — the share page's own archive rule while `GameRecipes` is on); a file outside it and
other people's files get no link. Read [Share a game](/docs/guides/share-a-game).

The share page lists your own files of the current event (pending and approved) and only preselects
the linked one when it is a usable archive; a row it cannot use says why (its name, or that it is no
archive). A share must pin a file you may download — your own upload (pending or approved) or an
approved file of a public event; a rejected upload, somebody else's pending file or a name that
cannot be pinned (backtick, `*`, `?`, a control character, `:`, `"`, `<`, `>`, `|`, a leading dot,
two dots in a row, fewer than three letters/digits) is refused with its own message.

| Dark | Light |
|---|---|
| ![Files upload card in dark theme: the quota bar, the file picker with its upload button and the rows below it](/screens/files-upload-dark.png) | ![The same upload card in light mode](/screens/files-upload-light.png) |
| `FILES · UPLOAD · DARK` — quota, picker, upload button | `FILES · UPLOAD · LIGHT` — same card |

The other end is the Filament **Shared files** table: every row with its owner, size, status and
store, so moderation can approve, hide or delete without opening the file.

| Dark | Light |
|---|---|
| ![Admin shared files table with status and store per file, dark](/screens/admin-shared-files-dark.png) | ![Admin shared files table with status and store per file, light](/screens/admin-shared-files-light.png) |
| `FILES · ADMIN · DARK` — moderation table, status, store | `FILES · ADMIN · LIGHT` |

## Guards and rules

- Any file type except browser-executable ones (HTML, SVG, JS) — an uploaded page must not run on
  the app's origin.
- Per-user quota is enforced by `UploadQuota` on the server; the UI hint is not the guard.
- Uploads are moderated; a pending file is visible to its uploader and staff only.
- With `FILES_STORE=host` + `FILES_TUS_URL`, uploads are resumable tus uploads straight to tusd.
  The app issues a single-use ticket (`file_upload_reservations`) and answers tusd's hooks.
- Downloads are policy-checked on every request. A locally stored row is streamed by the app as an
  opaque attachment (`application/octet-stream`, `nosniff`); a file-host row redirects to a signed
  URL with an expiry, where tampering answers 403 and an expired link 410.
- `lanomat:sweep-file-uploads` releases abandoned reservations.
- `lanomat:files:hash` fills in the SHA-256 of files that arrived without one (the nightly run takes
  20 files at 04:45, so a recipe's LAN file has a digest to verify).
- A row remembers its store (`local` or `filehost`), so switching stores never strands files.

## Docs for agents

`files_list` and `file_download_url`.

<div class="callout-live">
  <strong>LAN-only by design.</strong> The file host is reachable on the venue network. A download
  from outside the LAN would get a redirect it cannot follow — that is expected.
</div>
