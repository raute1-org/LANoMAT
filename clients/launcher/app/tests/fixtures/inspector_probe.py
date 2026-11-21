#!/usr/bin/env python3
"""Best-effort WebKit remote-inspector probe for the launcher shell smoke test.

A debug Tauri build enables the WebKitGTK developer extras, and `WEBKIT_INSPECTOR_SERVER` makes
WebKit listen for inspectors on a local port. The page it serves lists the inspectable targets
(usually by document title), which gives the smoke test a DOM-side confirmation that the app's
document loaded. The target list is saved verbatim so the evidence stays in the artifact folder.

    inspector_probe.py --port 9222 --out devtools.html

Exit 0 when the inspector server answered (the file is written), 1 when it did not. Never fatal:
WebKit versions differ in what they expose, so the smoke test only treats a hit as extra evidence.
"""
import argparse
import sys
import urllib.error
import urllib.request


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", type=int, required=True)
    parser.add_argument("--out", required=True)
    options = parser.parse_args()

    url = f"http://127.0.0.1:{options.port}/"

    try:
        with urllib.request.urlopen(url, timeout=3) as response:
            body = response.read()
            status = response.status
    except (urllib.error.URLError, OSError, ValueError) as error:
        print(f"unreachable: {error}", file=sys.stderr)
        return 1

    with open(options.out, "wb") as handle:
        handle.write(body)

    text = body.decode("utf-8", "replace")
    print(f"reachable: HTTP {status}, {len(body)} bytes -> {options.out}")

    for line in text.splitlines():
        if "LANoMAT" in line:
            print(line.strip()[:200])

    return 0


if __name__ == "__main__":
    sys.exit(main())
