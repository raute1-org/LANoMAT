# LANoMAT Launcher

The one-click desktop launcher for LANoMAT games. The pilot installs **Unreal Tournament 2004**
(flat) from the LAN file host: the retail DVD image plus the OldUnreal 3374 patch. No Steam, no
disc mount, no terminal.

The launcher is a small Tauri v2 app (Rust core + static HTML/CSS/JS). It uses the same RFC 8628
device pairing and `/api/v1` bearer API as `lanomat-agent`, but each app mints its own token: the
launcher and the agent are paired independently, and revoking one does not revoke the other.

## What it does

1. Pair once: enter the LANoMAT URL, approve the short code in the browser. The bearer token is
   stored in `~/.config/lanomat-launcher/config.json` on Linux and in
   `%APPDATA%\LANoMAT\lanomat-launcher\config.json` on Windows (file 0600, directory 0700 on Unix;
   on Windows the file inherits the user profile's ACL).
2. The library shows the published `unreal-tournament-2004` recipe. The server builds the install
   plan; the launcher lists the LAN ISO and patch and what is missing.
3. One click downloads the ISO and the patch (resume-safe, SHA-256 checked when the recipe pins a
   hash), extracts the disc payload without `System/`, applies the patch, verifies
   `System/Build.ini` and the 64-bit executable, then offers **Starten**.
4. `lanomat://install/<slug>` and `lanomat://launch/<slug>` deep links are accepted once the
   launcher is installed (its `.desktop` entry registers the `lanomat` scheme).

The launcher never prints or logs the token. External fallback downloads (a GitHub release URL in a
recipe) do not receive the LANoMAT bearer token.

## Build

There is no Rust toolchain on the host. Build and test in Docker:

```bash
docker build --target app -t lanomat-launcher-dev -f clients/launcher/Dockerfile.dev clients/launcher

# tests (launcher-core and the app's unit tests)
docker run --rm -v "$PWD/clients/launcher:/work" -w /work \
  -e CARGO_TARGET_DIR=/work/target lanomat-launcher-dev cargo test --workspace

# the desktop binary
docker run --rm -v "$PWD/clients/launcher:/work" -w /work \
  -e CARGO_TARGET_DIR=/work/target lanomat-launcher-dev \
  cargo build --release -p lanomat-launcher-app
```

`clients/launcher/target/release/lanomat-launcher` is the binary; Tauri embeds `app/ui` at compile
time. Run it on a desktop with WebKitGTK 4.1 (Linux) or WebView2 (Windows). The build's commit comes
from `LANOMAT_LAUNCHER_COMMIT` (set by the release helper) or the checkout, falling back to
`unknown`; it is not taken from `LANOMAT_BUILD_COMMIT`. The version the binary reports is Cargo's
package version (`0.1.0`), not the release string in the file names — `LANOMAT_BUILD_VERSION` names
the artifacts only, and the Rust side ignores it.

The footer shows that pair (`Launcher 0.1.0 · <commit>`), and once a server address is known it
compares itself with the `release` props of the server's `/launcher` page: when the published build
differs (another commit), the footer grows one link, „Neue Launcher-Version verfügbar: <version> —
Launcher-Seite öffnen", which opens that page in the browser. Same commit means no link; an
unreachable or unparsable page means silence, never a guess.

Two entry points, different jobs:

- `ci/build.sh` is the **gate**. Inside the `app` image it runs `cargo fmt --all --check`,
  `cargo clippy --locked --workspace --all-targets -- -D warnings`, `cargo test --locked --workspace`
  (core and app unit tests — the app crate's slug/URL/footer tests are real), then the release build
  and stages `dist/linux/`. The release workflow uses it (PR CI has no launcher job yet); run it
  before publishing.
- `scripts/ops/build-launcher.sh` is the **release helper**. It resolves the version, compiles
  `cargo build --locked --release` in the same image, packages and publishes. It does **not** run
  fmt/clippy/tests, so a Rust change that never went through `ci/build.sh` is not caught here.

The helper keeps the cargo target and registry in named Docker volumes, so nothing root-owned lands
in the checkout:

```bash
scripts/ops/build-launcher.sh                 # clean tree, version from git describe
scripts/ops/build-launcher.sh --allow-dirty   # local test; the version gets -dirty
scripts/ops/build-launcher.sh --help          # --version, --out, --rebuild-image, --keep-old
```

It refuses a working tree that is not clean: a tracked change anywhere, or an untracked file that is
not build output (`clients-dist/`, `clients/launcher/{target,dist}/`, `.ci-cache/`, `node_modules/`,
`vendor/`). `--allow-dirty` accepts both and appends `-dirty` to the version. The toolchain image is
labelled with the SHA-256 of `clients/launcher/Dockerfile.dev` and rebuilt when that file changes;
an image built before the label existed is reused with a notice, and `--rebuild-image` forces a
rebuild (for example after a base-image bump elsewhere).

The compile runs as a named container (`docker create`/`start`/`wait`/`logs`) instead of `docker run`,
because the docker client on the build host has been seen to hang after the container had already
finished. `LANOMAT_LAUNCHER_BUILD_TIMEOUT` (seconds, default `3600`) bounds the wait; when the client
hangs but `$STAGE/bin/lanomat-launcher` exists and is executable, the script warns and packages that
binary instead of failing. The staging folder is emptied before each build, so a binary found there
always comes from that run.

It publishes `clients-dist/launcher/lanomat-launcher-<version>-linux-x86_64.tar.gz` — a top-level
`lanomat-launcher-<version>-linux-x86_64/` folder holding the `lanomat-launcher` binary,
`install-desktop.sh` (the user-level installer for `~/.local/bin` and the `lanomat://` handler),
`install-terminal.sh` (arcade/gaming-terminal spin-up: autostart at login, `--arcade`/`--kiosk`
fullscreen, `--name` for the cabinet label; it calls the sibling `install-desktop.sh`),
`LIESMICH.txt`, `lanomat-launcher.desktop` and `lanomat-launcher.png` (256×256, the hicolor size
`install-desktop.sh` installs into), packed reproducibly (0755 directory mode, sorted entries, fixed
mtime) — plus `SHA256SUMS` and `manifest.json`. `manifest.json` is renamed into place last and is
what `/launcher` and the Preflight check `launcher_build` read. Older Linux tarballs in the same
folder are pruned unless `--keep-old` is given; a Windows file of another version is left for its
own release.

If a Windows portable exe **of the same version** sits in that folder, the manifest lists it too
(variant `portable`). A file of another version is ignored by the manifest (and by `/launcher`) and
left in place for its own release, so merging an exe means: same version, and write the folder from
a clean tree.

Windows builds come from `.github/workflows/release-launcher.yml`: the `windows` stage cross-builds
`lanomat-launcher.exe` with cargo-xwin, checks the PE header (MZ, machine 0x8664) and uploads it as
`lanomat-launcher-<version>-windows-x86_64-portable.exe`, together with `install-desktop.ps1` and
`install-terminal.ps1` (the Windows arcade entry point; they sit next to the exe and are not manifest
entries). A `launcher-v*` tag only starts the run — its `launcher-v` prefix is stripped so the
version stays the commit's own `git describe` string (`scripts/ops/lib/version.sh` remains the one
source). The workflow publishes no GitHub release; its
`package` job downloads both per-OS artifacts and uploads one `launcher-release` folder: the Linux
tarball, the Windows exe of the same version, a `manifest.json` listing both and `SHA256SUMS` for
exactly those files (the two `.ps1` files ride along unversioned). Copy that folder's contents into
`clients-dist/launcher/` — nothing else needs merging. (Locally, the equivalent is: drop a
same-version exe into the folder and re-run `scripts/ops/build-launcher.sh`, which then lists it.)

## Local pilot acceptance

A seeded LANoMAT database has the real recipe and the demo agent token; the ISO and patch are matched
by file name and carry the SHA-256 the server stores for them, so every matched LAN file is
hash-checked (a recipe pin, when present, wins over the stored digest). Run the server, then the
core installer against it (the host has no Rust toolchain, so this runs in the image;
`--network host` lets the container reach the server on 127.0.0.1):

```bash
docker run --rm --network host \
  -v "$PWD/clients/launcher:/work" -w /work/launcher-core \
  -e CARGO_TARGET_DIR=/work/target \
  -e LANOMAT_URL=http://127.0.0.1:8000 \
  -e LANOMAT_TOKEN=lmcp_… \
  -e LANOMAT_TARGET=/tmp/ut2004 \
  lanomat-launcher-dev cargo run --example install_local
```

The example prints each phase and fails with the exact missing file names when the Orga has not
uploaded the DVD or patch.

## Security notes

- Config directory 0700 and config file 0600 on Unix; on Windows the file inherits the user
  profile's ACL instead (no Unix modes). No token in the UI, logs or error strings.
- ISO/zip/tar entries are joined with a path check: absolute paths, `..`, and drive prefixes are
  rejected. Symlinks and hard links in the patch are skipped.
- Downloads resume from `.part`; the final name only appears once the transfer is complete and, if
  the recipe pins a SHA-256, that hash matches. The bearer token is sent only to the LANoMAT origin,
  not to external fallbacks or redirects.
- The launcher runs as the user, installs under `~/Games/unreal-tournament-2004` (or
  `C:\Games\...`), and never needs administrator rights.
