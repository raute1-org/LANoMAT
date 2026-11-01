# Native client release (Linux and Windows)

How the native voice client (`clients/voice`: the Tauri desktop app `lanomat-voice-app` and the CLI
`lanomat-voice`) is built, versioned, signed and handed to the server's download page (`/talk/client`,
`clients-dist/`). The code and its usage are described in
[`clients/voice/README.md`](../../clients/voice/README.md); the server API it talks to in
[`native-voice-client-api.md`](native-voice-client-api.md).

## Tag scheme and versions

- A client release build is started by a tag **`client-v<name>`** (for example `client-v2026-11-a`, or
  `client-v` plus the server version string) or by hand. The tag only starts the workflow and names the
  draft release; it is **not** the version that the client reports.
- **The version string is the server's.** The server offers a client only if the manifest's
  `app_version` equals its own `config('app.version')` (and the commit matches when both know it), see
  `ClientBuilds::matchesServer()`. That string is `git describe --tags --always --dirty`
  (`scripts/ops/lib/version.sh`, for example `m12-463-g0a3b6ff`). The workflow computes it for the built
  commit with the same function (without `--dirty`, a CI checkout is clean), passes it to the compiler as
  `LANOMAT_BUILD_VERSION` (and the 12-character commit as `LANOMAT_BUILD_COMMIT`) and puts it into every
  file name. The client's `--version`, the user agent and the about view therefore report exactly what
  the server reports.
- **How to get a client that matches a deployed server.** Build the commit the server was deployed
  from. Two cases: (1) tag that commit `client-v<anything>` and push the tag; mind that this tag then
  also becomes the nearest tag for `git describe`, so a server image built from the same checkout
  AFTER tagging reports `client-v<anything>` (or `client-v<anything>-N-g<sha>` later), and the workflow
  run at the tag reports the same string; (2) or run the workflow by hand on a branch or tag and give
  the `version` input the exact string the server shows (the `app_version` in its `/talk/client` page
  or `config('app.version')`), the built commit must be the deployed one. Mismatches are visible: the
  download page says "version does not match this server".

## The workflow

`.github/workflows/release-client.yml` runs **only** on a `client-v*` tag push or by hand
(`workflow_dispatch`, inputs `version` and `publish`); never on push or pull request. Jobs:

1. `meta`: derives version, commit and `API_VERSION` (read from `NativeClientConfig.php`, as
   `build-client.sh` does) and decides whether this run may create a draft release.
2. `build` (one Linux job): builds the image `clients/voice/Dockerfile.dev --target windows` (Rust 1.91,
   clang 21 from apt.llvm.org for libwebrtc, the Tauri Linux dependencies, `cargo-xwin`) and runs
   `clients/voice/ci/build.sh` inside it: `cargo fmt --check`, `cargo clippy ... -D warnings`,
   `cargo test --workspace`, the Linux release binaries and tarballs, the same clippy for
   `x86_64-pc-windows-msvc`, the Windows CLI **cross-compiled with cargo-xwin** (no Windows machine
   is needed to build), the Windows **desktop app and its NSIS installer** (see below) and the Windows
   unit-test executables.
3. `windows-smoke` (real `windows-2025` runner): runs the Windows test executables (so the named-pipe
   push-to-talk code is tested for real) and `ci/windows-smoke.ps1`: CLI `--version`, the version
   metadata, then the **portable exe** (window, `ptt status` through the live app's pipe, process
   stops, nothing written next to the exe), then a **silent install** of the setup (per user, into a
   temp dir), the **installed** exe with the same checks, a second copy next to it, and the silent
   **uninstall** (directory, Start Menu shortcut and registry entry are gone).
4. `package`: optional signing, naming the Windows files, `SHA256SUMS`, `manifest.json` and per-platform
   fragments (`ci/manifest.sh`); uploads the artifact `client-release-files`.
5. `draft-release` (tag runs only): a **draft** GitHub Release with those files. The workflow never
   publishes; the owner reviews the draft and publishes it by hand. A manual run publishes nothing
   unless it is started on a `client-v*` tag with `publish: true`.

Repository variables `CLIENT_LINUX_RUNS_ON` and `CLIENT_WINDOWS_RUNS_ON` (a JSON string such as
`"ubuntu-24.04"`) pick other runners. The cargo registry, the target directory (libwebrtc is built
into it) and the Windows SDK that cargo-xwin downloads (about 1.5 GB from Microsoft, the slow part of a
cold run) are cached with `actions/cache`; the toolchain image uses the GitHub Actions layer cache.

Two build facts worth knowing: the prebuilt libwebrtc links the **static** C runtime, so
`clients/voice/.cargo/config.toml` sets `crt-static` for the MSVC target and `app/build.rs` turns off
tauri-build's "static VC runtime, dynamic UCRT" default (otherwise `lld-link` reports a
`RuntimeLibrary` mismatch or missing `strlen`/`sinf`); and `clang-cl`/`llvm-lib` must be on `PATH`
(the `windows` stage of `Dockerfile.dev` links them from the apt.llvm.org packages).

### The Windows installer and the portable exe

One `cargo tauri build` produces both. `ci/build.sh` runs, inside the `windows` image,

```sh
cd app && cargo tauri build --runner cargo-xwin --target x86_64-pc-windows-msvc --bundles nsis --ci \
  --config '{"version":"<numeric>","bundle":{"active":true}}'
```

- The bundler is **off** in `app/tauri.conf.json` (`bundle.active=false`, so a plain `cargo build` and
  the CLI build are untouched) and is switched on only by this `--config`. The NSIS settings live in the
  file: per-user install without administrator rights (`installMode: currentUser`), `languages`
  German and English (the installer picks the Windows language), start menu shortcut, uninstaller,
  product name `LANoMAT Voice`, identifier `app.lanomat.voice`.
- The image has `nsis` (makensis), `tauri-cli` (version pinned in `Dockerfile.dev`, installed with
  `--locked`) and the `Win/RestartManager.nsh` header (checksum-pinned; Debian 12's NSIS 3.08 lacks it,
  Tauri's installer template needs it). The static-CRT settings (`.cargo/config.toml`,
  `app/build.rs`) apply unchanged: `tauri build` compiles the same crate.
- **The same exe is the portable file and the one inside the installer.** After the bundle step the
  exe in `target/<triple>/release/lanomat-voice-app.exe` is copied out as the portable app. (The Tauri
  bundler patches a bundle-type marker into it; it only matters to the Tauri updater plugin, which this
  app does not use.) Settings and credentials live in `%APPDATA%\lanomat-voice` for both (the
  `directories` crate, independent of where the exe sits), so a portable copy and an installed copy
  share them. The push-to-talk pipe is per user, so **two running copies exclude each other**: the
  second cannot take the pipe (the smoke test proves the first keeps working).
- **WebView2.** `webviewInstallMode` is `embedBootstrapper`: the installer carries Microsoft's small
  bootstrapper and runs it if WebView2 is missing, which needs the internet **once**. A LAN without
  internet needs the WebView2 runtime preinstalled (Windows 11 and updated Windows 10 have it). The
  portable exe never installs anything and needs WebView2 present.
- **Version metadata.** NSIS and the Windows version resource need a numeric `x.y.z`.
  `clients/voice/ci/win-version.sh` maps the release string deterministically (self-tested in
  `ci/build.sh`): `client-v1.2.3` / `v1.2.3` / `1.2.3` -> `1.2.3`; a `git describe` string
  `<tag>-<N>-g<hex>` (optionally `-dirty`) -> `0.N.0`, so `m12-496-g9624daa` -> `0.496.0`; anything else
  -> `0.0.0`; a field above 65535 is an error. The exact string stays in the file names, the manifest and
  `--version`; only the exe's file/product version and the installer's version use the number (the
  smoke test checks it).
- **Signing and the installer.** The SignPath hook signs the three `.exe` files, but the exe *inside* the
  installer is the unsigned one (it was embedded before signing). Signing the inner exe too needs
  Tauri's `bundle.windows.signCommand` on a Windows host; that is left for the day signing is real.
- Not built here: a Linux AppImage or an Arch package (possible follow-ups, mentioned for later).

### Files and manifest contract

`<V>` is the version string. The shape is what the server's `ClientBuilds` validates and
`scripts/ops/build-client.sh` writes, with the extra `signed` field (read by the server: the download page explains unsigned Windows files).

| File | os | arch | kind | variant |
| --- | --- | --- | --- | --- |
| `lanomat-voice-app-<V>-linux-x86_64.tar.gz` | linux | x86_64 | app | archive |
| `lanomat-voice-cli-<V>-linux-x86_64.tar.gz` | linux | x86_64 | cli | archive |
| `lanomat-voice-app-<V>-windows-x86_64-setup.exe` | windows | x86_64 | app | installer (NSIS, per user) |
| `lanomat-voice-app-<V>-windows-x86_64-portable.exe` | windows | x86_64 | app | portable (one exe) |
| `lanomat-voice-cli-<V>-windows-x86_64.exe` | windows | x86_64 | cli | archive |

`variant` is `installer`, `portable` or `archive`; a manifest without the field (everything built before
the installer, including the old `lanomat-voice-app-<V>-windows-x86_64.zip`) means `archive`, and the
server still accepts it. The server checks that `installer` and `portable` are the Windows app and end
in `-setup.exe` / `-portable.exe`. Downloads are served as attachments with
`application/vnd.microsoft.portable-executable` (exe), `nosniff` and the checksum header.

Next to them `SHA256SUMS`, `manifest.json` (all files) and `manifest-<os>-<arch>.json` (one platform):

```json
{ "app_version": "<V>", "commit": "<12 chars>", "api_version": 1, "built_at": "2026-11-01T12:00:00Z",
  "files": [ { "name": "...", "os": "windows", "arch": "x86_64", "kind": "app|cli",
               "variant": "installer|portable|archive", "size": 123, "sha256": "...", "signed": false } ] }
```

`scripts/ops/build-client.sh` (the server-side Linux-only build into `clients-dist/`) and this workflow
produce interchangeable Linux files; only the workflow produces Windows files.

## Why unsigned first, and how signing plugs in

Windows files are built **unsigned**: SmartScreen shows "Der Computer wurde durch Windows
geschuetzt"; users click **Weitere Informationen**, then **Trotzdem ausfuehren** (documented in the
client README, the download page and the draft release's notes). The manifest says
`signed: false`. The pipeline is prepared for signing: the `package` job has a hook that is skipped
unless the repository variable **`WINDOWS_SIGNING`** is set.

### Route A: SignPath Foundation (free for open source), `WINDOWS_SIGNING=signpath`

1. Apply to the SignPath Foundation open-source programme (the repository is public and MIT
   licensed; they review the project, that the build runs on GitHub-hosted runners from the public
   repository, and a code-signing policy section in the README). Name the project, link the repository
   and this workflow.
2. Once approved, in the SignPath web app create the **project**, an **artifact configuration** that
   signs the `.exe` files in the uploaded artifact, and a **signing policy** (for example
   `release-signing`, with the origin-verified GitHub trigger so only runs of this repository's
   workflow can request it).
3. Connect the GitHub trusted build system as the programme describes and create an API token for the
   CI user.
4. In the GitHub repository settings:
   - secret `SIGNPATH_API_TOKEN`
   - variables `SIGNPATH_ORGANIZATION_ID`, `SIGNPATH_PROJECT_SLUG`, `SIGNPATH_SIGNING_POLICY_SLUG`,
     and optionally `SIGNPATH_ARTIFACT_CONFIGURATION_SLUG`
   - variable `WINDOWS_SIGNING` = `signpath`
5. The next run uploads the unsigned exes, submits them with the official
   `signpath/github-action-submit-signing-request` action, waits for the result and packages the signed
   files (`signed: true` in the manifest); see the note above about the exe inside the installer.

### Route B: Azure Artifact Signing (formerly Trusted Signing)

A paid route: an Azure account with an Artifact Signing account and a certificate profile (identity
validation takes a few days) and OIDC federation for GitHub (`id-token: write`). The workflow carries
the commented outline next to the SignPath step: `azure/login`, then the Azure signing action, which
only runs on a Windows runner, so the signing step moves into its own Windows job between
`windows-smoke` and `package`.

### Not now: Tauri updater

A later task: the Tauri updater with a `latest.json` that the LANoMAT server itself serves from
`clients-dist/`, so an installed app updates to exactly the version that matches its server. It needs
an updater signing key (`cargo tauri signer generate`), installer bundles (NSIS on Windows, AppImage
on Linux) with `.sig` files and `tauri-plugin-updater`. None of that exists yet.

## Getting the files onto the server: `fetch-client-release.sh`

The server serves downloads from `clients-dist/`. `scripts/ops/fetch-client-release.sh` publishes the
workflow's set for the server version it is deployed with. The contract:

1. **Which set.** Default: the newest successful run of `release-client.yml` whose head commit is this
   checkout's commit and whose `manifest.json` has `app_version` equal to `lanomat_version` of this
   checkout. `--run <id>` takes the artifact `client-release-files` of that run, `--tag client-v...` the
   (draft or published) release assets. `--version` / `--commit` override what is wanted (testing).
2. **Refuse otherwise.** The manifest's `app_version` AND `commit` must equal the wanted ones; the error
   tells the operator to run `gh workflow run release-client.yml --ref <ref> -f version=<wanted>`. A dirty
   checkout (`-dirty` version) is refused.
3. **Verify.** Every listed name is a plain file name `lanomat-voice-(app|cli)-<V>-<os>-<arch>[-setup|-portable].(tar.gz|zip|exe)`
   (at most 64 entries, no duplicates, `signed` a boolean if present, `variant` one of the three and consistent with the name); every file exists, is no link, has
   the listed size and sha256, and `SHA256SUMS` agrees (its lines may only name plain files). `signed:false`
   files are named in the output; `--require-signed` refuses them.
4. **Publish atomically** (like `build-client.sh`): stage dir inside the target, files first,
   `manifest.json` renamed last, under the same `flock`, then prune files the manifest no longer lists. The
   directory is never replaced (bind mount). `--dry-run` stops after step 3 and prints the merged manifest.
5. **Merge.** If the target's manifest is for the same version and commit, its intact entries stay (a locally
   built Linux client) and the workflow's entries are added; same name or same os/arch/kind/variant: the local entry
   wins unless `--prefer-workflow`. `build-client.sh` merges the other way: it keeps non-Linux entries of the
   same version and commit. Another version or commit: the old files go.

`gh` must be authenticated (`gh auth login` or `GH_TOKEN`; nothing is printed). The workflow artifact is kept
30 days; a draft release needs a token with write access to read it. `manifest-<os>-<arch>.json` and
`SHA256SUMS` are not published.

## Verification status

Verified on GitHub's runners: formatting, clippy and the tests for both platforms, the Windows
cross-build, the Windows unit tests (the named-pipe code), the smoke test and the artifact set (see
the pull request for the run links). **Not verified:** a real Windows desktop session with audio
(microphone, WASAPI, the tray, the overlay), the SmartScreen dialog itself, the SignPath signing run
(needs the programme approval), the draft-release job (it only runs on a tag) and the Azure route.
