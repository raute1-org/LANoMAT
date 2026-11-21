# Launcher shell smoke test

`smoke-shell.sh` starts the real `lanomat-launcher` binary on Xvfb against a stub LANoMAT server
(`fixtures/standin_launcher.py`) and checks what the shell does from the outside: the window, the
device flow, the stored config, the library request and the single-instance deep-link handoff. No
production code is changed and no test-only feature is compiled in.

```bash
# inside the app stage of clients/launcher/Dockerfile.dev, workspace mounted at /work
docker run --rm -v "$PWD/clients/launcher:/work" -w /work \
  -e SMOKE_DIR=/work/target/smoke-shell lanomat-launcher-dev sh app/tests/smoke-shell.sh
```

The script builds `cargo build -p lanomat-launcher-app` first (skip with `SKIP_BUILD=1` when the
binary already exists). It exits non-zero on the first failed assertion but keeps going through the
phases, so one run reports every failure. Artifacts land in `$SMOKE_DIR` (default
`/tmp/lanomat-launcher-smoke`): `shots/*.png`, `logs/standin.log` (the stub's request log),
`logs/app-*.log` (the shell's own log) and, when WebKit exposed it, `shots/devtools.html`.

## What it proves, and how

| Assertion | Evidence |
|---|---|
| The connect screen renders | the window is mapped, the screenshot is not blank, and typing the stub address into the focused field makes the stub receive `POST /api/mcp/device` with the typed address (a default address would never reach the stub) |
| Pairing completes and persists | the stub issues a token after one `authorization_pending` poll, the shell reads `/api/v1/me`, writes `config.json` in `LANOMAT_LAUNCHER_CONFIG_DIR` (mode 600) with the stub URL and the token, then requests `GET /api/v1/recipes` |
| A seeded config boots into the library | the app starts with a pre-seeded `config.json`, the library request carries `Bearer <stored token>`, and the screenshot differs from the connect screen |
| The UT2004 card exists and opens | `lanomat://install/unreal-tournament-2004` in a second instance produces `POST /api/v1/recipes/unreal-tournament-2004/plan` from the first instance — the UI only requests a plan for a card it found by slug |
| Single instance | the second process exits with code 0 within 20 s, the window count stays 1, and the first instance keeps running |
| No crash | the shell process stays alive and its log has no `panicked at` |

The stub answers a plan whose two files are `missing`, so the install fails fast with the two labels
instead of downloading anything; the install screen is still a screenshot.

## Environment

| Variable | Default | Meaning |
|---|---|---|
| `SKIP_BUILD` | `0` | `1` uses `$APP_BIN` as is |
| `APP_BIN` | `target/debug/lanomat-launcher` | binary under test |
| `SMOKE_DIR` | `/tmp/lanomat-launcher-smoke` | work dir (temp HOME, config dir, logs) |
| `SMOKE_SHOTS` | `$SMOKE_DIR/shots` | screenshot folder |
| `SMOKE_DISPLAY` | free display in `:99..:139` | reuse a fixed X display |
| `STANDIN_PORT` | a free port | pin the stub port |
| `RUST_LOG` | `lanomat=debug,lanomat_launcher_app=info` | the app's log filter |

## Limits

- The DOM is not dumped. A debug build enables WebKit's inspector; the script probes
  `WEBKIT_INSPECTOR_SERVER` and saves the target list when WebKit answers, but the assertions do not
  depend on it. Everything else is observed from outside (X window, screenshots, the stub's request
  log, the config file), so the test cannot pass on a shell that did not actually render and talk to
  the server.
- No OCR: German copy is not asserted, only that the screen rendered and changed.
- Geometry checks are shape checks (window size, non-blank, screens differ), not pixel-perfect
  comparisons, so a Signalpult restyle does not break the smoke.
- The connect field is clicked at (472, 220), which follows from the fixed 960x720 window and the
  fixed connect layout. Without a window manager the webview never receives a focus handover, so
  the UI's own `focus()` on the address field does not hold; the click is what puts the caret into
  the field. A redesigned connect screen means adjusting this one coordinate.

## Finding from the first run (2026-11-21)

The smoke caught a real integration bug between the shell (L4) and the UI (L5): `ui/js/connect.js`
invoked `connect_start` with `{ server_url }`, but Tauri v2 exposes command arguments to JavaScript
in camelCase, so the shell answered

```
invalid args `serverUrl` for command `connect_start`:
command connect_start missing required key serverUrl
```

and pairing never started (evidence: `shots/01-connect-failed.png`). Minimal fix, either side:
`call('connect_start', { serverUrl: url })` in `ui/js/connect.js`, or
`#[tauri::command(rename_all = "snake_case")]` on `connect_start` in `app/src/lib.rs`. With that
one-word fix the whole smoke is green, including pairing, the stored config and the library.

Until the fix lands, a run on that tree exits 1 on the connect-screen assertions and passes every
other phase.

## Verified runs (2026-11-21, `lanomat-launcher-dev`, Xvfb)

| Tree | Exit | Result |
|---|---|---|
| current working tree (`cargo build -p lanomat-launcher-app`, then `SKIP_BUILD=1`) | `1` | 21 passed, 5 failed — all five are the `connect_start` argument bug above |
| same tree with only `{ serverUrl }` in `ui/js/connect.js` (rebuilt) | `0` | 32 passed, 0 failed |

The green run covers: the connect screen renders, the typed stub address starts the device flow,
the token is stored in `config.json` (mode 600) with the stub URL, the library request carries the
stored bearer token, the deep link opens the UT2004 card (plan requested), the install screen
renders, one window stays, the second instance exits 0, and both instances exit cleanly on close.
The WebKit inspector answered nothing in this image, so the devtools cross-check is skipped; the
assertions above do not depend on it.

## Desktop install (`test-install-desktop.sh`)

`test-install-desktop.sh` covers the step that makes `lanomat://install/<slug>` and
`lanomat://launch/<slug>` work on Linux after the tarball is unpacked:
`scripts/install-desktop.sh` copies the binary to `~/.local/bin`, writes
`~/.local/share/applications/lanomat-launcher.desktop` with an absolute `Exec=` (quoted when the
path has spaces), installs the icon into `hicolor/256x256/apps` and makes the entry the handler
for `x-scheme-handler/lanomat`. The Tauri deep-link plugin registers the scheme itself only for
AppImage builds, so without this step a link from the website goes nowhere.

```bash
sh clients/launcher/app/tests/test-install-desktop.sh
```

No Docker, no network: it runs against a `mktemp -d` home, so the real `~/.local` is untouched.
`xdg-mime` and `update-desktop-database` are best-effort in the installer, and one case runs with
a PATH that has neither. The same run checks that `scripts/ops/build-launcher.sh` and
`clients/launcher/ci/build.sh` both take the desktop entry (`--print-desktop`) and the readme
(`scripts/liesmich.txt`) from that folder, so the two packagers cannot drift apart (R4).

Windows: `scripts/install-desktop.ps1` writes `HKCU\Software\Classes\lanomat` (`URL Protocol`,
`shell\open\command` = `"<exe>" "%1"`) and the `App Paths` entry — no administrator rights,
`-Uninstall` removes exactly those two keys. It has no automated test here: the registry writes
need a Windows host (or PowerShell 7 on Linux for the syntax only).
