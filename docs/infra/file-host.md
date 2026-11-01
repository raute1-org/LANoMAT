# File host: shared files served from the LanCache machine

Participants share files at a LAN (mods, maps, installers, ISOs). The `Files` module can keep those files
**only on the LanCache host**, the machine with the fastest network link and the SSD RAID, and serve every
download from there:

```text
upload    browser ──► app (ticket: rules, quota) ; browser ──tus, chunked, resumable──► tusd on the LanCache host
download  browser ──► app (policy check) ──302 + signed link──► browser ──HTTPS──► nginx on the LanCache host
```

The app never streams the bytes of a file-host file and keeps no copy. With the resumable upload path below
(`FILES_TUS_URL` set) it never even receives them: the browser sends them to `tusd` on the file host. On the legacy
path it receives the form upload, forwards it over SFTP through a managed `RemoteHost` (Hosts module) and deletes
its temporary upload. After
`SharedFilePolicy::download` allows a download, `FilePageController::download` answers with a redirect to a
short-lived signed URL. nginx does the rest (sendfile, `Range`/resume).

This is opt-in. Without configuration `FILES_STORE=local` keeps today's behaviour (private Laravel disk, streamed
by the app). Each `shared_files` row remembers its store in `disk` (`local` or `filehost`), so switching the
setting never strands existing files.

The repository is public: every address, name, key and secret below is a placeholder.

## Placeholders

| Placeholder           | Meaning                                                                                             |
| --------------------- | --------------------------------------------------------------------------------------------------- |
| `<LANCACHE_HOST>`     | the LanCache machine (see [proxmox-test-vm-lancache.md](proxmox-test-vm-lancache.md))               |
| `<LAN_DOMAIN>`        | the venue domain with the wildcard certificate ([lan-tls-certificates.md](lan-tls-certificates.md)) |
| `<FILES_ROOT>`        | directory on the SSD storage, e.g. `/srv/lanomat-files`                                             |
| `<FILES_HOST_SECRET>` | shared signing secret, `openssl rand -hex 32`, root-only on both sides                              |
| `<FILES_SFTP_USER>`   | the restricted SFTP account, e.g. `lanomat-files`                                                   |
| `<HOST_ID>`           | id of the registered host in LANoMAT (admin, Hosts)                                                 |
| `<TUS_HOOK_SECRET>`   | secret nginx adds to tusd's hook calls, `openssl rand -hex 32`, root-only on both sides             |
| `<APP_HOST>`          | the app's public host name, e.g. `app.<LAN_DOMAIN>`: where nginx relays the hooks to                |
| `<FILES_UID>`         | numeric uid of `<FILES_SFTP_USER>` (`id -u`)                                                        |

## What was verified

Verified locally against a real `nginx:1.27-alpine` container (self-signed certificate, 5 MB test file): the
signature that PHP produces is accepted, range requests and `curl -C -` resume work, expired links answer 410,
tampered path/name/signature/expiry and a missing expiry answer 403, no directory listing, header values
cannot be injected through the file name, the access log carries no signature, the hardened container flags and
the compose health check work.

**Not verified** (needs the real machines): SFTP to the chrooted account (the phpseclib calls follow its documented
API, the app code is tested against a fake), the wildcard certificate on the file host, 4 GB transfers and real
throughput on the SSD RAID, behaviour next to the real LanCache. The checklist at the end covers those.

## Where it listens (coexistence with LanCache)

LanCache's own nginx owns ports **80 and 443** on `<LANCACHE_HOST>` and answers for the game CDN names. The file
host is a **separate nginx container on port 8443 of the same address**:

- `https://files.<LAN_DOMAIN>:8443` is `FILES_HOST_URL`. `files.<LAN_DOMAIN>` must resolve to `<LANCACHE_HOST>`
  for LAN clients (venue DNS, a wildcard `*.<LAN_DOMAIN>` record already does).
- Do not reuse LanCache's nginx, and do not publish 80/443 from the file-host container.
- If you prefer plain port 443, add a second IP address to the host's NIC, bind the container to that address
  (`FILES_BIND_IP`), change `8443` in `compose.yml` and the template, and drop the port from `FILES_HOST_URL`.
- Downloads are LAN-only by design. A client outside the venue cannot reach `files.<LAN_DOMAIN>`; the redirect
  would not work for them.

## Step 1: directory on the SSD storage

```bash
install -d -m 755 <FILES_ROOT>              # chroot root: owned by root, not writable
install -d -m 755 <FILES_ROOT>/certs
useradd --system --shell /usr/sbin/nologin --home-dir <FILES_ROOT> <FILES_SFTP_USER>
install -d -o <FILES_SFTP_USER> -g <FILES_SFTP_USER> -m 755 <FILES_ROOT>/data
install -d -m 755 <FILES_ROOT>/guard       # flag file of the free-space guard (resumable uploads)
```

`data/` is where the uploads land (`/data/event-<id>/<uuid>`, the original name is never part of the path). It is
the only place the SFTP account can write and the only directory nginx mounts for downloads, read-only. Put it
on the SSD RAID. Quota accounting stays in the app; the disk needs room for `FILES_PER_USER_QUOTA_MB` times
users times events.

## Step 2: SFTP account restricted to that directory (key-only, chrooted)

`/etc/ssh/sshd_config.d/lanomat-files.conf`:

```text
Match User <FILES_SFTP_USER>
    ChrootDirectory <FILES_ROOT>
    ForceCommand internal-sftp -u 022
    PasswordAuthentication no
    AuthenticationMethods publickey
    AllowTcpForwarding no
    X11Forwarding no
    PermitTTY no
    PermitTunnel no
```

- `-u 022` makes uploaded files world-readable, which the nginx container needs (it runs as an unprivileged user).
- The account has no shell, so LANoMAT never runs commands for the file host: upload, delete and exists are pure
  SFTP operations (`RemoteExecutor::uploadFile/deleteFile/fileExists`).
- Inside the chroot the writable directory appears as `/data`, which is `FILES_HOST_PATH`.
- Restrict the key further in `~<FILES_SFTP_USER>/.ssh/authorized_keys` with
  `from="<APP_SERVER_ADDRESS>",no-agent-forwarding,no-port-forwarding,no-pty`.
- `sshd -t && systemctl reload ssh`. Keep the `ufw`/DOCKER-USER rules from the base runbook: port 22 stays LAN or
  tunnel only.

Interrupted legacy (SFTP) uploads leave `*.part` files (renamed on success only). Sweep them daily:

```bash
find <FILES_ROOT>/data -name '*.part' -mmin +1440 -delete     # e.g. /etc/cron.daily/lanomat-files-parts
```

## Step 3: register the host in LANoMAT

Admin panel, Hosts, create a host: `<LANCACHE_HOST>`, port 22, user `<FILES_SFTP_USER>`, the private key matching
the `authorized_keys` entry. Then pin the host key (the same TOFU flow as every managed host, a mismatch aborts
before authentication) and note the id as `<HOST_ID>`. The host's role does not matter; a dedicated host entry
for this account is cleaner than reusing the LanCache admin login, because this account can only SFTP into
`<FILES_ROOT>`.

The `Files` module reaches Hosts only when the `hosts` module is enabled (`MODULE_HOSTS` or `MODULES_PROFILE=full`);
otherwise the file host store refuses with a clear error and nothing else changes.

## Step 4: TLS through the DNS-01 wildcard certificate

Downloads start from an HTTPS page, so the file host must be HTTPS with a certificate browsers trust. Reuse the
wildcard `*.<LAN_DOMAIN>` that the venue Traefik already issues ([lan-tls-certificates.md](lan-tls-certificates.md)):

1. Get the pair out of Traefik: the `certs-export` service ([local-service-tls.md](local-service-tls.md)) writes it as
   `wildcard.<LAN_DOMAIN>/certificate.crt` and `privatekey.key`.
2. Let the `cert-sync` service carry it over (`docker/file-host/sync-certs.py`, profile `cert-sync` in the file host's
   compose file). Point `CERT_SOURCE` at the directory that holds that pair and start it:

   ```bash
   CERT_SOURCE=<DIR_WITH_certificate.crt_AND_privatekey.key>
   docker compose --profile cert-sync up -d
   ```

   Every minute it compares the source with `<FILES_ROOT>/certs/{fullchain,privkey}.pem`. A different pair is checked
   (certificate and key must belong together), replaces both files and makes nginx reload; an unreadable or
   mismatched pair changes nothing, so a half-written export never breaks the working copy. It shares nginx's PID
   namespace and reloads it by `SIGHUP`, so it needs no Docker socket. It logs only changes of state.
3. **Same machine as Traefik** (the test VM): `CERT_SOURCE` is the host path of Traefik's certs volume plus
   `/wildcard.<LAN_DOMAIN>` (`docker volume inspect <PROJECT>_traefik_certs` shows the mountpoint).
   **Another machine** (the venue: file host on the LanCache machine, Traefik elsewhere): the source directory has to
   be filled from the Traefik host, for example by a restricted key and `rsync -t` run after each renewal, or an NFS
   mount. The sync step itself does not change.
4. The private key lives on the LanCache host now: treat that host like the Traefik host.
5. **Preflight** shows the certificate that the file host really serves. It warns 14 days before expiry and reports
   down 3 days before, so a sync that silently stopped is noticed in time.

If the venue Traefik is not reachable from the LanCache host, issue the certificate directly on it with any
DNS-01 client (for example `lego` with the same DNS token), for `files.<LAN_DOMAIN>` alone. Check `notAfter`
before the event.

## Step 5: run nginx

Copy `docker/file-host/compose.yml` and `docker/file-host/default.conf.template` from the repository to a
directory on `<LANCACHE_HOST>`, create the root-only `.env` (see the header of `compose.yml`), replace
`<FILES_ROOT>`, and start it. Nothing starts this automatically.

```bash
docker compose up -d
docker compose exec file-host nginx -V 2>&1 | tr ' ' '\n' | grep -E 'threads|secure_link|file-aio'
```

The official image is built with `--with-http_secure_link_module` and `--with-threads` (verified for 1.27), which
the configuration needs (`secure_link`, `aio threads`).

### Why the configuration looks the way it does

| Setting                                                                                   | Why                                                                                                                                                                                                                                                                                                                                |
| ----------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `sendfile on; tcp_nopush on; sendfile_max_chunk 1m`                                       | the kernel copies page cache to socket, packets are filled for bulk transfer, no single fast client can monopolise a worker                                                                                                                                                                                                        |
| no `directio` by default                                                                  | LAN parties download the same few files from many clients; the page cache serves repeats from RAM. Enable `directio 64m` (with the already configured `aio threads`) only if the file set far exceeds RAM and cold reads stall other clients: files at least that large then bypass the cache and are read through the thread pool |
| no `limit_rate`, `limit_conn 32` per client                                               | never throttles LAN speed; only stops a runaway client (429)                                                                                                                                                                                                                                                                       |
| `types { } default_type application/octet-stream`                                         | every file is an opaque octet stream                                                                                                                                                                                                                                                                                               |
| `Content-Disposition: attachment; filename*=UTF-8''<name>`                                | always a download with the original name; the name comes from the signed `n` argument                                                                                                                                                                                                                                              |
| `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; sandbox` | a browser never renders or executes a file from this origin, even if one slipped through                                                                                                                                                                                                                                           |
| `autoindex off`, `location / { return 404; }`                                             | no listing, nothing but signed `/dl/` links                                                                                                                                                                                                                                                                                        |
| access log with `$uri` only                                                               | the signature and file name are in the query string, which `$uri` excludes                                                                                                                                                                                                                                                         |
| `Range`                                                                                   | nginx handles `Accept-Ranges`, `206` and `If-Range` for static files (resume, download managers)                                                                                                                                                                                                                                   |

## Signed links: scheme and security properties

nginx's `ngx_http_secure_link_module` in the official image supports `secure_link_md5` only (the HMAC variant
`secure_link_hmac` is a third-party module, so it is not used). A link looks like:

```text
https://files.<LAN_DOMAIN>:8443/dl/event-3/<uuid>?expires=<unix>&md5=<signature>&n=<percent-encoded name>
signature = base64url( md5( "<expires><uri>|<n> <FILES_HOST_SECRET>" ) )     # no padding
```

- **Covered by the signature:** the expiry, the exact decoded path (`$uri`) and the download file name (`$arg_n`,
  as sent, percent-encoded). Changing any of the three, or the secret, makes the link invalid (403).
- **Expiry:** `FILES_URL_TTL_SECONDS` (default 300). nginx checks it when the request starts: a running download
  is never cut, but a resume after expiry needs a fresh click on "Herunterladen". Expired links answer 410. A
  link without `expires` is refused explicitly, because the module would otherwise treat it as unlimited.
- **Secret:** at least 32 characters of `[A-Za-z0-9_-]` (use `openssl rand -hex 32`); the app refuses anything
  shorter or containing characters that would break the nginx config. It is never part of a URL and never logged.
  It sits in the nginx configuration file inside the container and in `docker inspect` output: keep the `.env`
  and the Docker socket root-only. Rotating it invalidates all outstanding links (they live minutes), so rotate
  by changing both sides and restarting.
- **Primitive, honestly:** `md5(message || secret)` is not an HMAC. It is the construction the nginx module
  documents; with the secret at the end of the hashed string and the path before it, a length-extension attack
  cannot produce a valid link for another path. The comparison inside nginx is an ordinary string compare, not
  documented as constant-time; the short link lifetime and a secret of at least 32 characters make a timing attack
  over a LAN impractical. Do not claim more than that.
- **Not bound to the client address:** the link works from any address for its lifetime (NAT and dual-stack made
  binding unreliable). Treat a link as a bearer credential for a few minutes; the redirect response is
  `Cache-Control: no-store` with `Referrer-Policy: no-referrer`.
- **Permissions stay in the app.** A link is issued only after `SharedFilePolicy::download`: approved files for
  every participant, pending files only for the uploader and orga, rejected files for nobody.
- **The error log can contain the full request line** (including the signature) when a file is missing. The link
  is dead after its TTL; keep `files.error.log` root-readable and local.

## Upload size (legacy form upload)

This section only applies to the legacy path (`FILES_STORE=local`, or `host` without `FILES_TUS_URL`).
`FILES_MAX_UPLOAD_MB` (default 200) is its cap. To allow bigger files through the app, raise all of these
together, or the upload dies at the first layer that is smaller: `FILES_MAX_UPLOAD_MB` and `LIMIT_BODY_FILES_MB`
(`config/lanomat.php`), the Traefik `body-limit-files` middleware (`docker/traefik/dynamic.yml`, kept in sync by a
Pest test), PHP `upload_max_filesize` / `post_max_size` (`docker/frankenphp/php.ini`, 1G today) and the request
time. **Anything beyond about a gigabyte belongs on the resumable path below**, which has none of these limits.

## Resumable uploads (tus): whole games, around 15 GB per file

The form upload through PHP and Traefik cannot carry 15 GB and cannot resume. With `FILES_STORE=host` **and**
`FILES_TUS_URL` set, **all** uploads (mods and maps as well as games) take one path: the
[tus protocol](https://tus.io) straight from the browser to the official
[`tusd`](https://github.com/tus/tusd) server on the file host. The legacy form upload stays only for
`FILES_STORE=local` (and while `FILES_TUS_URL` is unset), and is refused while the resumable path is active, so
there is exactly one code path per mode.

```text
 1 browser ─POST /events/<slug>/files/uploads─► app: policy, size, type, quota reserved, single-use ticket
 2 browser ─tus POST (ticket in metadata)─► nginx :8443/upload/ ─► tusd ─pre-create hook─► nginx :8081 relay
                                              (adds the secret header) ─► app /internal/files/tusd-hook
                                      app: consume ticket, choose upload id + final path ─► ChangeFileInfo
 3 browser ─tus PATCH, 64 MiB chunks─► nginx (no buffering) ─► tusd ─► /data/event-<id>/<uuid>   (the app never sees bytes)
 4 tusd ─post-finish hook─► app: size == declared size, extension re-check, pending SharedFile, reservation completed
   tusd ─post-terminate hook─► app: reservation released
 5 scheduler ─lanomat:sweep-file-uploads─► deletes leftovers of abandoned uploads over SFTP, frees the quota
```

### Decisions and why

| Decision                                                    | Reason                                                                                                                                                                                                                                                                                                                         |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| tusd writes **directly to the final path**, no staging move | The app chooses the id and the path (`ChangeFileInfo.ID` / `Storage.Path` = `<data>/event-<id>/<uuid>`) in the pre-create hook, so there is no 15 GB copy or rename at the end (we have no shell on the host anyway, only SFTP). A partial file can never be served: it has no `shared_files` row, so no link is ever signed for it. |
| tusd's small `.info` files go to `<FILES_ROOT>/data/_tus/`  | Keeps the served `event-<id>/` directories free of metadata. `_tus` is inside the SFTP-writable tree, so the sweep can delete them over SFTP. nginx serves only signed `/dl/` links that the app signs for known rows, so nothing there is reachable.                                                                           |
| The ticket is an opaque random token, stored only as sha256 | Single use (cleared at pre-create), 10 minutes by default (`FILES_TUS_TICKET_TTL_SECONDS`), bound through its reservation row to user, event, exact file name and declared size. A self-contained signed token would need the same row for single use and quota anyway; random plus hash is simpler and cannot be forged.        |
| Quota is **reserved at ticket time**                        | Under the same per-(event, user) advisory lock as the legacy upload. Open reservations count; parallel uploads cannot exceed the quota. Asking again for the same file (a reload) rotates the ticket on the same reservation and does not reserve twice.                                                                         |
| Hook auth through an nginx relay                            | tusd can forward client headers to a hook but cannot add a static secret, and a secret in the hook URL is forbidden. nginx listens on :8081 inside the compose network only, adds `X-Tusd-Hook-Secret`, and forwards to the app. The app compares in constant time and refuses everything when no strong secret is configured. |
| Chunk size 64 MiB (`FILES_TUS_CHUNK_MB`)                    | A failed chunk costs at most this much re-sent data (tusd keeps the bytes of an interrupted request anyway and `HEAD` reports the real offset). 15 GB is about 240 requests. Bigger chunks risk proxy timeouts and long progress gaps, smaller ones multiply round trips. The browser slices the file from disk, memory stays small. |
| tusd `-disable-download`, `-expose-metrics=false`           | Otherwise anyone who knows an upload id could download a not yet approved file straight from tusd.                                                                                                                                                                                                                             |
| Checksum extension not used                                 | tusd 2.8 does not advertise it (`creation, creation-with-upload, termination, concatenation, creation-defer-length`). Integrity relies on TCP/TLS, tusd's offset accounting and the final size check.                                                                                                                          |
| tusd has no expiry for file storage                         | The expiration extension is not offered for the file store, so abandoned uploads are cleaned by the app's sweep.                                                                                                                                                                                                               |

### Limits (honest)

- **Content is not sniffed.** The app cannot read the bytes on another machine. At ticket time and again in
  `post-finish` it checks the extension of the declared name and the client-claimed type against the blocklist, and
  it checks that tusd's received size equals the declared size. It stores `application/octet-stream`. What protects
  the origin is as before: every download is an opaque `nosniff` attachment with a sandbox CSP, and every file waits
  for orga approval.
- **No per-chunk authentication.** After pre-create, tusd authorises `HEAD`/`PATCH`/`DELETE` by knowing the upload id
  (a random UUID chosen by the app). That is the tus model; it is what lets a reloaded tab resume without a new
  ticket.
- **`post-finish` is asynchronous.** If the app is down when tusd reports the finished file, tusd retries 5 times
  (2 s apart). After that the file exists without a row; the sweep removes it once the reservation expires
  (`FILES_TUS_UPLOAD_TTL_HOURS`, 24 h) and the user uploads again. Keep the app up during uploads.
- A reservation counts against the quota for at most `FILES_TUS_UPLOAD_TTL_HOURS` after the upload started, and for
  the ticket lifetime before that. A finished file whose reservation already expired is still accepted.

### Limits and defaults (provisional, to be confirmed by the owner)

| Setting                      | Default | Where else it must match                                                              |
| ---------------------------- | ------- | ------------------------------------------------------------------------------------- |
| `FILES_MAX_FILE_GB`          | 50      | `FILES_MAX_FILE_BYTES` in the file host's `.env` (tusd `-max-size`) = GB × 1073741824 |
| `FILES_PER_USER_QUOTA_MB`    | 102400  | disk sizing below; the page shows used and cap                                        |
| `FILES_TUS_UPLOAD_TTL_HOURS` | 24      | how long a half-finished upload may sit before the sweep deletes it                   |

The upload area shows the per-file maximum, and the quota readout counts files plus open reservations, from the same
settings.

### Disk layout, sizing and the free-space guard

```text
<FILES_ROOT>/data/event-<id>/<uuid>     finished and partial files (tusd and the SFTP account write, nginx reads)
<FILES_ROOT>/data/_tus/<uuid>.info      tusd metadata (a few hundred bytes each)
<FILES_ROOT>/guard/disk-full            flag, present while free space is low
```

- Size the disk for `FILES_PER_USER_QUOTA_MB` × expected uploaders (not everyone fills their quota) plus headroom
  for partial uploads. At 100 GB per person, 20 heavy uploaders are 2 TB.
- **Free-space guard:** tusd does not look at free space. `docker/file-host/disk-guard.sh` (cron, every minute) sets
  `<FILES_ROOT>/guard/disk-full` when less than `MIN_FREE_GB` is left. While it exists nginx answers every **new**
  upload (`POST`) with `507` and the UI says there is no room; running uploads continue.

  ```bash
  install -m 755 docker/file-host/disk-guard.sh /usr/local/bin/lanomat-files-disk-guard
  echo '* * * * * root /usr/local/bin/lanomat-files-disk-guard <FILES_ROOT> 200' > /etc/cron.d/lanomat-files-disk-guard
  ```

- `.info` files of finished uploads stay (so a `HEAD` after a reload still says "complete"). Sweep old ones daily:
  `find <FILES_ROOT>/data/_tus -name '*.info' -mtime +30 -delete`.
- **Permissions:** tusd runs as `<FILES_UID>:101`. The uid is the SFTP account's, so what tusd writes can be deleted
  over SFTP (reject, delete, sweep). Group 101 is the `nginx` group of the nginx image: tusd creates directories
  with mode `0754`, which nginx's workers can only enter through that group (checked locally: with another group
  they would not be able to read the files).

### Network path and firewall

- Browser to `https://files.<LAN_DOMAIN>:8443/upload/` (nginx, TLS from the wildcard certificate), then to tusd in
  the compose network. tusd has **no published port**.
- **tusd to the hook relay:** `http://file-host:8081/hook` inside the compose network. Port 8081 is not published;
  nothing outside the compose network can reach it, and the public listener answers 404 for `/hook`.
- **nginx to the app:** `FILES_APP_HOOK_URL` (`https://<APP_HOST>/internal/files/tusd-hook`), TLS verified with the
  system CA bundle (the public wildcard certificate works). The app's Traefik must let this path through from
  `<LANCACHE_HOST>`; you may restrict it to that address there. The shared secret is the authentication, the
  source address is defence in depth. Hook bodies carry the original file name, nothing secret.
- Firewall on `<LANCACHE_HOST>`: LAN clients to 8443/tcp only, as before; port 22 stays LAN or tunnel only.
- The browser's CSP `connect-src` gains the file host origin automatically (derived from `FILES_TUS_URL`).
- **CORS** is answered by tusd, not nginx (`-cors-allow-origin`, a regex): set `FILES_TUS_CORS_ORIGIN` to
  `^https://<LAN_DOMAIN_REGEX>$` (the app's origin, dots escaped). Any other origin gets no CORS headers.

### nginx settings for multi-hour transfers

`location /upload/` in `docker/file-host/default.conf.template`: `proxy_request_buffering off` and
`proxy_buffering off` (bytes stream straight to tusd instead of being spooled to disk first),
`client_max_body_size 0` (only for this location; tusd enforces `-max-size`, the app the ticket),
`client_body_timeout 300s` (between reads: a stalled client is dropped after 5 minutes and resumes with a new
request), `proxy_send_timeout` and `proxy_read_timeout` 3600 s. The timeouts apply between reads and writes, not to
the whole upload, so an upload of any length works as long as data keeps flowing.

### Setup

1. Steps 1 to 5 above, plus the `guard` directory, the guard cron and the `_tus` sweep cron.
2. In the file host's `.env` add `FILES_TUS_HOOK_SECRET`, `FILES_APP_HOOK_URL`, `FILES_MAX_FILE_BYTES`,
   `FILES_TUS_CORS_ORIGIN` and `FILES_UID` (see the header of `docker/file-host/compose.yml`); `docker compose up -d`
   starts nginx and `tusproject/tusd:v2.8`.
3. In the app's `.env`: `FILES_TUS_URL=https://files.<LAN_DOMAIN>:8443/upload/`, the same `FILES_TUS_HOOK_SECRET`,
   `FILES_MAX_FILE_GB` equal to the file host's limit, and `FILES_STORE=host` as before.
4. The scheduler runs `lanomat:sweep-file-uploads` every 10 minutes (Files module enabled). It is idempotent; a file
   host that cannot be reached just leaves the work for the next run.

### What was verified (resumable path)

Locally against real containers (`tusproject/tusd:v2.8`, `nginx:1.27-alpine`, self-signed certificate), a real
Laravel app container and `tus-js-client` 4.3.1 in Node as the client:

- 1.5 GB random file, upload aborted at 600 MB, resumed from the stored upload URL: finished, SHA-256 identical,
  pending `SharedFile` created by the `post-finish` hook through the nginx relay.
- 6 GB file with nginx restarted mid-transfer (connections killed): the client retried, `HEAD` returned the
  retained offset, the upload completed, `cmp` identical, row created.
- Download of a tusd-written file through the signed link: `200` and `206`.
- Rejected with the right codes: a ticket for another size or name (`ticket_mismatch`), a replayed and an unknown
  ticket (`ticket_invalid`), an expired ticket (`ticket_expired`), no ticket (`403`), 60 GB declared (`413`, tusd
  `-max-size`), a CORS preflight from a foreign origin (`403`), `GET` of an upload (`405`), `/hook` on the public
  port (`404`), the disk-guard flag (`507` on `POST` only).
- `DELETE` (terminate) removes both the data file at the custom path and the `.info` file.
- Neither a ticket nor the secret appears in the app log or the nginx access log.

**Not verified:** a real 15 GB transfer, real SSD and NIC throughput, the LanCache host, a real browser (the Vue
uploader was type-checked and linted, not driven by a browser), phones, SFTP deletes by the sweep against the real
chrooted account (the app side is tested against a fake), the venue Traefik path for the hook.

### Manual verification (on the real machines)

- [ ] `docker compose up -d`: both containers `healthy`; `docker compose logs tusd` shows the hook URL and the enabled events
- [ ] As a participant, upload a small `.zip` on the Files page: progress runs, the file shows "Wartet auf Freigabe" and exists in `<FILES_ROOT>/data/event-<id>/`
- [ ] **Resume after killing the connection:** start a multi-GB upload, pull the network cable or `docker compose restart file-host` mid-way: the page says "Verbindung unterbrochen", then continues by itself; the checksum of the stored file matches
- [ ] **Browser restart mid-upload:** close the browser during a big upload, reopen the page, select the same file: it continues at the earlier percentage ("Nach Unterbrechung fortgesetzt")
- [ ] **About 15 GB game** from a LAN client: note the sustained MB/s (expected: close to the link speed), the app server stays idle, the final size equals the source, orga sees it pending and can approve it
- [ ] **Expired ticket:** request a ticket (browser devtools), wait past `FILES_TUS_TICKET_TTL_SECONDS`, start the upload: clear German error, no stuck quota
- [ ] **Over quota:** with 100 GB used or reserved, a further file is refused before any byte is sent
- [ ] **Two parallel uploads** from one user: both run; their sizes together cannot exceed the quota
- [ ] **Abandoned upload:** start an upload, close the tab, wait past `FILES_TUS_UPLOAD_TTL_HOURS` (set it to 1 for the test) and run `php artisan lanomat:sweep-file-uploads`: partial file and `.info` are gone, the quota readout drops
- [ ] **Cancel** in the UI: partial file and `.info` disappear at once
- [ ] **Disk guard:** `touch <FILES_ROOT>/guard/disk-full`: a new upload says there is no room, a running one continues; remove the file
- [ ] `.html` and `.svg` are refused before upload; an `.exe` uploads and stays pending
- [ ] `POST https://files.<LAN_DOMAIN>:8443/hook` answers 404; `POST https://<APP_HOST>/internal/files/tusd-hook` without the secret answers 401

## Failure behaviour

| Situation                                            | Result                                                                                                                   |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| host unreachable, key mismatch, SFTP error on upload | German error toast, no row, no file (a partial `.part` is removed best effort)                                           |
| row cannot be written after the file landed          | the file is removed from the host again                                                                                  |
| delete or reject while the host is unreachable       | nothing changes, error toast, can be retried                                                                             |
| download with a broken file-host configuration       | 503 page, not a 500                                                                                                      |
| row exists but the file is gone                      | the redirect leads to nginx, which answers 404                                                                           |
| reject                                               | the file is removed from the host, the row stays (counts toward quota) and the file can no longer be approved: re-upload |

## Verification checklist (on the real machines)

- [ ] `docker compose up -d` healthy; `nginx -V` shows `--with-threads` and `secure_link`
- [ ] SFTP as `<FILES_SFTP_USER>` works with the key, fails with a password, cannot leave the chroot, has no shell
- [ ] LANoMAT: host registered, host key pinned, `FILES_STORE=host` set, upload a small `.zip` as a participant:
      it is `pending`, listed under the admin Files section with storage "Dateiserver", and the file exists in
      `<FILES_ROOT>/data/event-<id>/`
- [ ] Download as the uploader (pending) and as orga: browser address bar shows `https://files.<LAN_DOMAIN>:8443/...`,
      a green padlock, and the file saves under its original name
- [ ] Download of a pending file as another participant: forbidden; after approving it works
- [ ] Reject a pending file: it disappears from `data/`; the download answers forbidden
- [ ] Delete a file as its owner: it disappears from `data/`; with the SFTP service stopped the delete fails and the row stays
- [ ] **Resume:** start a large download, kill the browser connection (or `curl -C -`), resume; the file's checksum matches
- [ ] **Expired link:** wait past the TTL and replay the URL: 410
- [ ] **Tampered link:** change one character of the path, the name, `expires` or `md5`: 403
- [ ] **4 GB file:** with `FILES_MAX_UPLOAD_MB` and every layer above raised, upload and download a 4 GB file, compare checksums
- [ ] **Speed:** two or three LAN clients download the same big file at once; the link speed is the limit, the
      app server stays idle (watch `iostat`/`nload` on the host)
- [ ] An `.html` and an `.svg` upload are refused; an `.exe` is accepted but stays `pending`
- [ ] LanCache still works for a Steam download (ports 80/443 untouched)

## What LANoMAT automation would need

| Step                                                  | Existing building block                         | Gap                                                         |
| ----------------------------------------------------- | ----------------------------------------------- | ----------------------------------------------------------- |
| Register the host and pin its key                     | `Hosts` module (`RegisterHost`, `PinHostKey`)   | none                                                        |
| Create the directory, the SFTP user, the sshd drop-in | `Provisioning` steps over `RemoteExecutor`      | a `ProvisionFileHost` step                                  |
| Run the nginx container                               | `Provisioning` / `Lancache` stack apply pattern | a compose template step and a verify probe                  |
| Copy the wildcard certificate                         | none                                            | a certificate sync step plus an expiry `Preflight` probe    |
| Watch it                                              | `Preflight` health checks                       | a probe that signs a link for a canary file and expects 200 |
