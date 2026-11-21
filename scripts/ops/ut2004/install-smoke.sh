#!/usr/bin/env bash
# End-to-end smoke test for the native UT2004 install path, without the LAN file host.
#
# The live recipe 20 ("unreal-tournament-2004") normally points at a file on
# the LAN file host (10.23.0.12). That host is not reachable from every test
# machine (and a smoke test should not need it). This script serves a full-game
# base archive from a throwaway local HTTP server, points the live recipe at
# that URL for the duration of the run, lets the real launcher engine install
# it, verifies the install, and then restores the recipe URL and stops the
# server. Everything (recipe, server, temp dir) is cleaned up by a trap, also
# when the install fails.
#
# The recipe switch uses the Filament admin page (Livewire), because the live
# recipe is authored there. It needs an admin browser session; the token from
# ~/.config/lanomat-launcher/config.json is only used for the install itself
# and for reading the resulting plan. No secret is ever printed.
#
# Usage:
#   scripts/ops/ut2004/install-smoke.sh [options]
#
#   --base <zip>       full-game base archive to serve
#                      (default: ~/Downloads/Unreal Tournament 2004.zip)
#   --target <dir>     install target (default: ~/Games/unreal-tournament-2004)
#   --url <url>        recipe URL to use during the run
#                      (default: http://127.0.0.1:8099/<archive name>)
#   --timeout <dur>    install timeout, seconds or 30m/2h (default: 30m)
#   --dry-run          check every precondition and print the plan; change nothing
#   --keep             keep the temp dir, logs and the recipe backup
#   --no-restore       leave the live recipe on the local URL (prints the backup)
#   --engine <mode>    auto | example | deeplink (default: auto)
#   --recipe-id <id>   live recipe row to switch (default: 20)
#   --port <port>      local HTTP port (default: 8099)
#   --launch           after a verified install, start the game for a few seconds
#   -h, --help         this text
#
# Environment:
#   LANOMAT_ADMIN_COOKIES       raw Cookie header for the admin session
#   LANOMAT_ADMIN_COOKIES_FILE  file with a Cookie header, Netscape cookies or
#                               a Playwright storageState JSON
#                               (default: ~/.config/lanomat-launcher/admin-cookies.txt)
#   LANOMAT_PLAYWRIGHT_ENTRY    path to playwright/index.mjs
#   LANOMAT_CHROMIUM            explicit Chromium/headless-shell binary
#   LANOMAT_INSTALL_EXAMPLE     explicit install_local example binary
#   LANOMAT_LAUNCHER_BIN        installed launcher binary for --engine deeplink
#
# Readiness: the base archive is only used when the zip central directory is
# readable and the archive carries real game data (Maps/DM-Rankin.ut2) or one
# nested ISO. A running download (for example /tmp/opencode/utparts plus a
# partial prefix of the target zip) makes --dry-run exit 2 and prints how far it
# has come; it never starts or resumes the 2.55 GB download itself.
#
# Exit codes: 0 success (or dry-run ready) | 1 install/verify/restore failure |
#             2 usage error or missing precondition (including an unfinished base).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
CONFIG="$HOME/.config/lanomat-launcher/config.json"
DEFAULT_BASE="$HOME/Downloads/Unreal Tournament 2004.zip"
DEFAULT_TARGET="$HOME/Games/unreal-tournament-2004"
DEFAULT_COOKIES_FILE="$HOME/.config/lanomat-launcher/admin-cookies.txt"

BASE="${LANOMAT_UT2004_BASE:-$DEFAULT_BASE}"
TARGET="$DEFAULT_TARGET"
RECIPE_URL=""
TIMEOUT_SECONDS=1800
DRY_RUN=0
KEEP=0
NO_RESTORE=0
LAUNCH=0
ENGINE="auto"
RECIPE_ID="${LANOMAT_RECIPE_ID:-20}"
PORT="${LANOMAT_UT2004_PORT:-8099}"
HOST="127.0.0.1"
SLUG="unreal-tournament-2004"

TMP=""
HTTP_PID=""
SERVER_STARTED=0
RECIPE_SWITCHED=0
BACKUP_URL=""
BACKUP_KEY=""
BACKUP_FILE=""
INSTALL_LOG=""
HTTP_LOG=""
PLAN_JSON=""
SERVER_URL=""
TOKEN=""
EXAMPLE_BIN=""
TARGET_VERIFIED=0
CLEANUP_FAILED=0

die() { printf 'install-smoke: %s\n' "$*" >&2; exit 1; }
die2() { printf 'usage error: %s\n' "$*" >&2; exit 2; }
step() { printf '\n==> %s\n' "$*"; }
note() { printf '    %s\n' "$*"; }
warn() { printf '    WARN: %s\n' "$*" >&2; }

usage() {
    sed -n '2,/^set -euo pipefail$/p' "${BASH_SOURCE[0]}" | sed '$d' | sed 's/^# \{0,1\}//'
}

need() {
    command -v "$1" >/dev/null 2>&1 || die2 "missing dependency: $1"
}

as_seconds() {
    local value="$1"
    case "$value" in
        *h) value="${value%h}"; printf '%s' "$(( value * 3600 ))" ;;
        *m) value="${value%m}"; printf '%s' "$(( value * 60 ))" ;;
        *s) printf '%s' "${value%s}" ;;
        *) printf '%s' "$value" ;;
    esac
}

while [ $# -gt 0 ]; do
    case "$1" in
        --base) [ $# -ge 2 ] || die2 "--base needs a path"; BASE="$2"; shift ;;
        --target) [ $# -ge 2 ] || die2 "--target needs a path"; TARGET="$2"; shift ;;
        --url) [ $# -ge 2 ] || die2 "--url needs a URL"; RECIPE_URL="$2"; shift ;;
        --timeout) [ $# -ge 2 ] || die2 "--timeout needs a value"; TIMEOUT_SECONDS="$(as_seconds "$2")"; shift ;;
        --dry-run) DRY_RUN=1 ;;
        --keep) KEEP=1 ;;
        --no-restore) NO_RESTORE=1 ;;
        --launch) LAUNCH=1 ;;
        --engine) [ $# -ge 2 ] || die2 "--engine needs a value"; ENGINE="$2"; shift ;;
        --recipe-id) [ $# -ge 2 ] || die2 "--recipe-id needs a value"; RECIPE_ID="$2"; shift ;;
        --port) [ $# -ge 2 ] || die2 "--port needs a value"; PORT="$2"; shift ;;
        -h|--help) usage; exit 0 ;;
        *) die2 "unknown argument: $1 (see --help)" ;;
    esac
    shift
done

case "$ENGINE" in auto|example|deeplink) ;; *) die2 "--engine must be auto, example or deeplink" ;; esac
case "$TIMEOUT_SECONDS" in ''|*[!0-9]*) die2 "--timeout must be a positive duration" ;; esac
[ "$TIMEOUT_SECONDS" -gt 0 ] || die2 "--timeout must be a positive duration"
case "$PORT" in ''|*[!0-9]*) die2 "--port must be a number" ;; esac
case "$RECIPE_ID" in ''|*[!0-9]*) die2 "--recipe-id must be a number" ;; esac
[ -f "$BASE" ] || die2 "base archive not found: $BASE"

BASE="$(cd "$(dirname "$BASE")" && pwd)/$(basename "$BASE")"
BASE_NAME="$(basename "$BASE")"
BASE_SIZE="$(stat -c '%s' "$BASE" 2>/dev/null || wc -c <"$BASE")"

need python3
need curl
need jq
need node

[ -f "$CONFIG" ] || die2 "launcher config not found: $CONFIG"
# Only the URL and token are read; the token is never printed or passed in argv.
mapfile -t CFG < <(python3 - "$CONFIG" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as handle:
    config = json.load(handle)
url = str(config.get("url", "")).strip().rstrip("/")
token = str(config.get("token", "")).strip()
print(url)
print(token)
PY
)
SERVER_URL="${CFG[0]:-}"
TOKEN="${CFG[1]:-}"
[ -n "$SERVER_URL" ] || die2 "no server URL in $CONFIG"
[ -n "$TOKEN" ] || die2 "no launcher token in $CONFIG (pair the launcher once)"

# ---------------------------------------------------------------------------
# Playwright entry and the admin cookie source.
# ---------------------------------------------------------------------------
PW_ENTRY="${LANOMAT_PLAYWRIGHT_ENTRY:-$ROOT/node_modules/playwright/index.mjs}"
if [ ! -f "$PW_ENTRY" ]; then
    for candidate in \
        "$ROOT/node_modules/playwright-core/index.mjs" \
        "$(dirname "$(command -v node 2>/dev/null || echo /nonexistent)")/../lib/node_modules/playwright/index.mjs"; do
        [ -f "$candidate" ] && { PW_ENTRY="$candidate"; break; }
    done
fi
[ -f "$PW_ENTRY" ] || die2 "playwright not found (set LANOMAT_PLAYWRIGHT_ENTRY)"

ADMIN_COOKIES="${LANOMAT_ADMIN_COOKIES:-}"
ADMIN_COOKIES_FILE="${LANOMAT_ADMIN_COOKIES_FILE:-$DEFAULT_COOKIES_FILE}"
if [ -z "$ADMIN_COOKIES" ] && [ ! -f "$ADMIN_COOKIES_FILE" ]; then
    # Convenience fallback used by the ops sessions on this host.
    if [ -f "/tmp/opencode/cookies.txt" ]; then
        ADMIN_COOKIES_FILE="/tmp/opencode/cookies.txt"
    fi
fi
ADMIN_HOST="$(python3 - "$SERVER_URL" <<'PY'
import sys
from urllib.parse import urlsplit
print(urlsplit(sys.argv[1]).hostname or "")
PY
)"

# ---------------------------------------------------------------------------
# URL and local server.
# ---------------------------------------------------------------------------
if [ -z "$RECIPE_URL" ]; then
    RECIPE_URL="http://$HOST:$PORT/$(python3 - "$BASE_NAME" <<'PY'
import sys
from urllib.parse import quote
print(quote(sys.argv[1], safe=""))
PY
)"
    SERVE_NAME="$BASE_NAME"
else
    SERVE_NAME="$(python3 - "$RECIPE_URL" <<'PY'
import sys
from urllib.parse import unquote, urlsplit
path = urlsplit(sys.argv[1]).path
print(unquote(path.rsplit("/", 1)[-1]) if path.rsplit("/", 1)[-1] else "")
PY
)"
    [ -n "$SERVE_NAME" ] || SERVE_NAME="$BASE_NAME"
    case "$SERVE_NAME" in */*|..|.*) die2 "--url must end in a plain file name";; esac
fi
URL_IS_LOCAL="$(python3 - "$RECIPE_URL" "$HOST" "$PORT" <<'PY'
import sys
from urllib.parse import urlsplit
url = urlsplit(sys.argv[1])
host, port = sys.argv[2], sys.argv[3]
ok = url.scheme in ("http", "https") and url.hostname in (host, "localhost") and str(url.port or (443 if url.scheme == "https" else 80)) == port
print("1" if ok else "0")
PY
)"

# ---------------------------------------------------------------------------
# Read-only archive/download probe. JSON on stdout, human text on stderr.
# ---------------------------------------------------------------------------
probe_python() {
    python3 - "$@" <<'PY'
import json, os, re, sys
from pathlib import Path

path = Path(sys.argv[1]).expanduser()
result = {
    "path": str(path),
    "exists": path.is_file(),
    "size": path.stat().st_size if path.is_file() else 0,
    "zip_ok": False,
    "zip_error": None,
    "entries": 0,
    "has_dm_rankin": False,
    "has_system": False,
    "iso_entries": [],
    "active": [],
    "parts": [],
    "parts_dir": None,
    "expected_total": None,
    "downloaded": 0,
    "ready": False,
    "reasons": [],
}

if not result["exists"]:
    result["reasons"].append(f"base archive missing: {path}")
else:
    import zipfile
    try:
        with zipfile.ZipFile(path) as archive:
            names = archive.namelist()
        result["zip_ok"] = True
        result["entries"] = len(names)
        for name in names:
            low = name.lower().replace("\\", "/")
            if low == "maps/dm-rankin.ut2" or low.endswith("/maps/dm-rankin.ut2"):
                result["has_dm_rankin"] = True
            if "/system/" in low or low.startswith("system/"):
                result["has_system"] = True
            if low.endswith(".iso"):
                result["iso_entries"].append(name)
    except Exception as error:  # BadZipFile, truncated central directory, ...
        result["zip_error"] = f"{type(error).__name__}: {error}"

def read_cmdline(pid):
    try:
        raw = Path(f"/proc/{pid}/cmdline").read_bytes()
    except OSError:
        return []
    return [part.decode("utf-8", "replace") for part in raw.split(b"\0") if part]

def range_end(args):
    for index, arg in enumerate(args):
        value = None
        if arg in ("-r", "--range"):
            value = args[index + 1] if index + 1 < len(args) else None
        elif arg.startswith("--range="):
            value = arg.split("=", 1)[1]
        elif arg.startswith("-r") and arg != "-r":
            value = arg[2:]
        if value and "-" in value:
            _, end = value.split("-", 1)
            end = end.split(",", 1)[0]
            if end.isdigit():
                return int(end) + 1
    return None

def ranges_in_text(text):
    ends = []
    for match in re.finditer(r"(?:^|\s)(?:-r|--range)(?:\s+|=)(\d+)-(\d+)", text):
        ends.append(int(match.group(2)) + 1)
    return ends

name_hint = path.name
for entry in Path("/proc").iterdir():
    if not entry.name.isdigit():
        continue
    args = read_cmdline(entry.name)
    if not args:
        continue
    joined = " ".join(args)
    # The parent shell of a multi-range download carries every `-r a-b`
    # range in one command line, including ranges whose curl already exited.
    if "utparts" in joined or name_hint.lower() in joined.lower():
        for end in ranges_in_text(joined):
            result["expected_total"] = max(result["expected_total"] or 0, end)
    binary = Path(args[0]).name.lower()
    if "curl" not in binary and "wget" not in binary:
        continue
    outputs = []
    for index, arg in enumerate(args):
        if arg in ("-o", "--output", "-O", "--output-document") and index + 1 < len(args):
            outputs.append(args[index + 1])
        elif arg.startswith("--output="):
            outputs.append(arg.split("=", 1)[1])
    relevant = (
        "utparts" in joined
        or name_hint.lower() in joined.lower()
        or any(os.path.abspath(out) == str(path) for out in outputs if not out.startswith("-"))
        or any(out.startswith(str(path) + ".") for out in outputs)
    )
    if not relevant:
        continue
    total = range_end(args)
    out = outputs[0] if outputs else None
    size = 0
    if out and os.path.isfile(out):
        try:
            size = os.path.getsize(out)
        except OSError:
            size = 0
    writes_base = out is not None and (os.path.abspath(out) == str(path) or out.startswith(str(path) + "."))
    result["active"].append({"pid": int(entry.name), "output": out, "size": size, "range_end": total, "writes_base": writes_base})
    if total:
        result["expected_total"] = max(result["expected_total"] or 0, total)

# A parts directory next to the download (e.g. /tmp/opencode/utparts) is a
# multi-range download; report its parts even when no curl is visible.
candidates = []
if os.environ.get("LANOMAT_UT2004_PARTS"):
    candidates.append(Path(os.environ["LANOMAT_UT2004_PARTS"]))
candidates.append(path.parent / "utparts")
for candidate in candidates:
    if candidate.is_dir():
        result["parts_dir"] = str(candidate)
        for part in sorted(candidate.iterdir()):
            if part.is_file():
                result["parts"].append({"name": part.name, "size": part.stat().st_size})
        break

prefix = result["size"]
parts_total = sum(part["size"] for part in result["parts"])
active_on_base = any(entry["writes_base"] for entry in result["active"])
# A valid zip is authoritative. Leftover range parts or a finished curl do
# not matter once the central directory exists; a curl that appends to the
# base archive itself does.
result["downloaded"] = prefix if result["zip_ok"] else prefix + parts_total

if active_on_base:
    result["reasons"].append("a download process is still writing the base archive")
if not result["zip_ok"] and result["active"]:
    result["reasons"].append(f"{len(result['active'])} download process(es) still writing")
if not result["zip_ok"] and result["parts"]:
    result["reasons"].append(f"{len(result['parts'])} range part(s) in {result['parts_dir']}")
if result["exists"] and not result["zip_ok"]:
    result["reasons"].append("zip central directory is not readable (truncated archive)")
elif result["zip_ok"] and not result["has_dm_rankin"] and len(result["iso_entries"]) != 1:
    result["reasons"].append("archive has neither Maps/DM-Rankin.ut2 nor one nested ISO")

result["ready"] = (
    result["exists"]
    and result["zip_ok"]
    and not active_on_base
    and (result["has_dm_rankin"] or len(result["iso_entries"]) == 1)
)
print(json.dumps(result, indent=1))
PY
}

# ---------------------------------------------------------------------------
# Plan helpers. The token travels through the environment, never argv.
# ---------------------------------------------------------------------------
fetch_plan() {
    local out="$1" rc=0
    # The token goes through a mode-600 header file, never argv; curl's
    # Happy Eyeballs avoids the IPv6-only "network unreachable" hiccup.
    printf 'Authorization: Bearer %s\n' "$TOKEN" >"$TMP/auth-header"
    chmod 600 "$TMP/auth-header"
    curl -fsS --max-time 30 -H @"$TMP/auth-header" \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -X POST -d '{"os":"linux"}' \
        "$SERVER_URL/api/v1/recipes/$SLUG/plan" -o "$out" || rc=$?
    rm -f "$TMP/auth-header"
    return "$rc"
}

plan_base_url() {
    jq -r '
        ([.files[]? | select(.requirement == "iso" and (.url // "") != "")][0].url)
        // ([.downloads[]? | select(.requirement == "iso")][0].url)
        // ([.downloads[]? | .url // empty][0])
        // ""
    ' "$1" 2>/dev/null
}

# ---------------------------------------------------------------------------
# The admin recipe switch (inline Playwright + Livewire).
# ---------------------------------------------------------------------------
write_recipe_helper() {
    cat >"$TMP/recipe-url.mjs" <<'JS'
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { pathToFileURL } from "node:url";

const action = process.argv[2] || "get";
const value = process.argv[3] ?? "";
const base = (process.env.LANOMAT_ADMIN_BASE || "").replace(/\/+$/, "");
const recipeId = process.env.LANOMAT_RECIPE_ID || "20";
const wantedKey = process.env.LANOMAT_REQUIREMENT_KEY || "";
const pwEntry = process.env.LANOMAT_PLAYWRIGHT_ENTRY || "";

function fail(message) {
  console.error(message);
  process.exit(1);
}

function cookieObjects(raw) {
  if (!raw) return [];
  if (raw.trim().startsWith("{")) {
    const parsed = JSON.parse(raw);
    if (Array.isArray(parsed.cookies)) return parsed.cookies;
  }
  if (raw.includes("\t") || raw.startsWith("# Netscape")) {
    return raw.split(/\r?\n/).filter((line) => line && !line.startsWith("#"))
      .map((line) => line.split("\t"))
      .filter((fields) => fields.length >= 7)
      .map((fields) => ({
        domain: fields[0], path: fields[2], secure: fields[3] === "TRUE",
        name: fields[5], value: fields[6], httpOnly: false, sameSite: "Lax",
      }));
  }
  const host = new URL(base).hostname;
  return raw.split(";").map((pair) => pair.trim()).filter(Boolean).map((pair) => {
    const index = pair.indexOf("=");
    return {
      name: pair.slice(0, index).trim(),
      value: pair.slice(index + 1).trim(),
      domain: host, path: "/", secure: base.startsWith("https://"),
      httpOnly: false, sameSite: "Lax",
    };
  });
}

async function launch() {
  const { chromium } = await import(pathToFileURL(pwEntry).href);
  if (process.env.LANOMAT_CHROMIUM) {
    return chromium.launch({ headless: true, executablePath: process.env.LANOMAT_CHROMIUM });
  }
  try {
    return await chromium.launch({ headless: true });
  } catch (first) {
    const root = path.join(os.homedir(), ".cache", "ms-playwright");
    let fallback = null;
    try {
      for (const entry of fs.readdirSync(root)) {
        if (entry.startsWith("chromium_headless_shell-")) {
          const candidate = path.join(root, entry, "chrome-headless-shell-linux64", "chrome-headless-shell");
          if (fs.existsSync(candidate)) fallback = candidate;
        }
      }
    } catch {}
    if (!fallback) throw first;
    return chromium.launch({ headless: true, executablePath: fallback });
  }
}

let browser;
try {
  const raw = process.env.LANOMAT_ADMIN_COOKIES ||
    (process.env.LANOMAT_ADMIN_COOKIES_FILE
      ? fs.readFileSync(process.env.LANOMAT_ADMIN_COOKIES_FILE, "utf8")
      : "");
  const cookies = cookieObjects(raw);
  if (cookies.length === 0) fail("no admin cookies (set LANOMAT_ADMIN_COOKIES or LANOMAT_ADMIN_COOKIES_FILE)");
  browser = await launch();
  const context = await browser.newContext();
  const host = new URL(base).hostname;
  await context.addCookies(cookies.map((cookie) => ({
    ...cookie,
    domain: cookie.domain || host,
    path: cookie.path || "/",
    secure: cookie.secure ?? base.startsWith("https://"),
    sameSite: cookie.sameSite || "Lax",
  })));
  const page = await context.newPage();
  page.setDefaultTimeout(30000);
  const response = await page.goto(`${base}/admin/recipes/${recipeId}/edit`, { waitUntil: "domcontentloaded", timeout: 60000 });
  if (!response || response.status() >= 400) fail(`admin page answered HTTP ${response ? response.status() : "?"}`);
  if (/\/login|\/auth\//.test(page.url())) fail("admin session expired (login redirect)");
  await page.waitForFunction(() => Boolean(window.Livewire), null, { timeout: 30000 });
  await page.waitForFunction(() => {
    return [...document.querySelectorAll("[wire\\:id]")]
      .some((element) => (element.getAttribute("wire:snapshot") || "").includes("EditRecipe"));
  }, null, { timeout: 30000 });

  const read = () => page.evaluate((wanted) => {
    const element = [...document.querySelectorAll("[wire\\:id]")]
      .find((node) => (node.getAttribute("wire:snapshot") || "").includes("EditRecipe"));
    if (!element) return { error: "EditRecipe component not found" };
    const component = window.Livewire.find(element.getAttribute("wire:id"));
    const requirements = component.get("data.requirements") || {};
    const entries = Object.entries(requirements).map(([key, requirement]) => ({
      key, role: requirement?.role || "", label: requirement?.label || "", url: requirement?.url || "",
    }));
    const chosen = entries.find((entry) => entry.key === wanted && entry.url)
      || entries.find((entry) => entry.role === "iso" && entry.url)
      || entries.find((entry) => (entry.role === "archive" || entry.role === "base") && entry.url)
      || entries.find((entry) => entry.url)
      || null;
    return { chosen, entries, component: component.get("data.slug") || null };
  }, wantedKey);

  const current = await read();
  if (current.error) fail(current.error);
  if (!current.chosen) fail("recipe has no requirement with a URL");

  if (action === "get") {
    console.log(JSON.stringify({ ok: true, url: current.chosen.url, key: current.chosen.key, role: current.chosen.role }));
    await browser.close();
    process.exit(0);
  }
  if (action !== "set") fail(`unknown action: ${action}`);

  const selected = current.chosen;
  const result = await page.evaluate(async ({ key, next }) => {
    const element = [...document.querySelectorAll("[wire\\:id]")]
      .find((node) => (node.getAttribute("wire:snapshot") || "").includes("EditRecipe"));
    const component = window.Livewire.find(element.getAttribute("wire:id"));
    await component.set(`data.requirements.${key}.url`, next);
    await component.call("save");
    return { key, url: component.get("data.requirements." + key + ".url") };
  }, { key: selected.key, next: value });

  console.log(JSON.stringify({ ok: true, url: result.url, key: result.key, role: selected.role }));
  await browser.close();
} catch (error) {
  if (browser) await browser.close().catch(() => {});
  fail(String(error && error.message ? error.message : error));
}
JS
}

admin_recipe() {
    local action="$1" value="${2:-}"
    LANOMAT_ADMIN_BASE="$SERVER_URL" \
    LANOMAT_RECIPE_ID="$RECIPE_ID" \
    LANOMAT_REQUIREMENT_KEY="$BACKUP_KEY" \
    LANOMAT_PLAYWRIGHT_ENTRY="$PW_ENTRY" \
    LANOMAT_ADMIN_COOKIES="$ADMIN_COOKIES" \
    LANOMAT_ADMIN_COOKIES_FILE="$ADMIN_COOKIES_FILE" \
    TMPDIR="$PW_TMP" \
        node "$TMP/recipe-url.mjs" "$action" "$value"
}

wait_for_plan_url() {
    local expected="$1" attempts="${2:-20}" i current=""
    for ((i = 1; i <= attempts; i++)); do
        if fetch_plan "$TMP/plan.json" 2>/dev/null; then
            current="$(plan_base_url "$TMP/plan.json")"
            if [ "$current" = "$expected" ]; then
                cp "$TMP/plan.json" "$PLAN_JSON" 2>/dev/null || true
                return 0
            fi
        fi
        sleep 1
    done
    return 1
}

# ---------------------------------------------------------------------------
# Target checks.
# ---------------------------------------------------------------------------
check_target() {
    local dir="$1" ok=0
    [ -f "$dir/System/Build.ini" ] || { note "missing System/Build.ini"; ok=1; }
    if [ -x "$dir/System/ut2004-bin-amd64" ] || [ -x "$dir/System/UT2004" ] || [ -f "$dir/System/UT2004" ]; then
        :
    else
        note "missing System/ut2004-bin-amd64 (or System/UT2004)"
        ok=1
    fi
    [ -f "$dir/Maps/DM-Rankin.ut2" ] || { note "missing Maps/DM-Rankin.ut2"; ok=1; }
    return "$ok"
}

target_state() {
    local dir="$1"
    if [ ! -d "$dir" ]; then printf 'absent'; return; fi
    if check_target "$dir" >/dev/null 2>&1; then printf 'verified'; else printf 'partial'; fi
}

# ---------------------------------------------------------------------------
# Engine discovery.
# ---------------------------------------------------------------------------
find_example() {
    local candidate
    if [ -n "${LANOMAT_INSTALL_EXAMPLE:-}" ]; then
        [ -x "$LANOMAT_INSTALL_EXAMPLE" ] && { printf '%s' "$LANOMAT_INSTALL_EXAMPLE"; return 0; }
        return 1
    fi
    for candidate in \
        "$ROOT/clients/launcher/target/release/examples/install_local" \
        "$ROOT/clients/launcher/target/debug/examples/install_local"; do
        [ -x "$candidate" ] && { printf '%s' "$candidate"; return 0; }
    done
    return 1
}

deeplink_available() {
    [ -n "${LANOMAT_LAUNCHER_BIN:-}" ] && [ -x "${LANOMAT_LAUNCHER_BIN}" ] && return 0
    command -v xdg-open >/dev/null 2>&1 || return 1
    xdg-mime query default x-scheme-handler/lanomat 2>/dev/null | grep -q . && return 0
    [ -x "$HOME/.local/bin/lanomat-launcher" ] && return 0
    return 1
}

resolve_engine() {
    case "$ENGINE" in
        example) find_example >/dev/null || die2 "no install_local example binary (build it or set LANOMAT_INSTALL_EXAMPLE)"; printf 'example' ;;
        deeplink) deeplink_available || die2 "no lanomat:// handler or launcher binary (set LANOMAT_LAUNCHER_BIN)"; printf 'deeplink' ;;
        auto)
            if find_example >/dev/null; then printf 'example'
            elif deeplink_available; then printf 'deeplink'
            else printf 'none'; fi
            ;;
    esac
}

# ---------------------------------------------------------------------------
# Cleanup: restore the recipe, stop exactly the server we started, drop temp.
# ---------------------------------------------------------------------------
cleanup() {
    local rc=$?
    trap - EXIT INT TERM
    if [ "$SERVER_STARTED" = "1" ] && [ -n "$HTTP_PID" ]; then
        kill "$HTTP_PID" 2>/dev/null || true
        for _ in 1 2 3 4 5; do
            kill -0 "$HTTP_PID" 2>/dev/null || break
            sleep 0.2
        done
        kill -9 "$HTTP_PID" 2>/dev/null || true
        SERVER_STARTED=0
    fi
    if [ "$RECIPE_SWITCHED" = "1" ] && [ "$NO_RESTORE" = "0" ] && [ -n "$BACKUP_URL" ]; then
        note "restoring recipe $RECIPE_ID to $BACKUP_URL"
        if admin_recipe set "$BACKUP_URL" >/dev/null 2>&1 && wait_for_plan_url "$BACKUP_URL" 20; then
            note "recipe URL restored"
        else
            warn "could not restore recipe $RECIPE_ID automatically"
            warn "backup: ${BACKUP_FILE:-unknown} (restore with: $BACKUP_URL)"
            CLEANUP_FAILED=1
            rc=1
        fi
        RECIPE_SWITCHED=0
    fi
    if [ -n "$TMP" ] && [ -d "$TMP" ]; then
        # Browser profiles and the auth header are short-lived secrets; they
        # never survive, not even with --keep.
        rm -rf "$TMP/pw" "$TMP/serve"
        rm -f "$TMP/auth-header"
        if [ "$KEEP" = "1" ] || [ "$NO_RESTORE" = "1" ] || [ "$CLEANUP_FAILED" = "1" ]; then
            note "kept temp dir: $TMP"
            note "recipe backup: $BACKUP_FILE"
        else
            rm -rf "$TMP"
        fi
    fi
    exit "$rc"
}
trap cleanup EXIT INT TERM

make_tmp() {
    local parent="${TMPDIR:-/tmp}" free_kb=0
    if [ -d "$parent" ] && [ -w "$parent" ]; then
        free_kb="$(df -Pk "$parent" 2>/dev/null | awk 'NR==2 {print $4}')"
        if [ "${free_kb:-0}" -gt 20480 ]; then
            mktemp -d "$parent/lanomat-ut2004-smoke.XXXXXX"
            return
        fi
    fi
    mkdir -p "$HOME/.cache/lanomat-ut2004-smoke"
    printf '    temp on %s has no room; using %s\n' "$parent" "$HOME/.cache/lanomat-ut2004-smoke" >&2
    mktemp -d "$HOME/.cache/lanomat-ut2004-smoke/run.XXXXXX"
}

TMP="$(make_tmp)"
PW_TMP="$TMP/pw"
mkdir -p "$PW_TMP"
INSTALL_LOG="$TMP/install.log"
HTTP_LOG="$TMP/http.log"
PLAN_JSON="$TMP/plan.json"
BACKUP_FILE="$TMP/recipe-backup.json"
write_recipe_helper

step "UT2004 install smoke"
note "base:    $BASE ($(numfmt --to=iec-i --suffix=B "$BASE_SIZE" 2>/dev/null || printf '%s B' "$BASE_SIZE"))"
note "target:  $TARGET"
note "recipe:  $RECIPE_ID on $SERVER_URL"
note "url:     $RECIPE_URL"
note "timeout: ${TIMEOUT_SECONDS}s"

# ---------------------------------------------------------------------------
# Preconditions and readiness.
# ---------------------------------------------------------------------------
step "Checking base archive and downloads"
if [ -z "${LANOMAT_UT2004_PARTS:-}" ] && [ "$BASE_NAME" = "Unreal Tournament 2004.zip" ] && [ -d /tmp/opencode/utparts ]; then
    # The known multi-range download for this archive; an explicit
    # LANOMAT_UT2004_PARTS or a sibling `utparts` dir still works everywhere.
    export LANOMAT_UT2004_PARTS=/tmp/opencode/utparts
fi
PROBE_JSON="$(probe_python "$BASE")"
printf '%s\n' "$PROBE_JSON" | jq -r '
    "    archive: \(.size) bytes, zip=\(.zip_ok), entries=\(.entries), DM-Rankin=\(.has_dm_rankin), nested ISOs=\(.iso_entries|length)",
    (if .zip_ok then "    archive: complete (central directory readable)"
     elif .expected_total and .expected_total >= .downloaded then "    download: \(.downloaded) / \(.expected_total) bytes (\(((.downloaded * 100 / .expected_total) | floor))%)"
     else "    download: \(.downloaded) bytes so far (total unknown)" end),
    (if (.zip_ok|not) and .parts_dir then "    parts: \(.parts_dir) (\(.parts|length) file(s), \(([.parts[].size] | add) // 0) bytes)" else empty end),
    (if (.zip_ok|not) and (.active|length) > 0 then "    active: \(.active|map("pid \(.pid) -> \(.output // "?")")|join(", "))" else empty end),
    (if .zip_error then "    zip error: \(.zip_error)" else empty end),
    (if (.reasons|length) > 0 then "    reasons: \(.reasons|join("; "))" else empty end)
'
if [ "$(printf '%s' "$PROBE_JSON" | jq -r '.ready')" != "true" ]; then
    if [ "$DRY_RUN" = "1" ]; then
        step "NOT READY"
        note "the archive is incomplete or still being written; nothing was changed"
        PARTS_DIR="$(printf '%s' "$PROBE_JSON" | jq -r '.parts_dir // empty')"
        if [ -n "$PARTS_DIR" ]; then
            note "when every part is complete, append them in name order:"
            note "  cat $PARTS_DIR/* >> \"$BASE\""
        fi
        note "the test is ready when the zip opens and carries Maps/DM-Rankin.ut2 or one nested ISO"
        exit 2
    fi
    die2 "base archive is not ready (see reasons above); finish the download first"
fi

step "Checking server, token and admin session"
note "server: $SERVER_URL"
note "token:  ${#TOKEN} characters (from ${CONFIG})"
if [ -n "$ADMIN_COOKIES" ]; then
    note "admin cookies: environment"
elif [ -f "$ADMIN_COOKIES_FILE" ]; then
    note "admin cookies: $ADMIN_COOKIES_FILE"
else
    note "admin cookies: MISSING (set LANOMAT_ADMIN_COOKIES or LANOMAT_ADMIN_COOKIES_FILE)"
fi

if fetch_plan "$TMP/plan-before.json"; then
    CURRENT_PLAN_URL="$(plan_base_url "$TMP/plan-before.json")"
    note "current plan URL: $CURRENT_PLAN_URL"
else
    die2 "could not read the install plan from $SERVER_URL"
fi

if [ -z "$ADMIN_COOKIES" ] && [ ! -f "$ADMIN_COOKIES_FILE" ]; then
    if [ "$DRY_RUN" = "1" ]; then
        step "NOT READY"
        note "the recipe cannot be switched without an admin session"
        exit 2
    fi
    die2 "no admin cookies; cannot switch recipe $RECIPE_ID"
fi

if ! ADMIN_GET="$(admin_recipe get 2>"$TMP/admin-get.err")"; then
    warn "$(cat "$TMP/admin-get.err" 2>/dev/null || true)"
    if [ "$DRY_RUN" = "1" ]; then step "NOT READY"; note "admin recipe read failed"; exit 2; fi
    die "admin recipe read failed (recipe $RECIPE_ID on $SERVER_URL)"
fi
BACKUP_URL="$(printf '%s' "$ADMIN_GET" | jq -r '.url')"
BACKUP_KEY="$(printf '%s' "$ADMIN_GET" | jq -r '.key')"
case "$BACKUP_URL" in
    http://*|https://*) ;;
    *) die "admin page returned no usable recipe URL";;
esac
note "admin recipe URL: $BACKUP_URL"
printf '{"recipe_id":%s,"key":%s,"url":%s}\n' \
    "$RECIPE_ID" "$(printf '%s' "$BACKUP_KEY" | jq -R .)" "$(printf '%s' "$BACKUP_URL" | jq -R .)" >"$BACKUP_FILE"

port_busy() {
    python3 - "$HOST" "$PORT" <<'PY'
import socket, sys
sock = socket.socket()
sock.settimeout(0.5)
try:
    sock.connect((sys.argv[1], int(sys.argv[2])))
except OSError:
    raise SystemExit(1)
raise SystemExit(0)
PY
}

local_server_matches() {
    local length
    length="$(curl -fsSI --max-time 5 "$RECIPE_URL" 2>/dev/null | tr -d '\r' | awk 'tolower($1)=="content-length:" {print $2}' | tail -n 1)"
    [ -n "$length" ] && [ "$length" = "$BASE_SIZE" ]
}

# Port and local server plan.
REUSE_HTTP=0
if [ "$URL_IS_LOCAL" = "1" ]; then
    if port_busy; then
        if local_server_matches; then
            REUSE_HTTP=1
            note "http: reusing the existing server on $HOST:$PORT (serves $SERVE_NAME)"
        else
            if [ "$DRY_RUN" = "1" ]; then
                step "NOT READY"
                note "port $PORT is in use but does not serve $SERVE_NAME with $BASE_SIZE bytes"
                exit 2
            fi
            die2 "port $PORT is in use by a different server (pass --port N)"
        fi
    else
        note "http: will start $HOST:$PORT serving $SERVE_NAME"
    fi
else
    note "http: not needed (--url points elsewhere)"
fi

step "Checking install engine"
RESOLVED_ENGINE="$(resolve_engine)"
case "$RESOLVED_ENGINE" in
    example)
        EXAMPLE_BIN="$(find_example)"
        note "engine: launcher-core example ($EXAMPLE_BIN)"
        ;;
    deeplink)
        note "engine: lanomat://install/$SLUG deep link"
        note "target state: $(target_state "$TARGET")"
        ;;
    none)
        if [ "$DRY_RUN" = "1" ]; then step "NOT READY"; note "no launcher engine found"; exit 2; fi
        die2 "no install engine (build clients/launcher or set LANOMAT_INSTALL_EXAMPLE)"
        ;;
esac
note "target state: $(target_state "$TARGET")"

FREE_KB="$(df -Pk "$(dirname "$TARGET")" 2>/dev/null | awk 'NR==2 {print $4}')"
if [ -n "$FREE_KB" ]; then
    NEED_KB=$(( (BASE_SIZE * 2) / 1024 + 524288 ))
    human() { numfmt --to=iec-i --suffix=B "$1" 2>/dev/null || printf '%sB' "$1"; }
    note "disk: $(human "$((FREE_KB * 1024))") free, ~$(human "$((NEED_KB * 1024))") needed"
    if [ "$FREE_KB" -lt "$NEED_KB" ]; then
        [ "$DRY_RUN" = "1" ] && { step "NOT READY"; note "not enough free disk space"; exit 2; }
        die2 "not enough free disk space"
    fi
fi

if [ "$DRY_RUN" = "1" ]; then
    step "READY"
    note "would switch recipe $RECIPE_ID:"
    note "  $BACKUP_URL"
    note "  -> $RECIPE_URL"
    note "would install with $RESOLVED_ENGINE into $TARGET (timeout ${TIMEOUT_SECONDS}s)"
    note "would then restore the recipe, stop the local server and verify:"
    note "  System/Build.ini, System/ut2004-bin-amd64 (or System/UT2004), Maps/DM-Rankin.ut2"
    note "backup: $BACKUP_FILE"
    exit 0
fi

# ---------------------------------------------------------------------------
# Serve the archive.
# ---------------------------------------------------------------------------
if [ "$URL_IS_LOCAL" = "1" ]; then
    if [ "$REUSE_HTTP" = "1" ]; then
        step "Reusing the existing local HTTP server"
        curl -fsS -o /dev/null --max-time 5 -r 0-0 "$RECIPE_URL" || die "existing server does not answer $RECIPE_URL"
        note "not started by this run; it is left running"
    else
        step "Starting local HTTP server"
        SERVE_DIR="$TMP/serve"
        mkdir -p "$SERVE_DIR"
        ln -s "$BASE" "$SERVE_DIR/$SERVE_NAME"
        python3 -m http.server "$PORT" --bind "$HOST" --directory "$SERVE_DIR" >"$HTTP_LOG" 2>&1 &
        HTTP_PID=$!
        SERVER_STARTED=1
        for _ in $(seq 1 50); do
            if curl -fsS -o /dev/null --max-time 2 "http://$HOST:$PORT/$(python3 - "$SERVE_NAME" <<'PY'
import sys
from urllib.parse import quote
print(quote(sys.argv[1], safe=""))
PY
)" 2>/dev/null; then
                break
            fi
            kill -0 "$HTTP_PID" 2>/dev/null || die "local HTTP server exited (log: $HTTP_LOG)"
            sleep 0.2
        done
        curl -fsS -o /dev/null --max-time 5 -r 0-0 "$RECIPE_URL" || die "local server does not answer $RECIPE_URL"
        note "pid $HTTP_PID, log: $HTTP_LOG"
    fi
fi

# ---------------------------------------------------------------------------
# Switch the live recipe.
# ---------------------------------------------------------------------------
step "Switching live recipe $RECIPE_ID to the local URL"
if [ "$BACKUP_URL" = "$RECIPE_URL" ]; then
    note "recipe already points at $RECIPE_URL"
else
    if ! admin_recipe set "$RECIPE_URL" >"$TMP/admin-set.json" 2>"$TMP/admin-set.err"; then
        warn "$(cat "$TMP/admin-set.err" 2>/dev/null || true)"
        die "could not update recipe $RECIPE_ID"
    fi
    RECIPE_SWITCHED=1
fi
if ! wait_for_plan_url "$RECIPE_URL" 30; then
    if [ "$BACKUP_URL" != "$RECIPE_URL" ]; then RECIPE_SWITCHED=1; fi
    die "plan still shows a different URL after the switch ($(plan_base_url "$TMP/plan.json" 2>/dev/null || echo '?'))"
fi
note "plan now serves: $(plan_base_url "$PLAN_JSON")"

# ---------------------------------------------------------------------------
# Install.
# ---------------------------------------------------------------------------
install_with_example() {
    local rc=0
    step "Installing with launcher-core (timeout ${TIMEOUT_SECONDS}s)"
    note "log: $INSTALL_LOG"
    (
        cd "$ROOT/clients/launcher"
        set +e
        timeout --foreground --signal=TERM --kill-after=30 "$TIMEOUT_SECONDS" \
            env LANOMAT_URL="$SERVER_URL" LANOMAT_TOKEN="$TOKEN" \
                LANOMAT_TARGET="$TARGET" "$EXAMPLE_BIN"
        exit $?
    ) 2>&1 | tee -a "$INSTALL_LOG"
    rc=${PIPESTATUS[0]}
    if [ "$rc" = "124" ] || [ "$rc" = "137" ]; then
        die "install timed out after ${TIMEOUT_SECONDS}s (log: $INSTALL_LOG)"
    fi
    [ "$rc" = "0" ] || die "install failed with exit code $rc (log: $INSTALL_LOG)"
}

install_with_deeplink() {
    step "Installing with the installed launcher (deep link)"
    local link="lanomat://install/$SLUG"
    if [ -n "${LANOMAT_LAUNCHER_BIN:-}" ] && [ -x "$LANOMAT_LAUNCHER_BIN" ]; then
        "$LANOMAT_LAUNCHER_BIN" "$link" >"$INSTALL_LOG" 2>&1 &
    elif [ -x "$HOME/.local/bin/lanomat-launcher" ]; then
        "$HOME/.local/bin/lanomat-launcher" "$link" >"$INSTALL_LOG" 2>&1 &
    else
        xdg-open "$link" >"$INSTALL_LOG" 2>&1 &
    fi
    local launcher_pid=$!
    local start now elapsed last=-1 state size
    start="$(date +%s)"
    while :; do
        now="$(date +%s)"
        elapsed=$((now - start))
        state="$(target_state "$TARGET")"
        size="$(du -sb "$TARGET" 2>/dev/null | awk '{print $1}' || echo 0)"
        if [ "$size" != "$last" ]; then
            printf '    [%4ss] %s, %s bytes\n' "$elapsed" "$state" "$size"
            last="$size"
        fi
        if [ "$state" = "verified" ]; then
            note "target verified after ${elapsed}s"
            break
        fi
        if [ "$elapsed" -ge "$TIMEOUT_SECONDS" ]; then
            die "deep-link install did not verify within ${TIMEOUT_SECONDS}s (log: $INSTALL_LOG)"
        fi
        kill -0 "$launcher_pid" 2>/dev/null || true
        sleep 5
    done
    wait "$launcher_pid" 2>/dev/null || true
}

case "$RESOLVED_ENGINE" in
    example) install_with_example ;;
    deeplink) install_with_deeplink ;;
esac

step "Verifying target"
if ! check_target "$TARGET"; then
    note "install log: $INSTALL_LOG"
    die "target does not contain the expected UT2004 layout"
fi
TARGET_VERIFIED=1
note "System/Build.ini, System/ut2004-bin-amd64 (or System/UT2004), Maps/DM-Rankin.ut2: ok"
note "size: $(du -sh "$TARGET" 2>/dev/null | awk '{print $1}')"

if [ "$LAUNCH" = "1" ]; then
    step "Starting the game for a few seconds (optional)"
    local_bin="$TARGET/System/ut2004-bin-amd64"
    [ -x "$local_bin" ] || local_bin="$TARGET/System/UT2004"
    if [ -x "$local_bin" ]; then
        ( cd "$TARGET/System" && timeout 15 "$local_bin" ) >"$TMP/launch.log" 2>&1 || rc=$?
        rc=${rc:-0}
        if [ "$rc" = "124" ]; then
            note "game kept running for 15s (expected for a GUI game)"
        else
            warn "game exited with $rc (see $TMP/launch.log)"
        fi
    else
        warn "no executable to start"
    fi
fi

step "Done"
note "install verified in $TARGET"
if [ "$NO_RESTORE" = "1" ]; then
    note "recipe $RECIPE_ID stays on: $RECIPE_URL"
    note "backup (previous URL $BACKUP_URL): $BACKUP_FILE"
else
    note "recipe $RECIPE_ID will be restored to: $BACKUP_URL"
fi
exit 0
