#!/bin/sh
# Deployment proof for the LANoMAT docs container.
#
# Runs the curl matrix that the deployment card requires against an already
# running container (default: the local preview on port 4015). POSIX sh, no
# jq, no bashisms; it only needs curl.
#
#   docker build -t lanomat-docs docs-site
#   docker run -d --name lanomat-docs -p 4015:8080 lanomat-docs
#   docs-site/deploy/verify.sh                      # BASE_URL defaults to :4015
#   BASE_URL=https://lan.example docs-site/deploy/verify.sh
#
# Exits 0 only if every assertion passes. Prints one line per check.

set -u

BASE_URL="${BASE_URL:-http://127.0.0.1:4015}"
BASE_URL="${BASE_URL%/}"

fails=0
checks=0

# fetch PATH [curl args...] -> sets CODE, HEADERS, BODY (BODY only with BODY=1)
fetch() {
    path=$1
    shift
    [ -n "${HEADERS:-}" ] && rm -f "$HEADERS"
    tmp="$(mktemp)"
    headers="$(mktemp)"
    CODE="$(curl -sS -o "$tmp" -D "$headers" -w '%{http_code}' "$@" "$BASE_URL$path")" || CODE=000
    HEADERS="$headers"
    if [ "${WANT_BODY:-0}" = "1" ]; then
        BODY="$(cat "$tmp")"
    fi
    rm -f "$tmp"
    # HEADERS is returned via the file; callers read it before the next fetch.
}

header() {
    # header NAME -> first value, trimmed, case-insensitive
    tr -d '\r' < "$HEADERS" | awk -v name="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" '
        BEGIN { IGNORECASE = 1 }
        index(tolower($0), name ":") == 1 { sub(/^[^:]*:[[:space:]]*/, ""); print; exit }
    '
}

pass() { checks=$((checks + 1)); printf 'PASS  %s\n' "$1"; }
fail() { checks=$((checks + 1)); fails=$((fails + 1)); printf 'FAIL  %s\n' "$1"; }

expect_code() { # description path expected
    fetch "$2"
    if [ "$CODE" = "$3" ]; then pass "$1 ($2 -> $CODE)"; else fail "$1 ($2 -> $CODE, want $3)"; fi
}

expect_redirect() { # description path expected-code expected-location
    fetch "$2"
    loc="$(header Location)"
    if [ "$CODE" = "$3" ] && [ "$loc" = "$4" ]; then
        pass "$1 ($2 -> $CODE, Location: $loc)"
    else
        fail "$1 ($2 -> $CODE, Location: ${loc:-none}; want $3, ${4})"
    fi
}

expect_header() { # description path header-regex
    fetch "$2"
    value="$(tr -d '\r' < "$HEADERS" | grep -Ei "^$3:" | head -1)"
    if [ -n "$value" ]; then pass "$1 ($2: ${value%%:*} present)"; else fail "$1 ($2: missing header $3)"; fi
}

expect_content_type() { # description path prefix
    fetch "$2"
    ctype="$(header Content-Type)"
    case "$ctype" in
        "$3"*) pass "$1 ($2 -> $ctype)" ;;
        *) fail "$1 ($2 -> ${ctype:-none}; want $3*)" ;;
    esac
}

echo "Docs deployment check against $BASE_URL"
echo

# --- entry points ---------------------------------------------------------
WANT_BODY=1 fetch /docs/
rctype="$(header Content-Type)"
if [ "$CODE" = "200" ] && printf '%s' "$BODY" | grep -q 'Run the LAN, not the spreadsheet'; then
    pass "docs root is 200 with the introduction page ($rctype)"
else
    fail "docs root is not the rendered introduction (status $CODE, ${#BODY} bytes)"
fi
WANT_BODY=0
expect_code "docs root deep-links into the intro" /docs/start/introduction 200
expect_redirect "prefix without slash redirects" /docs 301 /docs/
expect_redirect "host root redirects into the docs" / 302 /docs/
expect_redirect "short /docs/games redirects to the library" /docs/games 301 /docs/system/games-library
expect_redirect "short /docs/mcp redirects to the overview" /docs/mcp 301 /docs/mcp/overview
expect_code "OpenAPI reference" /docs/api 200
expect_code "recipe deep link" /docs/recipes/ut2004 200
expect_code "module deep link" /docs/system/overview 200

# HTML is revalidated on every request so a deploy is visible immediately.
for p in /docs/ /docs/api; do
    fetch "$p"
    hcc="$(header Cache-Control)"
    case "$hcc" in
        *no-cache*) pass "HTML is no-cache ($p: $hcc)" ;;
        *) fail "HTML Cache-Control for $p is '${hcc:-missing}'" ;;
    esac
done

# Redirects must stay path-relative: an absolute Location built from the
# container's own :8080 would leak into the browser behind Traefik.
fetch /docs
loc="$(header Location)"
case "$loc" in
    /*) pass "redirect Location is path-relative ($loc)" ;;
    *) fail "redirect Location is absolute/leaky: ${loc:-none}" ;;
esac

# --- unknown paths --------------------------------------------------------
expect_code "unknown page under /docs is 404" /docs/nope 404
expect_code "unknown nested page is 404" /docs/api/nope 404
expect_code "unknown path outside /docs is 404" /nope 404
expect_code "unknown asset is 404 (never the shell)" /docs/assets/nope.js 404
expect_code "stock nginx index.html is gone" /index.html 404

fetch /docs/nope
ncc="$(header Cache-Control)"
case "$ncc" in
    *no-cache*) pass "404 responses are not cached ($ncc)" ;;
    *) fail "404 Cache-Control is '${ncc:-missing}'" ;;
esac

# --- redirects keep query strings ----------------------------------------
fetch '/docs/games?from=app'
gloc="$(header Location)"
if [ "$CODE" = 301 ] && [ "$gloc" = "/docs/system/games-library?from=app" ]; then
    pass "short-path redirect keeps the query string ($gloc)"
else
    fail "/docs/games?from=app -> $CODE '${gloc:-none}' (want 301 with ?from=app)"
fi

fetch '/docs?utm_source=test'
ploc="$(header Location)"
if [ "$CODE" = 301 ] && [ "$ploc" = "/docs/?utm_source=test" ]; then
    pass "prefix redirect keeps the query string ($ploc)"
else
    fail "/docs?utm_source=test -> $CODE '${ploc:-none}' (want 301 /docs/?utm_source=test)"
fi

# --- built output has no doubled basePath --------------------------------
for p in /docs/system/overview /docs/system/event-people /docs/system/integrations; do
    WANT_BODY=1 fetch "$p"
    if printf '%s' "$BODY" | grep -q '/docs/docs/'; then
        fail "$p contains a doubled basePath (/docs/docs/...)"
    else
        pass "$p has no doubled basePath"
    fi
    WANT_BODY=0
done

# --- assets, caching, compression ----------------------------------------
WANT_BODY=1 fetch /docs/start/introduction
asset_css="$(printf '%s' "$BODY" | grep -o '/docs/assets/[^"]*\.css' | head -1)"
asset_js="$(printf '%s' "$BODY" | grep -o '/docs/assets/[^"]*\.js' | head -1)"
WANT_BODY=0

if [ -n "$asset_css" ]; then
    fetch "$asset_css"
    ctype="$(header Content-Type)"
    cache="$(header Cache-Control)"
    case "$ctype/$cache" in
        "text/css"*"immutable"*) pass "hashed css: correct type and immutable cache ($ctype; $cache)" ;;
        *) fail "hashed css: type=$ctype cache=$cache" ;;
    esac
else
    fail "could not discover a hashed css asset in /docs/start/introduction"
fi

if [ -n "$asset_js" ]; then
    gz="$(curl -sS -o /dev/null -D - -H 'Accept-Encoding: gzip' "$BASE_URL$asset_js" | tr -d '\r' | grep -i '^content-encoding:' | head -1)"
    plain="$(curl -sS -o /dev/null -w '%{size_download}' "$BASE_URL$asset_js")"
    zipped="$(curl -sS -o /dev/null -w '%{size_download}' -H 'Accept-Encoding: gzip' "$BASE_URL$asset_js")"
    if [ -n "$gz" ] && [ "$zipped" -lt "$plain" ]; then
        pass "hashed js gzips (${plain} -> ${zipped} bytes)"
    else
        fail "hashed js does not gzip (header='${gz:-none}', ${plain} -> ${zipped})"
    fi
else
    fail "could not discover a hashed js asset in /docs/start/introduction"
fi

fetch /docs/screens/home-dark.png
scache="$(header Cache-Control)"
case "$(header Content-Type)/$scache" in
    "image/png"*"max-age=3600"*) pass "screenshot: image/png, one-hour cache ($scache)" ;;
    *) fail "screenshot: type=$(header Content-Type) cache=$scache" ;;
esac

# --- download and text formats -------------------------------------------
expect_content_type "OpenAPI YAML download" /docs/api/1.0.0/lanomat-openapi.yaml application/yaml
expect_content_type "llms.txt" /docs/llms.txt text/plain
expect_content_type "page markdown source" /docs/recipes/ut2004.md text/markdown

# --- security headers -----------------------------------------------------
fetch /docs/
for h in "X-Content-Type-Options: nosniff" \
         "X-Frame-Options: SAMEORIGIN" \
         "Referrer-Policy: strict-origin-when-cross-origin" \
         "Permissions-Policy: camera" \
         "Cross-Origin-Opener-Policy: same-origin" \
         "Strict-Transport-Security: max-age=31536000; includeSubDomains; preload"; do
    name="${h%%:*}"
    want="${h#*: }"
    got="$(header "$name")"
    case "$got" in
        "$want"*) pass "security header $name on /docs/" ;;
        *) fail "security header $name on /docs/ is '${got:-missing}' (want '$want')" ;;
    esac
done

# Headers must also survive on the immutable-assets location (nginx drops
# inherited add_header in a location that sets its own).
if [ -n "$asset_css" ]; then
    fetch "$asset_css"
    missing=""
    for name in X-Content-Type-Options X-Frame-Options Referrer-Policy Permissions-Policy; do
        [ -n "$(header "$name")" ] || missing="$missing $name"
    done
    if [ -z "$missing" ]; then pass "security headers on hashed assets"; else fail "missing on assets:$missing"; fi
fi

fetch /docs/
server="$(header Server)"
if [ "$CODE" = "200" ] && [ -n "$server" ]; then
    case "$server" in
        *[0-9]*) fail "Server header leaks a version: $server" ;;
        *) pass "Server header hides the version ($server)" ;;
    esac
else
    fail "could not read the Server header (status $CODE, value '${server:-none}')"
fi

echo
if [ "$fails" -eq 0 ]; then
    echo "OK: $checks checks passed."
    exit 0
fi
echo "FAILED: $fails of $checks checks failed."
exit 1
