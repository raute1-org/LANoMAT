#!/usr/bin/env python3
"""Keep the file host's copy of the wildcard certificate current.

nginx on the file host reads `fullchain.pem` and `privkey.pem` from its certs directory. The
certificate itself is renewed elsewhere (Traefik, exported by `certs-export` as `certificate.crt`
and `privatekey.key`), so a copy goes stale after ~60 days unless something carries renewals over.
This runs forever next to nginx: when the source holds a different pair, it checks that the
certificate and key belong together, replaces both files and tells nginx to reload.

Nothing is replaced when the new pair is unreadable or mismatched, so a half-written export never
breaks the working copy. nginx is reloaded by SIGHUP to its master process: the sidecar shares
nginx's PID namespace (compose `pid: service:file-host`), where the master is PID 1.
"""

import os
import signal
import ssl
import sys
import tempfile
import time

SOURCE = os.environ.get("CERT_SOURCE_DIR", "/source")
DEST = os.environ.get("CERTS_DIR", "/certs")
INTERVAL = int(os.environ.get("CERTS_INTERVAL_SECONDS", "60"))

# (name at the source, name at the destination, mode)
FILES = (
    ("certificate.crt", "fullchain.pem", 0o644),
    ("privatekey.key", "privkey.pem", 0o600),
)


def read(path: str) -> bytes | None:
    try:
        with open(path, "rb") as handle:
            return handle.read()
    except FileNotFoundError:
        return None


def reload_nginx() -> None:
    os.kill(1, signal.SIGHUP)


def sync_once(source: str, dest: str, reload=reload_nginx) -> str:
    """Returns "missing", "unchanged", "invalid" or "updated"."""
    pair = [read(os.path.join(source, name)) for name, _, _ in FILES]

    if any(not data for data in pair):
        return "missing"

    if all(read(os.path.join(dest, target)) == data for (_, target, _), data in zip(FILES, pair)):
        return "unchanged"

    os.makedirs(dest, exist_ok=True)
    staged = []

    try:
        for (_, target, mode), data in zip(FILES, pair):
            handle, tmp = tempfile.mkstemp(dir=dest, prefix=f".{target}.")
            staged.append((tmp, os.path.join(dest, target), mode))
            with os.fdopen(handle, "wb") as out:
                out.write(data)
            os.chmod(tmp, mode)

        try:
            context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
            context.load_cert_chain(staged[0][0], staged[1][0])
        except (ssl.SSLError, ValueError, OSError):
            return "invalid"

        for tmp, final, _ in staged:
            os.replace(tmp, final)
        staged = []
    finally:
        for tmp, _, _ in staged:
            try:
                os.unlink(tmp)
            except FileNotFoundError:
                pass

    reload()
    return "updated"


def main() -> None:
    last = None
    while True:
        try:
            result = sync_once(SOURCE, DEST)
        except OSError as error:
            result = f"error: {error}"

        # Log only changes of state, not one line per minute.
        if result != last:
            print(f"certificate sync: {result}", flush=True)
            last = result
        time.sleep(INTERVAL)


if __name__ == "__main__":
    sys.exit(main())
