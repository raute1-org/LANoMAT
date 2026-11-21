# Launcher acceptance fixtures

Throwaway inputs for `scripts/ops/verify-launcher-local.sh`. They contain **no real UT2004 data** —
every file is a few text lines — but they have the shapes the launcher's installer must handle:

| File | Shape |
| --- | --- |
| `ut2004-fixture.iso` | ISO 9660 + Joliet image of `iso/`. Top-level `Maps/`, `Textures/`, `System/`, `Setup.exe` — like retail disc 1 (no wrapper folder). |
| `ut2004-patch-fixture.tar.bz2` | tar.bz2 of `patch/`, created with `tar -cjf … .` so it starts with a `./` **root entry** (the case that used to make extraction fail). Carries the patch's `System/` tree. |

`iso/` and `patch/` are the unpacked sources: edit them, then regenerate the two binaries.

The ISO's `System/` tree must be skipped by the installer (the patch supplies the complete `System/`),
so `iso/System/UT2004.exe` says "disc" while `patch/System/UT2004.exe` says "patched". The acceptance
script asserts the patched text, which proves the disc's `System/` was not copied.

## Regenerate

The host needs no `genisoimage`; the ISO is built in a throwaway Alpine container (package `cdrkit`).

```bash
# ISO (genisoimage 1.1.11, ISO 9660 + Joliet + Rock Ridge)
docker run --rm -v "$PWD/scripts/ops/fixtures:/fx" alpine:latest sh -c '
  apk add --no-cache cdrkit >/dev/null &&
  genisoimage -quiet -J -R -V LANOMAT_UT2004 \
    -A "LANoMAT launcher acceptance fixture" \
    -o /fx/ut2004-fixture.iso /fx/iso'
docker run --rm -v "$PWD/scripts/ops/fixtures:/fx" alpine:latest \
  chown "$(id -u):$(id -g)" /fx/ut2004-fixture.iso

# patch (the leading ./ is intentional)
tar --sort=name --mtime='@0' --owner=0 --group=0 --numeric-owner \
  -cjf scripts/ops/fixtures/ut2004-patch-fixture.tar.bz2 \
  -C scripts/ops/fixtures/patch .
```

Current sizes (the scripts updates `shared_files.size_bytes` to these, because the launcher treats
the announced size as authoritative):

```bash
stat -c '%n %s bytes' scripts/ops/fixtures/ut2004-fixture.iso \
  scripts/ops/fixtures/ut2004-patch-fixture.tar.bz2
```

## Expected install result

`System/Build.ini` and `System/ut2004-bin-amd64` (from the patch, the binary marked executable),
`System/UT2004.exe` with the "patched" text, `Maps/DM-Rankin.ut2` and `Textures/Test.utx` from the ISO,
and `Textures/Test.utx` overwritten by the patch. No `System/` entry from the disc survives.
