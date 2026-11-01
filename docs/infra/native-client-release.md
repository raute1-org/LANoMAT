# Native client release (Linux and Windows)

How the native voice client (`clients/voice`: the Tauri desktop app `lanomat-voice-app` and the CLI
`lanomat-voice`) is built, versioned, signed and handed to the server's download page. The code and
its usage are described in [`clients/voice/README.md`](../../clients/voice/README.md); the server API
it talks to in [`native-voice-client-api.md`](native-voice-client-api.md).

## Tag scheme and versions

- A client release is the tag **`client-v<semver>`**, for example `client-v0.2.0`. Server releases
  (`vX.Y.Z`, the registry image) and the milestone tags (`m12`) are separate; the `client-v*` prefix
  never triggers them and they never trigger the client build.
- The version in the tag is the **client app version**. It is written into the workspace version of
  `clients/voice/Cargo.toml` and into `app/tauri.conf.json` during the build, so
  `lanomat-voice --version`, the app's about view and the HTTP user agent all report it. Keep
  `Cargo.toml` equal to the latest released tag on `main` (bump it in the PR that prepares a release)
  so a local build and the released build agree.
- **Which client belongs to which server.** The native API is versioned independently (`/api/v1`),
  so any client of the same API generation works with any server of that generation. The pairing the
  deploy cares about is simply "the client files that were built from the commit the server was
  deployed from, or the newest `client-v*` tag that is an ancestor of it". Rule of thumb: when the
  server changes the native API incompatibly, bump the client minor version in the same release train
  and tag both; the download page shows the `app_version` from the manifest next to the server
  version so a mismatch is visible.

## The workflow

`.github/workflows/release-client.yml` runs **only** on a `client-v*` tag push or by hand
(`workflow_dispatch`, inputs `version` and `publish`); never on push or pull request. Jobs:

1. `meta`: derives the version (tag, else the `version` input, else the workspace version), validates
   it as semver and decides whether this run may create a draft release.
2. `build` (one Linux job): builds the image `clients/voice/Dockerfile.dev --target windows` (Rust 1.91,
   clang 21 from apt.llvm.org for libwebrtc, the Tauri Linux dependencies, `cargo-xwin`) and runs
   `clients/voice/ci/build.sh` inside it: `cargo fmt --check`, `cargo clippy ... -D warnings`,
   `cargo test --workspace`, the Linux release binaries, the same clippy for
   `x86_64-pc-windows-msvc`, the Windows release binaries **cross-compiled with cargo-xwin** (no Windows
   machine is needed to build), and the Windows unit-test executables.
3. `windows-smoke` (real `windows-2025` runner): runs the Windows test executables (so the named-pipe
   push-to-talk code is tested for real) and `ci/windows-smoke.ps1` (CLI version, the app window,
   `ptt status` through the live app's pipe).
4. `package`: optional signing, builds the portable zip, writes `SHA256SUMS` and one manifest fragment
   per platform (`ci/manifest.sh`), uploads everything as the artifact `client-release-files`.
5. `draft-release` (tag runs only): creates a **draft** GitHub Release with those files. The workflow
   never publishes; the owner reviews the draft and publishes it by hand. A manual run publishes
   nothing unless it is started on a `client-v*` tag with `publish: true`.

Repository variables `CLIENT_LINUX_RUNS_ON` and `CLIENT_WINDOWS_RUNS_ON` (a JSON string such as
`"ubuntu-24.04"`) pick other runners. The cargo registry, the target directory and the Windows SDK that
cargo-xwin downloads (about 1.5 GB) are cached with `actions/cache`; the toolchain image uses the
GitHub Actions layer cache.

### Files and manifest contract

| File | os | arch | kind |
| --- | --- | --- | --- |
| `lanomat-voice-linux-x86_64.tar.gz` | linux | x86_64 | cli |
| `lanomat-voice-app-linux-x86_64.tar.gz` | linux | x86_64 | app |
| `lanomat-voice-windows-x86_64.exe` | windows | x86_64 | cli |
| `lanomat-voice-app-windows-x86_64.zip` | windows | x86_64 | app (portable, contains `LANoMAT-Voice.exe`) |

Next to them: `SHA256SUMS` and `manifest-<os>-<arch>.json`, each of the shape

```json
{ "app_version": "0.2.0", "commit": "<full sha>",
  "files": [ { "name": "...", "os": "windows", "arch": "x86_64", "kind": "app|cli",
               "size": 123, "sha256": "...", "signed": false } ] }
```

`LANOMAT_BUILD_VERSION` and `LANOMAT_BUILD_COMMIT` are exported to the build steps with the version and
the full commit; `scripts/ops/build-client.sh` (the server-side Linux build for `clients-dist/`) uses the
same two names and the same manifest shape, so a locally built Linux set and a released set are
interchangeable. The download page merges the `manifest-*.json` fragments it finds into one list.

## Why unsigned first, and how signing plugs in

Windows files are built **unsigned**: SmartScreen shows "Der Computer wurde durch Windows geschuetzt";
users click **Weitere Informationen**, then **Trotzdem ausfuehren** (documented in the client README
and in the draft release's notes). The manifest says `signed: false`, and the download page can show
the same hint. The pipeline is prepared for signing: the `package` job has a hook that is skipped
unless the repository variable **`WINDOWS_SIGNING`** is set.

### Route A: SignPath Foundation (free for open source), `WINDOWS_SIGNING=signpath`

1. Apply at the SignPath Foundation for the open-source programme (the repository is public and MIT
   licensed; they check the project, its build being reproducible on GitHub Actions, and a code-signing
   policy page in the repository). Name the project, link the repo and the workflow.
2. Once approved, in the SignPath web app create the **project**, an **artifact configuration** that
   signs the two `.exe` files, and a **signing policy** (for example `release-signing`, with the
   origin-verified GitHub trigger so only runs of this repository's workflow can request it).
3. Connect the GitHub app/trusted build system as the programme describes, and create an API token for
   the CI user.
4. In the GitHub repository settings:
   - secret `SIGNPATH_API_TOKEN`
   - variables `SIGNPATH_ORGANIZATION_ID`, `SIGNPATH_PROJECT_SLUG`, `SIGNPATH_SIGNING_POLICY_SLUG`,
     and optionally `SIGNPATH_ARTIFACT_CONFIGURATION_SLUG`
   - variable `WINDOWS_SIGNING` = `signpath`
5. The next tag run uploads the unsigned exes, submits them with the official
   `signpath/github-action-submit-signing-request` action, waits for the result and packages the signed
   files (`signed: true` in the manifest). SignPath signs the executables; the zip is built from the
   signed exe afterwards.

### Route B: Azure Artifact Signing (formerly Trusted Signing)

A paid route that needs an Azure account with an Artifact Signing account and a certificate profile
(identity validation takes a few days) and OIDC federation for GitHub (`id-token: write`). The workflow
contains the commented outline next to the SignPath step: `azure/login`, then the Azure signing action,
which only runs on a Windows runner, so the signing step moves into its own Windows job between
`windows-smoke` and `package`.

### Not now: Tauri updater

A later task: the Tauri updater with a `latest.json` that the LANoMAT server itself serves from
`clients-dist/`, so an installed app updates to exactly the version that matches its server. It needs
an updater signing key (`cargo tauri signer generate`), installer bundles (NSIS on Windows, AppImage
on Linux) with `.sig` files, and the `tauri-plugin-updater`. Nothing of that exists yet.

## What the deploy has to do (later task)

The server serves downloads from `clients-dist/` (see the download page work). To publish a matching
set the deploy step must, for the chosen `client-v<semver>` tag:

1. Download the release assets (or the workflow artifact `client-release-files`) into
   `clients-dist/`: the four files, `SHA256SUMS` and both `manifest-*.json`.
2. Verify `SHA256SUMS` (`sha256sum -c`) and that every manifest `files[].sha256`/`size` matches before
   making anything visible; refuse the set otherwise.
3. Keep the manifests as they are; the download page reads `app_version`, `commit`, and per file
   `name`, `os`, `arch`, `kind`, `size`, `sha256`, `signed`.

Never put the deploy credentials or any key into the workflow; the draft release is created with the
job's own `GITHUB_TOKEN` and nothing else is needed to read it from a public repository.

## Verification status

Verified on GitHub's runners: formatting, clippy and the tests for both platforms, the Windows
cross-build, the Windows unit tests, the smoke test and the artifact set (see the pull request for the
run links). **Not verified:** a real Windows desktop session with audio (microphone, WASAPI, the tray,
the overlay), the SmartScreen dialog itself, the SignPath signing run (needs the programme approval),
the draft-release job (it only runs on a tag) and the Azure route.
