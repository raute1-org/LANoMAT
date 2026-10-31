# Provisioning: the test VM as a LANoMAT function

The optional module `provisioning` turns the manual recipe in
[`docs/infra/proxmox-test-vm-lancache.md`](infra/proxmox-test-vm-lancache.md)
into a function of the app: it creates a hardened Debian 13 VM on Proxmox VE,
installs Docker, sets up a LanCache and isolates the VM's egress. This page is
the operator's view: concept, safety model, registering a hypervisor and how the
automation maps to the manual runbook.

This repository is public. Every address, key and token below is a placeholder
(documentation ranges `203.0.113.0/24` and `198.51.100.0/24`, `example.test`).

Scope: backend and console only. There is no UI yet. The WireGuard tunnel, Pelican
and game server steps of the other runbooks are future plans, not part of this
module.

## Enabling it

`provisioning` is optional (`core = false`). It requires `hosts` and `lancache`
(`Module::requires()`), because the plan registers the VM as a remote host and
applies the LanCache stack through the Lancache module.

```bash
MODULES_PROFILE=full          # or:
MODULE_PROVISIONING=true      # plus MODULE_HOSTS=true and MODULE_LANCACHE=true
```

Behaviour is tuned in [`config/provisioning.php`](../config/provisioning.php)
(task and step timeouts, SSH wait, safety-net minutes). The queue worker must run
for queued runs, like for every other job.

## Concept

```text
provisioning:plan   --> dry run: prints what would happen. No connection, no write.
provisioning:run    --> StartProvisioningRun (policy, validation, rows) --> RunProvisioningPlanJob
                        --> RunProvisioningPlan: step 1 .. step 9, one row per step
```

| Piece                                                  | Role                                                                                                                       |
| ------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------- |
| `HypervisorClient` (contract)                          | The only way to talk to the hypervisor. `ProxmoxApiClient`, `FakeHypervisorClient` (tests, dev), `NullHypervisorClient`.   |
| `Hypervisor` (model)                                   | Credentials of one Proxmox API endpoint: URL, default node, token id, **encrypted** token secret, optional TLS pin.        |
| `ProvisioningStep` (contract)                          | `describe()`, `apply()`, `verify()`, `rollback()`. Idempotent: `apply()` checks the state first and skips when satisfied.  |
| `ProvisioningPlan` + `ProvisioningContext`             | Ordered steps plus the typed config, hypervisor, actor and a small state bag that is persisted per step.                   |
| `RunProvisioningPlan`                                  | The runner: dry run, per-step persistence, stop on failure, rollback, lock.                                                |
| `provisioning_runs`, `provisioning_run_steps` (tables) | One run per execution, one row per step (status, skipped flag, redacted log, redacted error, timestamps).                  |
| `FirewallPolicy` (pure)                                | Renders ufw, the DOCKER-USER block and the nftables isolation from one list of allowed sources. Unit-tested to the letter. |
| `HostKeyTrust` / `HostKeyScanner`                      | Establishes the VM's SSH host key out of band, see below.                                                                  |
| `LancacheConfig` (Lancache module)                     | Typed LanCache config rendering `.env` and `docker-compose.yml`; `ApplyLancacheStack` applies it idempotently.             |

Everything on the VM goes through the existing `RemoteExecutor` contract
(`SshRemoteExecutor` with host key pinning before login), never around it. Remote
work is a `ProvisioningScript`: a template with `{{PLACEHOLDER}}` markers where every
substituted value is `escapeshellarg`-quoted, on top of strict pattern validation of
the typed config. Files are rendered from typed objects, uploaded to a temp path and
installed with `sudo install`.

### The plan `test-vm-lancache`

| #   | Step               | Runbook    | What it does                                                                                             |
| --- | ------------------ | ---------- | -------------------------------------------------------------------------------------------------------- |
| 1   | `download_image`   | step 0     | `download-url` of the cloud image with a mandatory checksum, verified by the hypervisor.                 |
| 2   | `create_vm`        | step 0     | Creates the VM with cloud-init, generates its SSH key pair, registers the remote host.                   |
| 3   | `start_vm`         | step 0     | Starts the VM.                                                                                           |
| 4   | `wait_for_ssh`     | step 0     | Waits for sshd, confirms the host key on two paths, pins it, proves key login.                           |
| 5   | `base_setup`       | step 1, 1b | Packages, guest agent, chrony, unattended-upgrades, timezone, sshd hardening with a second-session test. |
| 6   | `install_docker`   | step 2     | Docker Engine from the official apt repository, `daemon.json` before the first start.                    |
| 7   | `ingress_firewall` | step 1c, 2 | ufw (SSH from the allowed sources) and the DOCKER-USER block, with a lock-out guard.                     |
| 8   | `lancache`         | step 3     | The compose stack through `ApplyLancacheStack`, ports bound to the VM address only.                      |
| 9   | `egress_isolation` | step 5     | nftables egress isolation with the safety-net timer. Always last.                                        |

Not automated yet (open items of the runbook): the reboot persistence check, the
real game client cache check, a physical cache host, and everything that is not in
the table (WireGuard, Pelican, game servers).

## Safety model

1. **Dry run first.** `provisioning:plan` renders every step: hypervisor calls, the
   exact remote scripts and the files that would be written. It makes no external
   call, opens no connection and writes nothing to the database. The runner's
   `describe()` and `apply()` use the same renderers, and a test asserts that every
   described command is executed on a real run.
2. **Explicit confirmation.** `provisioning:run` refuses without `--confirm`.
3. **Authorization.** Hypervisors and runs are orga/admin only (policies). The
   requesting user is stored on the run and re-authorized inside the queued job.
4. **One plan per target.** A cache lock and a partial unique index on active runs
   (`pending`, `running`) per target make a second concurrent run for the same VM
   impossible.
5. **Idempotent steps.** A re-run finds satisfied steps and skips them (shown as
   "already satisfied"). An existing VM is adopted only when it has the expected name
   and the `lanomat` tag; any other machine on that VM id is never touched.
6. **Stop on failure.** The run stops at the first failing step and later steps stay
   `pending`. By default the failed step is rolled back (it only undoes what this run
   created). Rolling back the steps applied earlier needs the explicit `--rollback`.
7. **Lock-out guards.** sshd hardening, ufw and the egress isolation each keep their
   change only after a **second, fresh SSH session** worked. The ufw step additionally
   refuses to run when the address LANoMAT connects from is not covered by
   `allowed_source_cidrs`.
8. **Safety-net timer.** The isolation step arms a `systemd-run` timer that deletes the
   nftables table again, applies the rules, opens a fresh SSH session and cancels the
   timer only when that session works. When it fails the timer is left armed on
   purpose; the rules disappear after `safety_net_minutes` (default 6).
9. **Time budgets.** Each step has a wall-clock budget (`provisioning.steps.timeout`)
   that long-running loops check between attempts. The budget is cooperative, not
   preemptive; the queue job timeout is the hard stop. Hypervisor tasks wait at most
   `provisioning.proxmox.task_timeout`.

### What runs on which machine

| Machine            | What happens there                                                                                                    |
| ------------------ | --------------------------------------------------------------------------------------------------------------------- |
| LANoMAT app server | The runner, all rendering, the direct SSH host key read, the pinned SSH sessions, the Proxmox API calls.              |
| Proxmox host (API) | Image download, VM create, disk import and resize, start, stop, delete, guest agent ping. Nothing is installed there. |
| Proxmox host (SSH) | Only `ssh-keyscan` of the new VM, as the independent view of its host key (optional jump host, see below).            |
| The new VM         | Packages, sshd drop-in, ufw, Docker, the LanCache stack, nftables. All through sudo as the cloud-init user.           |

### Secrets

- The Proxmox token secret is stored with the `encrypted` cast, is not fillable and is
  hidden from serialization. It is only sent in the `Authorization` header, never in a
  URL, command, log or exception message.
- The VM's SSH key pair is generated per run. The public half goes into cloud-init
  next to your admin keys. The private half goes **only** into the remote host record
  (encrypted cast). It is never part of the run context, a log, a command line or an
  upload.
- The run context (typed config plus state) is stored encrypted and holds no secrets.
- Step logs and errors pass a redactor (registered secret values, API token headers,
  private key blocks, `secret|token|password` assignments) and are truncated. Exceptions
  that are not domain errors are stored by class name only.
- The LanCache stack has no secrets. The `.env` is mode 0640 and root owned.

## Registering a hypervisor

1. Create a role and an API token on the Proxmox host (scoped, not the root password).
   The privileges must cover VM allocation and configuration, power, the guest agent,
   storage allocation (disks and templates), `SDN.Use` on the bridge and
   `Sys.AccessNetwork` on the node (needed for `download-url`). Check the exact list
   against the API viewer of your Proxmox version; it was **not** verified against a
   live token here.

    ```bash
    pveum role add LANoMATProvisioner -privs "<PRIVILEGE_LIST>"
    pveum user add lanomat@pve
    pveum aclmod / -user lanomat@pve -role LANoMATProvisioner
    pveum user token add lanomat@pve provisioning --privsep 1
    pveum aclmod / -token 'lanomat@pve!provisioning' -role LANoMATProvisioner
    ```

2. The cloud image storage must accept the content type **Import**
   (Datacenter, Storage, Edit, Content). The probe lists the storages that do. A
   storage without it is refused by the plan with a clear error.

3. Optional but recommended for the host key check: register the Proxmox host itself
   as a remote host (SSH), and pass its id as `--jump-host`. Without an independent
   path the plan cannot establish trust and stops at `wait_for_ssh`.

4. If the node uses a self-signed certificate, pass an SPKI pin. TLS verification stays
   on; the pin is enforced by curl on the same connection:

    ```bash
    openssl x509 -in /etc/pve/local/pve-ssl.pem -pubkey -noout \
      | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | openssl enc -base64
    # --tls-pin=sha256//<BASE64_FROM_ABOVE>
    ```

5. Register and probe (the secret comes from the environment or a prompt, never from
   an argument; `--as` is the id of an orga/admin user):

    ```bash
    PROVISIONING_TOKEN_SECRET='<TOKEN_SECRET>' php artisan provisioning:register-hypervisor \
      lab https://<PVE_HOST>:8006 'lanomat@pve!provisioning' --node=<NODE> \
      --tls-pin='sha256//<BASE64>' --jump-host=<REMOTE_HOST_ID> --as=<USER_ID>
    php artisan provisioning:probe-hypervisor lab --as=<USER_ID>
    ```

    The probe is read-only: version, nodes, storages, free RAM and disk, and the
    storages that accept cloud images.

## Running the plan

Config file (JSON, no secrets; public keys only). Required: `name`, `image`,
`admin_ssh_keys`, `ip`, `prefix`, `gateway`, `allowed_source_cidrs`. Everything else
has a default that mirrors the runbook.

```json
{
    "name": "lancache-test",
    "memory_mb": 8192,
    "cores": 4,
    "disk_gb": 200,
    "storage": "local-lvm",
    "image_storage": "local",
    "bridge": "vmbr0",
    "image": {
        "url": "https://cloud.example.test/debian-13-genericcloud-amd64.qcow2",
        "checksum": "<SHA512_FROM_THE_OFFICIAL_SHA512SUMS>",
        "algorithm": "sha512",
        "filename": "debian-13-genericcloud-amd64.qcow2"
    },
    "admin_ssh_keys": ["ssh-ed25519 <PUBLIC_KEY_BASE64> admin-key"],
    "ip": "203.0.113.10",
    "prefix": 24,
    "gateway": "203.0.113.1",
    "search_domain": "example.test",
    "timezone": "Europe/Berlin",
    "wan_if": "eth0",
    "allowed_source_cidrs": ["203.0.113.0/24", "198.51.100.0/24"],
    "egress_allowed_cidrs": ["198.51.100.0/24"],
    "negative_probe_host": "203.0.113.99"
}
```

- `checksum`: take it from the official checksum file of the image and compare it over
  a second channel; the hypervisor verifies the download against it. The stored file
  name carries the first 12 characters of the checksum, so an existing file of that
  name is by construction the verified one.
- `allowed_source_cidrs`: the VM LAN, **the subnet the app server appears from as seen
  by the VM** (routed or NATed) and an optional VPN subnet. Missing the app server's
  subnet locks you out, which is why the ufw step checks it first.
- `negative_probe_host`: a machine in the LAN that the isolated VM must not reach. When
  set, the verification proves the isolation from the VM and from a container.

```bash
php artisan provisioning:plan --config=lancache-test.json --hypervisor=lab        # dry run
php artisan provisioning:run  --config=lancache-test.json --hypervisor=lab \
  --as=<USER_ID> --confirm                                                         # queued
php artisan provisioning:run  ... --confirm --sync                                 # foreground
php artisan provisioning:run  ... --confirm --sync --rollback                      # undo earlier steps on failure
```

### Queue worker

Queued runs go to the queue `provisioning` and take minutes (image download, apt, Docker
pulls), so they do not share the default worker: a long run would block notifications
and Discord jobs. Start a dedicated worker; set the visibility timeout (`retry_after`
of the queue connection, 90 s by default) above the job timeout, or a second worker may
pick the running job up again and write a `failed_jobs` row even though the run
succeeds. With a single dedicated worker this cannot happen; `--sync` avoids the queue.

```bash
php artisan queue:work --queue=provisioning --tries=1 --timeout=0
```

A failed run is inspected in `provisioning_run_steps` (status, redacted log, error). A
new run is idempotent and continues where the state is not satisfied yet.

## Future steps (not implemented)

Named so that the next plans line up with the other runbooks. None of these exists in
code; each would be a `ProvisioningStep` with `apply`, `verify` and `rollback`.

| Step key                   | Source runbook                          | What it would do                                                          |
| -------------------------- | --------------------------------------- | ------------------------------------------------------------------------- |
| `reboot_persistence_check` | `infra/proxmox-test-vm-lancache.md`     | Reboot, wait for the guest agent, re-run the verification suite.          |
| `wireguard_tunnel`         | `infra/wireguard-tunnel.md`             | Keys on both ends, peer config, boot order, firewall rule for the tunnel. |
| `pelican_panel`            | `infra/pelican-panel-wings.md`          | Panel in Docker, bound to the VM address, admin and API key bootstrap.    |
| `pelican_wings`            | `infra/pelican-panel-wings.md`          | Wings as a systemd service, node registration, LanCache DNS for games.    |
| `game_server_eggs`         | `infra/custom-game-servers-gmod-ttt.md` | Import the shipped eggs, create the game server presets.                  |

## Mapping to the manual recipe

| Manual step (`docs/infra/proxmox-test-vm-lancache.md`) | Automation                                                                         |
| ------------------------------------------------------ | ---------------------------------------------------------------------------------- |
| Step 0: `curl` + `sha512sum -c`                        | `HypervisorClient::downloadImage` (mandatory checksum, verified on the hypervisor) |
| Step 0: `qm create`, `importdisk`, `set`, `resize`     | `HypervisorClient::createVm` (`import-from` disk, cloud-init drive, resize)        |
| Step 0: host key out of band                           | `CrossCheckHostKeyTrust` plus `PinHostKey` (pin before the first login)            |
| Step 1: packages, timezone                             | `BaseSetup`                                                                        |
| Step 1b: sshd drop-in, second session                  | `BaseSetup` (fresh `probe`, drop-in removed again on failure)                      |
| Step 1c, 2: ufw, DOCKER-USER                           | `ApplyIngressFirewall` rendered by `FirewallPolicy`                                |
| Step 2: Docker Engine                                  | `InstallDocker`                                                                    |
| Step 3: LanCache                                       | `ApplyLancache` through `Lancache\ApplyLancacheStack`                              |
| Step 5: nftables isolation, safety net                 | `ApplyEgressIsolation` (timer armed first, cancelled only after a fresh session)   |
| Rollback notes                                         | `ProvisioningStep::rollback()`                                                     |

## Known limits

- The Proxmox client was verified against the official API viewer and tested against
  sanitised real `pvesh` output (see below) but **not** against a live API token: no
  token was available. A first live run should start with `provisioning:probe-hypervisor`
  and a throwaway VM.
- `pvesh` prints the bare data, the REST API wraps it in `{"data": ...}`. The fixtures
  reconstruct that envelope; the REST envelope itself is taken from the API
  documentation.
- The per-step time budget is cooperative. A command that hangs inside a single SSH
  call is only stopped by the job timeout.
- TLS pinning uses curl's SPKI pin, which is not the colon-separated certificate
  fingerprint the Proxmox UI shows. The pin path is unit-tested for its options, not
  against a real handshake.
- The DOCKER-USER and nftables checks need `nftables` and `ufw` on the image; the
  Debian 13 genericcloud image plus the base step cover this.
- There is no UI, scheduler job or preflight probe for provisioning yet.
- Queued runs need their own worker (see above); the plan itself is the only one that exists.
