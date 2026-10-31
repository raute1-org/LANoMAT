# File host: shared files served from the LanCache machine

Participants share files at a LAN (mods, maps, installers, ISOs). The `Files` module can keep those files
**only on the LanCache host**, the machine with the fastest network link and the SSD RAID, and serve every
download from there:

```text
upload    browser ──► app (checks, moderation row) ──SFTP, host key pinned──► LanCache host  /data/event-<id>/<uuid>
download  browser ──► app (policy check) ──302 + signed link──► browser ──HTTPS──► nginx on the LanCache host
```

The app never streams the bytes of a file-host file and keeps no copy: it receives the upload, forwards it over
SFTP through a managed `RemoteHost` (Hosts module), and deletes its temporary upload. After
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

Interrupted uploads leave `*.part` files (they are renamed on success only). Sweep them daily:

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

1. Export it from Traefik's `acme-lan.json` (for example with `traefik-certs-dumper file --watch`), producing
   `fullchain.pem` and `privkey.pem` for `*.<LAN_DOMAIN>`.
2. Copy both to `<FILES_ROOT>/certs/` on `<LANCACHE_HOST>` (`scp` or `rsync` after each renewal, root-only,
   `chmod 600 privkey.pem`). `docker compose exec file-host nginx -s reload` picks up a renewed certificate.
3. The private key lives on the LanCache host now: treat that host like the Traefik host.

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

## Upload size

`FILES_MAX_UPLOAD_MB` (default 200) and `FILES_PER_USER_QUOTA_MB` (default 500) stay the app's caps. To allow
bigger files (an ISO), raise all of these together, or the upload dies at the first layer that is smaller:

- `FILES_MAX_UPLOAD_MB` and `LIMIT_BODY_FILES_MB` (`config/lanomat.php`, multipart overhead on top);
- the Traefik `body-limit-files` buffering middleware (`docker/traefik/dynamic.yml`; a Pest test keeps it in sync
  with `config/lanomat.php`);
- PHP `upload_max_filesize` / `post_max_size` (`docker/frankenphp/php.ini`, 1G today, so 1 GB is the ceiling
  without changing the image);
- the time the browser and any proxy allow for the request: the upload to the app and the SFTP forward to the host
  both happen inside the request.

The SFTP forward runs with no execution-time limit and streams from the temporary upload file, so memory use
stays flat for large files.

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
