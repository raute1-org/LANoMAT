# Trusted TLS for the services around the app (Music Assistant, Pelican panel, Mumble)

The app gets its certificate from Traefik. The services next to it (Music Assistant, the Pelican panel, the
voice servers) run outside that compose project and used to be plain `http://<host>:<port>` or self-signed.
This runbook gives them names below the venue domain and a **trusted** certificate, with one wildcard
certificate for all of them. All names, addresses and tokens are placeholders.

## What needs what

| Service                            | What it gets                                | Why                                                                                                                                  |
| ---------------------------------- | ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| Music Assistant web UI             | a name, TLS by Traefik                      | the browser UI and its WebSocket should not be plain http on a page people open on their phones                                      |
| Pelican panel                      | a name, TLS by Traefik, `APP_URL` set to it | same; the panel redirects to whatever `APP_URL` says                                                                                 |
| Mumble                             | the wildcard certificate itself             | its clients connect on port 64738 directly, not through Traefik; with a trusted certificate there is no warning on the first connect |
| LiveKit (web voice), the file host | a name, TLS                                 | the browser needs HTTPS for the microphone and for downloads from an HTTPS page; see their own runbooks                              |
| TeamSpeak 3                        | nothing                                     | it has its own protocol and encryption; there is no certificate authority to involve                                                 |
| LanCache                           | nothing                                     | it caches other people's HTTP downloads and cannot terminate their HTTPS; it has no certificate of its own                           |

## Placeholders

| Placeholder         | Meaning                                                             |
| ------------------- | ------------------------------------------------------------------- |
| `<LAN_DOMAIN>`      | the domain the names live below, with a DNS-01 capable zone         |
| `<SERVICE_HOST_IP>` | address of the host that runs Music Assistant and the Pelican panel |
| `<TRAEFIK_IP>`      | address of the venue Traefik                                        |

## Step 1: names

Three names, all resolving to the venue Traefik (`<TRAEFIK_IP>`) through the venue DNS
([lan-tls-certificates.md](lan-tls-certificates.md), step 1): `music.<LAN_DOMAIN>`, `panel.<LAN_DOMAIN>`,
`voice.<LAN_DOMAIN>`. The Mumble name may point at the Mumble host instead; the certificate only has to match
the name clients type.

## Step 2: Traefik routes and the wildcard certificate

Traefik reads **one** directory of dynamic files with the venue static file
(`docker/traefik/traefik.venue.example.yml`: `providers.file.directory: /etc/traefik/dynamic`). Put the standard
rules and the venue's routes there:

```yaml
# compose.override.yml, in the traefik service's volumes
- ./docker/traefik/dynamic.yml:/etc/traefik/dynamic/dynamic.yml:ro
- ./docker/traefik/venue-services.yml:/etc/traefik/dynamic/venue-services.yml:ro
```

`venue-services.yml` is a copy of `docker/traefik/venue-services.example.yml` with the placeholders replaced.
Its first router asks for the wildcard certificate (`domains: main: <LAN_DOMAIN>`, `sans: ['*.<LAN_DOMAIN>']`);
the others use `tls: {}` and are served with it. A service that Traefik reaches over the host network needs the
host firewall to let the compose network's subnet reach its port (for Music Assistant: 8095).

## Step 3: the Pelican panel's own address

The panel redirects to its `APP_URL`. Set it to `https://panel.<LAN_DOMAIN>` in the panel's compose file and
restart the panel. `TRUSTED_PROXIES` must contain the address Traefik reaches the panel from (the Docker bridge
gateway when Traefik goes through a published port). Wings and LANoMAT keep talking to the panel by its address
(`remote:` in Wings' `config.yml`, `PELICAN_PANEL_URL`), which does not change.

## Step 4: Mumble

Mumble does not go through Traefik, so it needs the certificate as files. The `certs-export` service (profile
`venue-certs`, a small script, `docker/traefik/export-certs.py`) reads Traefik's `acme.json` and writes each
certificate below a volume that Mumble mounts read-only:

```text
/certs/<name>/certificate.crt              certificate with its chain
/certs/wildcard.<LAN_DOMAIN>/certificate.crt   the wildcard certificate (an asterisk in a name would be a trap)
/certs/<dir>/privatekey.key                private key, mode 0640, group CERTS_GID (Mumble's group, 10000)
```

It checks every 30 seconds and rewrites a file only when its content changed, so a renewal reaches the files by
itself; mode and group are corrected on every pass, so changing `CERTS_GID` takes effect without touching the volume.
The group must be the one the Mumble server runs as in its image: **10000 in 1.5.x, 1000 in the old 1.4.x image**. With
the wrong one Mumble stops at start with "Failed to read .../privatekey.key" (seen when moving the test server from
1.4.230 to 1.5.939). Then in `.env`:

```bash
MUMBLE_SSL_CERT=/certs/wildcard.<LAN_DOMAIN>/certificate.crt
MUMBLE_SSL_KEY=/certs/wildcard.<LAN_DOMAIN>/privatekey.key
```

and `docker compose --profile prod --profile venue-certs up -d certs-export` followed by recreating `mumble`.
Empty values keep Mumble's self-signed default, so nothing changes for installs that do not set them. After a
renewal (every ~60 days) Mumble keeps serving the old certificate until it reloads: restart the container or send
it `SIGUSR1`; check the expiry a week before an event.

## Verified (test VM, real Let's Encrypt, Cloudflare DNS-01)

- One wildcard certificate for the apex and `*.<LAN_DOMAIN>` issued by the production CA through the DNS-01
  challenge; it lasts 90 days.
- Music Assistant answers on its name with that certificate, the browser UI included.
- The panel answers on its name and its login page loads after `APP_URL` was changed; Wings and LANoMAT kept
  working.
- Mumble loads the certificate with its three intermediates and a TLS client verifies it against
  `voice.<LAN_DOMAIN>:64738` (`Verify return code: 0`).
- The earlier choice, `ldez/traefik-certs-dumper`, crashed on this Traefik's `acme.json` ("unsupported key type")
  and left unreadable key files; the small exporter replaced it.

Not verified: a real Mumble client's own trust dialog, a renewal reaching Mumble, the names through a MikroTik
resolver instead of public DNS records.

## Things that went wrong on the way

- A probe that follows redirects: after `APP_URL` changed, the panel answered the app's reachability probe with a
  redirect to its new name, which a container on the same host cannot reach (it cannot reach the host's second
  address). Reachability probes now count a redirect as an answer and do not follow it.
- A wildcard certificate is stored by Traefik under the name `*.<domain>`; scripts and mounts should not use that
  as a directory name.
