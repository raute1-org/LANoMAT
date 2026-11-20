---
title: SMB import
description: Pull recipes and game content from the LAN share at smb://nas.local/games-recipes/.
sidebar_icon: network
---

The LAN's recipe and content staging lives on an SMB share:

```text
smb://nas.local/games-recipes/
```

The share is the source of truth for large content: ISOs, patches, mods, avatars and the recipe
folders an orga is preparing. It is not a git remote and it is not the file host participants
download from — the file host serves the bytes, the share is where they are staged and reviewed.

<div class="live">LIVE 17:42 · SMB share mounted · 412 GB · 96 files</div>

## What is on the share

| Folder | Contents |
|---|---|
| `recipes/` | Candidate `recipe.yaml` + `SKILL.md` folders, one per slug |
| `isos/` | Disc images (`ut2004-dvd1.iso`, `r4.chd`, …) |
| `patches/` | Patches and official updates |
| `mods/` | Mod releases (`UT2004VR-*.zip`) |
| `avatars/` | `.vrm` files and avatar packs |
| `bios/` | User-supplied console BIOS dumps |

Names matter: a recipe's `match` globs run against these file names. Keep upstream names when you
can, and use lowercase kebab-case for recipe folders.

## Mount it

**Linux**

```bash
sudo mkdir -p /mnt/games-recipes
sudo mount -t cifs //nas.local/games-recipes /mnt/games-recipes \
  -o username=lanomat,uid=$(id -u),gid=$(id -g),vers=3.0
```

**Windows**

```powershell
net use Z: \\nas.local\games-recipes /persistent:no
explorer Z:\
```

**macOS**

```bash
open "smb://nas.local/games-recipes"
```

`smbclient -L //nas.local -U lanomat` lists the shares if the name is not reachable. The share
is LAN-only; it is not exposed to the internet.

## Import a batch

1. Copy new content into the matching folder. Prefer `rsync` so a re-run only transfers changes:

   ```bash
   rsync -av --progress /mnt/games-recipes/isos/ /srv/lanomat-files/data/isos/
   ```

2. Copy candidate recipe folders into `resources/recipes/<slug>/` in the repository.
3. Run the validator, or import the folder straight into the catalogue (the sync command reads an
   SMB path without mounting it):

   ```bash
   php artisan lanomat:recipes:sync smb://nas.lan/games-recipes/recipes --prune
   cd resources/recipes/schema && npm run validate
   ```

   `--prune` removes recipes that vanished from that source; it never touches another source.
   `--only=<slug>` imports one recipe.

4. Open the recipe in the docs/recipe catalogue, check the content list, and run a plan for one
   platform before publishing.
5. Publish the recipe (`status: published`) and, if needed, copy the content to the file host under
   the same names the recipe matches.

## Import rules

- **Never copy game files into git.** The share and the file host hold bytes; the repository holds
  YAML and Markdown.
- **Keep the names stable.** Renaming a file after a recipe matched it breaks every plan.
- **One source per item.** If the same ISO exists twice under different names, the plan may pick
  either; delete the duplicate or make the glob specific.
- **Check free space first.** The file host enforces quota per user, but the share itself does not.
- **Verify before publish.** A recipe that points at a file the share does not contain is a broken
  plan, even if the YAML validates.
- **Respect the license.** The share is for user-supplied copies at the LAN, not a redistribution
  channel.

<div class="callout-live">
  <strong>The share is not the download path.</strong> Participants download from the LAN file host
  through signed URLs. The SMB share is where the orga stages and curates content before it is
  published.
</div>
