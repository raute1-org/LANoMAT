# LANoMAT Launcher

The one-click desktop launcher for LANoMAT games. The pilot installs **Unreal Tournament 2004**
(flat) from the LAN file host: the retail DVD image plus the OldUnreal 3374 patch. No Steam, no
disc mount, no terminal.

The launcher is a small Tauri v2 app (Rust core + static HTML/CSS/JS). It uses the same RFC 8628
device pairing and `/api/v1` bearer API as `lanomat-agent`, but each app mints its own token: the
launcher and the agent are paired independently, and revoking one does not revoke the other.

## What it does

1. Pair once: enter the LANoMAT URL, approve the short code in the browser. The bearer token is
   stored in `~/.config/lanomat-launcher/config.json` on Linux,
   `~/Library/Application Support/app.LANoMAT.lanomat-launcher/config.json` on macOS and in
   `%APPDATA%\LANoMAT\lanomat-launcher\config.json` on Windows (file 0600, directory 0700 on Unix;
   on Windows the file inherits the user profile's ACL).
2. The library shows the published `unreal-tournament-2004` recipe. The server builds the install
   plan; the launcher lists the LAN ISO and patch and what is missing.
3. One click downloads the ISO and the patch (resume-safe, SHA-256 checked when the recipe pins a
   hash), extracts the disc payload without `System/`, applies the patch, verifies
   `System/Build.ini` and the 64-bit executable, then offers **Starten**.
4. `lanomat://install/<slug>`, `lanomat://launch/<slug>` and the one-click
   `lanomat://join/<slug>?address=host:port` (or `?url=<encoded join URL>`) deep links are
   accepted once the launcher is installed (its `.desktop` entry registers the `lanomat` scheme
   on Linux, the app bundle's `Info.plist` on macOS, the installed URL handler on Windows). A
   join runs the install wizard when the game is missing and then launches on that server;
   UT2004 takes the address as its first argument, other recipes open their join URL after the
   launch.

The launcher never prints or logs the token. External fallback downloads (a GitHub release URL in a
recipe) do not receive the LANoMAT bearer token.

## Build

On a Linux host there is no Rust toolchain, so build and test in Docker:

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

### macOS

A macOS `.app`/`.dmg` cannot be cross-built from Linux: the bundle needs `hdiutil`, `codesign` and
a real macOS host (GitHub's `macos-14` runner, see the release workflow). Build it on macOS with
Xcode Command Line Tools and Rust:

```bash
# once per machine
xcode-select --install
rustup target add aarch64-apple-darwin x86_64-apple-darwin
cargo install tauri-cli --version "^2" --locked

cd clients/launcher/app

# universal .app + .dmg (both targets installed, as above)
cargo tauri build --target universal-apple-darwin --bundles app,dmg

# or just the host architecture (what scripts/ops/build-launcher.sh does by default)
cargo tauri build --target aarch64-apple-darwin --bundles app,dmg   # Apple silicon
cargo tauri build --target x86_64-apple-darwin  --bundles app,dmg   # Intel
```

The bundles land in `clients/launcher/target/<target>/release/bundle/`:
`macos/LANoMAT Launcher.app` and `dmg/LANoMAT Launcher_0.1.0_<arch>.dmg`. `bundle.active` is `true`
and `bundle.targets` is `["app", "dmg", "nsis"]`; Tauri filters the list to the host's native bundle
types, so a bare `cargo tauri build` produces `.app`/`.dmg` on macOS, the NSIS installer on Windows
(`cargo tauri build --bundles nsis`, with the portable exe still coming from the release workflow) and
nothing extra on Linux (the tarball is packaged by `scripts/ops/build-launcher.sh`).

`bundle.macOS.minimumSystemVersion` is `10.15`. The `lanomat://` scheme is registered through the
`deep-link` plugin config and ends up in the bundle's `Info.plist` (`CFBundleURLTypes`), so the
website's `lanomat://install/<slug>` links keep working after the app is dragged to `/Applications`.

On macOS the bundler derives `icon.icns` from the PNGs in `bundle.icon`; no binary icon is committed
for it. If you want a committed, reproducible `.icns` instead, generate the full icon set from the
existing source and add `"icons/icon.icns"` to `bundle.icon`:

```bash
cd clients/launcher/app
cargo tauri icon icons/icon.png     # writes icons/icon.icns and the PNG/ICO set
```

#### Code signing, notarization and Gatekeeper

The released builds are **unsigned** until an Apple Developer ID exists — the manifest says
`signed: false` and macOS Gatekeeper refuses the first start ("Apple could not verify…"). The
workflow already passes Tauri the standard variables; add these repository secrets to sign and
notarize:

| Secret | Purpose |
| --- | --- |
| `APPLE_CERTIFICATE` | base64 of the Developer ID Application `.p12` |
| `APPLE_CERTIFICATE_PASSWORD` | password of that `.p12` |
| `APPLE_SIGNING_IDENTITY` | e.g. `Developer ID Application: Your Name (TEAMID)` |
| `APPLE_ID` | Apple ID used for notarization |
| `APPLE_PASSWORD` | app-specific password for that Apple ID |
| `APPLE_TEAM_ID` | 10-character Apple Developer Team ID |

Tauri imports the certificate, signs the `.app` before the `.dmg` is built and submits the app to
Apple's notary service. Locally the same environment variables do the same for a manual build.

Until then, the user opens an unsigned build with **right-click → Open** (or
`xattr -dr com.apple.quarantine "/Applications/LANoMAT Launcher.app"`). The manifest and the
`/launcher` page label it `signed: false`; do not promise a notarized build before the secrets exist.

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
- `scripts/ops/build-launcher.sh` is the **release helper**. It resolves the version, compiles and
  packages: on Linux `cargo build --locked --release` in the same image, on macOS
  `cargo tauri build --bundles app,dmg` on the macOS host. It does **not** run fmt/clippy/tests, so a
  Rust change that never went through `ci/build.sh` is not caught here.

The helper keeps the cargo target and registry in named Docker volumes on Linux, so nothing
root-owned lands in the checkout; on macOS it builds natively into `clients/launcher/target`:

```bash
scripts/ops/build-launcher.sh                 # clean tree, version from git describe
scripts/ops/build-launcher.sh --allow-dirty   # local test; the version gets -dirty
scripts/ops/build-launcher.sh --help          # --version, --out, --rebuild-image, --keep-old
```

It refuses a working tree that is not clean: a tracked change anywhere, or an untracked file that is
not build output (`clients-dist/`, `clients/launcher/{target,dist}/`, `.ci-cache/`, `node_modules/`,
`vendor/`). `--allow-dirty` accepts both and appends `-dirty` to the version. On Linux the toolchain
image is labelled with the SHA-256 of `clients/launcher/Dockerfile.dev` and rebuilt when that file
changes; an image built before the label existed is reused with a notice, and `--rebuild-image` forces
a rebuild (for example after a base-image bump elsewhere). On macOS the helper requires
`cargo tauri` (`cargo install tauri-cli --version "^2" --locked`) and refuses to start on a
non-macOS host, because the `.app`/`.dmg` cannot be cross-built.

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

On macOS the same helper publishes `lanomat-launcher-<version>-macos-<arch>.dmg` and
`lanomat-launcher-<version>-macos-<arch>.zip` (the zipped `.app`; arch `aarch64` or `x86_64`; the
host's arch unless `LANOMAT_LAUNCHER_MACOS_TARGET` says otherwise) and writes the same
`manifest.json`/`SHA256SUMS`. Both entries use `variant: "archive"` — the server's `LauncherBuilds`
only accepts `installer|portable|archive` and reserves the first two for Windows — plus
`format: "dmg"` or `format: "app"`, so `/launcher` and the manifest still distinguish the disk image
from the zipped bundle. `signed` is `false` until codesigning exists (see above).

If a Windows portable exe **of the same version** sits in that folder, the manifest lists it too
(variant `portable`). A file of another version is ignored by the manifest (and by `/launcher`) and
left in place for its own release, so merging an exe means: same version, and write the folder from
a clean tree.

Windows and macOS builds come from `.github/workflows/release-launcher.yml`:

- `windows`: the `windows` stage cross-builds `lanomat-launcher.exe` with cargo-xwin, checks the PE
  header (MZ, machine 0x8664) and uploads it as
  `lanomat-launcher-<version>-windows-x86_64-portable.exe`, together with `install-desktop.ps1` and
  `install-terminal.ps1` (the Windows arcade entry point; they sit next to the exe and are not
  manifest entries).
- `macos`: runs on GitHub's macOS runners (`macos-14` arm64 is required, `macos-15-intel` x86_64 is
  best-effort) because a `.app`/`.dmg` cannot be produced from Linux. It builds
  `--bundles app,dmg` with `cargo tauri build`, checks the Mach-O arch, zips the `.app`, and uploads
  the `.dmg` plus the zipped `.app` and one `entries-macos-<arch>.json`. If the Apple secrets below are present,
  Tauri signs and notarizes; otherwise the entries say `signed: false`.

A `launcher-v*` tag only starts the run — its `launcher-v` prefix is stripped so the version stays the
commit's own `git describe` string (`scripts/ops/lib/version.sh` remains the one source). The workflow
publishes no GitHub release; its `package` job downloads the per-OS artifacts (both macOS arches when
they exist) and uploads one `launcher-release` folder: the Linux tarball, the Windows exe, the macOS
`.dmg` and zipped `.app` of the same version, a `manifest.json` listing all of them and `SHA256SUMS` for
exactly those files (the two `.ps1` files ride along unversioned). Copy that folder's contents into
`clients-dist/launcher/` — nothing else needs merging. (Locally on Linux, the equivalent is: drop a
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
