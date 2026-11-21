---
title: Upload ISOs, patches and avatars
description: How the LAN's files get from a hard drive onto the LANoMAT file host — participant uploads, Orga approval, filename matching, pinning a file to a recipe, and the VRM avatar rules.
sidebar_icon: upload
---

The recipes only describe **what** a game needs; the bytes live in the LANoMAT **Files**
module. This guide is for both sides: the person who uploads and the Orga who approves.

## What the file host accepts

- Any type except HTML/SVG/JS (those are blocked; they would execute on the LANoMAT origin).
- Up to **50 GB** per file on the resumable (tus) path, 200 MB on the legacy form path.
- Every file is `pending` until an Orga approves it; participants only see approved files
  plus their own pending ones.
- Files live on the LAN file host (`FILES_STORE=host`) or the app's private disk
  (`FILES_STORE=local`); downloads are signed and short-lived on the host.

## Participant: upload a disc image

1. Open `/events/<slug>/files`.
2. Drag the ISO/archive into the uploader (or use the big-file resumable upload — the bytes go
   straight to the file host, not through PHP).
3. Keep the **original filename**: the recipe matcher matches the uploaded name
   (`ut2004*.iso`, `OldUnreal-UT2004Patch3374-*.zip`, `*.vrm`), case-insensitively. A rename to
   `game.iso` will not match.
4. Wait for the Orga to approve. The file shows a "wartet auf Freigabe" marker until then.
5. Open `/games/<slug>`; the requirement flips to **LAN** when a matching approved file exists.

## Participant: upload avatars (VRM)

- Upload the `.vrm` file exactly as downloaded (name preserved: `Ayla.vrm`).
- The installer offers every approved `*.vrm` as a multi-select. `role: avatar` entries are
  optional; the base game is never blocked by a missing avatar.
- Licensing: only avatars you may share. Public VRM models usually carry a license (CC0,
  CC-BY, CC-BY-SA, CC-BY-NC). Ripped commercial characters are not allowed; keep the author
  and license in the recipe metadata or the file name if there is no metadata.
- Privacy: a VRM can contain a recognizable likeness. Ask before uploading someone else's
  avatar; the LAN copy is visible to every participant of the event.

## Orga: approve and pin

1. `/admin/shared-files` lists pending uploads; open one, check the type/size/name, then
   **Approve** (or **Reject**; a rejected file's bytes are deleted).
2. `/admin/recipes` is the recipe review:
   - **Edit** a recipe, check the `requires` rows and the steps.
   - The **LAN-Datei-ID** field pins a specific upload to a requirement. Use it when several
     files match (two DVD ISOs, two patch versions) or when the filename is ambiguous.
   - The pinned file must be approved; a stale/deleted pin is cleared or shows a form error.
   - **Publish** sets `reviewed_by`/`reviewed_at`; **Archive** hides it without deleting it.
3. The recipe plan (`lanomat-agent plan <slug>`) now reports the pin as `matched` with the
   file's original name and size; no pin + no match reports `missing`.

## Orga: import the NAS recipe share

The share is the staging area, not the file host:

```bash
# default: resources/recipes (bundled)
php artisan lanomat:recipes:sync

# the NAS share (needs smbclient in the app image; credentials optional)
RECIPES_SOURCE=smb://nas.local/games-recipes php artisan lanomat:recipes:sync
```

`RECIPES_SMB_USER` / `RECIPES_SMB_PASSWORD` go into a `0600` credentials file, never argv.
`--prune` deletes recipes that disappeared from the **same source**; `--only=<slug>` syncs one.
The scheduler runs the bundled sync daily; the share sync is an explicit Orga action.

## Filename matching rules

- Case-insensitive `fnmatch` with `*` and `?`; `[abc]` classes are not supported.
- A pinned `shared_file_id` wins over the pattern.
- Only **approved** files of **non-draft** events are candidates.
- The matcher prefers the largest file, then the newest upload.
- `.vrm` avatars and ISOs use the same rule; a leading wildcard (`*.iso`) is fine.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Upload rejected as blocked type | HTML/SVG/JS are blocked; rename is not enough, re-pack as `.zip`/`.iso`. |
| File uploaded but never matches | The filename does not match the recipe globs, or it is still pending; check both. |
| Two ISOs match the same requirement | Pin the right one in `/admin/recipes` (LAN-Datei-ID). |
| "not on the LAN yet" for an avatar | Upload it to the event Files page and approve it; the installer only offers LAN copies. |
| SMB sync fails | `smbclient` must be in the app image (it is since this release); check host/share/user and the credentials file. |
| Prune deleted a recipe | It came from the same source and disappeared; re-add the file and sync, or restore the DB backup. |

## Checklist

- [ ] Original filenames kept (ISO, patch, `.vrm`)
- [ ] Orga approved the files
- [ ] Game page shows the requirement as **LAN**
- [ ] Ambiguous matches pinned to a specific file
- [ ] Avatar author/license recorded
- [ ] Recipe published (reviewer + date) before the LAN
