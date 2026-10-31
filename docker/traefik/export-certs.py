#!/usr/bin/env python3
"""Export the certificates Traefik obtained (acme.json) as plain PEM files.

Services that cannot use Traefik for TLS, such as Mumble, serve the same trusted
certificate from these files. Runs forever: it looks at acme.json every few seconds and rewrites
a certificate only when its content changed, so a renewal reaches the files by itself.

Layout below the destination (stable names, no guessing which certificate is which):
    <name>/certificate.crt            certificate with its chain
    wildcard.<domain>/certificate.crt for the wildcard certificate of *.<domain>
    <dir>/privatekey.key              the private key, readable by group CERTS_GID only (0640)
"""

import base64
import json
import os
import sys
import time

SOURCE = os.environ.get("ACME_JSON", "/data/acme.json")
DEST = os.environ.get("CERTS_DIR", "/certs")
GID = int(os.environ.get("CERTS_GID", "1000"))
INTERVAL = int(os.environ.get("CERTS_INTERVAL_SECONDS", "30"))


def apply_attributes(path: str, mode: int) -> bool:
    """Make mode and group what they should be; True if something had to change."""
    info = os.stat(path)
    changed = False

    if (info.st_mode & 0o777) != mode:
        os.chmod(path, mode)
        changed = True

    if info.st_gid != GID:
        try:
            os.chown(path, -1, GID)
            changed = True
        except PermissionError:
            pass

    return changed


def write_if_changed(path: str, data: bytes, mode: int) -> bool:
    """Write the file when its content changed, and keep its mode and group right either way.

    The group matters: a service reads the private key through it, and the group of a
    service's image can change between its versions (Mumble's image runs as 1000 in
    1.4 and as 10000 in 1.5), so unchanged content must still get the new group.
    """
    try:
        with open(path, "rb") as handle:
            if handle.read() == data:
                return apply_attributes(path, mode)
    except FileNotFoundError:
        pass

    tmp = f"{path}.tmp"
    with open(tmp, "wb") as handle:
        handle.write(data)
    os.chmod(tmp, mode)
    try:
        os.chown(tmp, -1, GID)
    except PermissionError:
        pass
    os.replace(tmp, path)
    return True


def export_once() -> None:
    with open(SOURCE, "rb") as handle:
        store = json.load(handle)

    for resolver, content in store.items():
        for entry in (content or {}).get("Certificates") or []:
            domain = entry.get("domain") or {}
            main = domain.get("main")
            if not main or not entry.get("certificate") or not entry.get("key"):
                continue

            # Traefik stores a wildcard certificate under the name "*.<domain>"; an asterisk in a
            # directory name is a trap in shells and mounts, so it becomes "wildcard.<domain>".
            name = f"wildcard.{main[2:]}" if main.startswith("*.") else main
            directory = os.path.join(DEST, name)
            os.makedirs(directory, exist_ok=True)

            changed = write_if_changed(
                os.path.join(directory, "certificate.crt"),
                base64.b64decode(entry["certificate"]),
                0o644,
            )
            changed |= write_if_changed(
                os.path.join(directory, "privatekey.key"),
                base64.b64decode(entry["key"]),
                0o640,
            )
            if changed:
                print(f"exported {name} (resolver {resolver})", flush=True)


def main() -> None:
    while True:
        try:
            export_once()
        except FileNotFoundError:
            print(f"waiting for {SOURCE}", flush=True)
        except (ValueError, KeyError) as error:
            print(f"cannot read {SOURCE}: {error}", file=sys.stderr, flush=True)
        time.sleep(INTERVAL)


if __name__ == "__main__":
    main()
