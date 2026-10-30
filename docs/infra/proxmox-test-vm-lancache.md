# Test VM on Proxmox VE: Debian 13, Docker, LanCache, network isolation

A runbook for a single Debian 13 VM that runs a LanCache (`lancachenet`) behind
a hardened base system, with two firewall layers that behave correctly next to
Docker, and an egress policy that stops the VM from initiating connections into
local networks. Every command below was run for real; addresses are
placeholders. The final cache host at an event will usually be a physical
server, so this doubles as the blueprint for that.

The last section maps every step to existing LANoMAT modules and states what a
provisioning feature would still need.

## Placeholders

| Placeholder                | Meaning                                                                                |
| -------------------------- | -------------------------------------------------------------------------------------- |
| `<PVE_HOST>`               | Proxmox VE host (SSH as an administrator)                                              |
| `<VMID>`                   | Proxmox VM ID of the new VM                                                            |
| `<VM_NAME>`                | VM name, e.g. `lancache-test`                                                          |
| `<VM_IP>`                  | static address of the VM (also `LANCACHE_IP` / `DNS_BIND_IP`)                          |
| `<VM_PREFIX>`              | prefix length of the VM network, e.g. `24`                                             |
| `<LAN_CIDR>`               | subnet of the VM and the event clients                                                 |
| `<GATEWAY_IP>`             | router: default gateway, upstream DNS, resolver for `<SEARCH_DOMAIN>`                  |
| `<SEARCH_DOMAIN>`          | DNS search domain handed out by cloud-init                                             |
| `<ADMIN_WORKSTATION_CIDR>` | subnet the administrator's workstation appears from, as seen by the VM (routed or NAT) |
| `<WG_CIDR>`                | optional VPN subnet (WireGuard) that may reach the VM                                  |
| `<KEYFILE>`                | file on the Proxmox host holding the administrators' **public** SSH keys               |
| `<WAN_IF>`                 | name of the VM's NIC (`eth0` here; `ens*` on other setups)                             |
| `<CACHE_ROOT>`             | cache data root, e.g. `/srv/lancache`                                                  |
| `<TZ>`                     | IANA timezone, e.g. `Europe/Berlin`                                                    |
| `<OTHER_HOST_IP>`          | any other machine in the LAN, used for negative tests                                  |
| `<CLIENT_IP>`              | address of a LAN client as logged by the cache                                         |

Replace the placeholders with real values on your own machine only; never
commit them back.

## Prerequisites

- Proxmox VE host with about 16 GB RAM free, storage `local-lvm`, bridge `vmbr0`.
- A Debian 13 "genericcloud" image from cloud.debian.org.
- `<KEYFILE>` with the public keys of the administrators (only those, never
  every key a workstation or agent happens to hold).
- A workstation that is routed to `<LAN_CIDR>`. If it is routed or NATed, the
  VM sees it from a different subnet (`<ADMIN_WORKSTATION_CIDR>`); that subnet
  must be in every allow list below or you lock yourself out (gotcha 5).

## Inputs

Everything a run needs. These are the fields a provisioning feature would ask
for, and the single source of truth from which both firewall layers are
rendered.

| Input                                                      | Used by                  | Example / default                                     |
| ---------------------------------------------------------- | ------------------------ | ----------------------------------------------------- |
| Hypervisor endpoint + credentials                          | Step 0 (`qm`)            | `<PVE_HOST>`, API token                               |
| `<VMID>`, `<VM_NAME>`                                      | Step 0                   | free ID                                               |
| CPU cores, RAM, disk size                                  | Step 0                   | 4 cores, 8 GiB, 200 GiB                               |
| `<VM_IP>`/`<VM_PREFIX>`, `<GATEWAY_IP>`, `<SEARCH_DOMAIN>` | Steps 0, 3, 5            | static                                                |
| SSH public keys                                            | Step 0                   | admin keys plus the app's key                         |
| `<WAN_IF>`                                                 | Steps 2 (DOCKER-USER), 5 | `eth0`                                                |
| `<TZ>`                                                     | Steps 1, 3               | `Europe/Berlin`                                       |
| Allowed source subnets                                     | Steps 1c (ufw 22), 2, 5  | `<LAN_CIDR>`, `<ADMIN_WORKSTATION_CIDR>`, `<WG_CIDR>` |
| Isolation exemptions                                       | Step 5                   | `<GATEWAY_IP>`, `<WG_CIDR>`                           |
| `<CACHE_ROOT>`                                             | Step 3                   | `/srv/lancache`                                       |
| `CACHE_DISK_SIZE`                                          | Step 3                   | disk size minus headroom, `g`/`t` suffix              |
| `MIN_FREE_DISK`                                            | Step 3                   | `15g`                                                 |
| `CACHE_INDEX_SIZE`                                         | Step 3                   | 250m per TB of cache                                  |
| `UPSTREAM_DNS` (list)                                      | Step 3                   | `<GATEWAY_IP>`                                        |
| Image tags / digests                                       | Step 3                   | pinned digests                                        |

## Step 0: provision the VM on Proxmox

Download and verify the image on `<PVE_HOST>`:

```bash
mkdir -p /root/vm-build && cd /root/vm-build
curl -fLO https://cloud.debian.org/images/cloud/trixie/latest/debian-13-genericcloud-amd64.qcow2
curl -fLO https://cloud.debian.org/images/cloud/trixie/latest/SHA512SUMS
sha512sum -c --ignore-missing SHA512SUMS   # must print: debian-13-genericcloud-amd64.qcow2: OK
```

Create the VM:

```bash
qm create <VMID> --name <VM_NAME> --memory 8192 --balloon 0 --cores 4 --cpu host --ostype l26 \
  --machine q35 --bios ovmf --net0 virtio,bridge=vmbr0 --scsihw virtio-scsi-single --agent enabled=1 \
  --serial0 socket --vga serial0 --onboot 1 --efidisk0 local-lvm:1,efitype=4m,pre-enrolled-keys=0
qm importdisk <VMID> /root/vm-build/debian-13-genericcloud-amd64.qcow2 local-lvm
qm set <VMID> --scsi0 local-lvm:vm-<VMID>-disk-1,discard=on,iothread=1,ssd=1 --boot order=scsi0
qm resize <VMID> scsi0 200G
qm set <VMID> --ide2 local-lvm:cloudinit --ciuser debian --sshkeys <KEYFILE> \
  --ipconfig0 ip=<VM_IP>/<VM_PREFIX>,gw=<GATEWAY_IP> --nameserver <GATEWAY_IP> --searchdomain <SEARCH_DOMAIN>
qm start <VMID>
```

Pitfalls:

- The genericcloud image has **no qemu-guest-agent**; it is installed in step 1.
- `pre-enrolled-keys=0` leaves Secure Boot keys unenrolled, which a plain Debian
  cloud image needs under OVMF.
- `balloon 0` is deliberate: LanCache wants the page cache, no ballooning.
- If the IP was used before, `known_hosts` will mismatch. Verify the new host
  key out of band (for example via a jump host through `<PVE_HOST>` versus a
  direct `ssh-keyscan`, comparing the fingerprints) before `ssh-keygen -R`.
- Inject only the intended public keys.

Rollback: `qm stop <VMID>; qm destroy <VMID> --purge`. Cleanup afterwards:
`rm -rf /root/vm-build` (about 330 MB cached image); re-download for a rebuild.

## Step 1: base system

Run on the VM as `debian` with sudo:

```bash
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -qq && sudo -E apt-get -y -qq upgrade
sudo -E apt-get -y -qq install qemu-guest-agent unattended-upgrades ufw curl ca-certificates jq htop chrony dnsutils
sudo systemctl start qemu-guest-agent   # needed once; after a reboot udev starts it (virtio-port)
sudo timedatectl set-timezone <TZ>
```

Verify:

- On `<PVE_HOST>`: `qm agent <VMID> ping` returns rc 0 (before the start it says
  "QEMU guest agent is not running").
- `chronyc tracking` shows a sane stratum; `timedatectl` shows `<TZ>`.
- Installing chrony disables `systemd-timesyncd`, so only one NTP client runs.
- `unattended-upgrades` is enabled through `/etc/apt/apt.conf.d/20auto-upgrades`
  (`Update-Package-Lists 1`, `Unattended-Upgrade 1`): Debian security updates only.

### 1b: sshd hardening

```bash
sudo tee /etc/ssh/sshd_config.d/10-hardening.conf <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
EOF
sudo sshd -t && sudo systemctl reload ssh
sudo sshd -T | grep -Ei '^(passwordauthentication|permitrootlogin|kbdinteractive)'
```

Verify with a **second, fresh** `ssh debian@<VM_IP>` before closing the first
session; a login as root must fail with `Permission denied (publickey)`. The
image already had password auth off; the drop-in makes it explicit and turns
root login (default `prohibit-password`) off. Rollback: delete the drop-in and
reload ssh.

### 1c: ufw

```bash
sudo ufw default deny incoming && sudo ufw default allow outgoing
for n in <LAN_CIDR> <ADMIN_WORKSTATION_CIDR> <WG_CIDR>; do
  sudo ufw allow from "$n" to any port 22 proto tcp comment "ssh from $n"
done
sudo ufw --force enable
```

SSH is allowed from the VM LAN, the administrator's workstation subnet as the VM
sees it (read it from `$SSH_CLIENT`), and the optional VPN subnet. Per-service
ports are **not** added to ufw, because Docker-published ports bypass it (step 2).
Rollback if locked out: the Proxmox serial console (`qm terminal <VMID>`, serial0
is configured), then `ufw disable`.

## Step 2: Docker Engine

Official apt repository (it exists for Debian 13 and is current):

```bash
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
sudo tee /etc/apt/sources.list.d/docker.sources <<EOF
Types: deb
URIs: https://download.docker.com/linux/debian
Suites: $(. /etc/os-release && echo "$VERSION_CODENAME")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
sudo apt-get update
sudo mkdir -p /etc/docker
sudo tee /etc/docker/daemon.json <<'EOF'
{"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"3"}}
EOF
sudo apt-get -y install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo docker run --rm hello-world
sudo docker info --format '{{.LoggingDriver}}'   # json-file
```

Result: Docker 29.x, Compose v5.x (`docker compose`), iptables 1.8.x with the
nf_tables backend. Write `daemon.json` **before** the first start, otherwise a
restart is needed. Do not add the login user to the `docker` group (it is
root-equivalent); use sudo. The Docker docs say firewall rules created with
`nft` directly are unsupported next to Docker; here the nft rules live in their
**own table** and only filter egress (step 5), which was verified across a reboot.

### Docker versus ufw (key gotcha)

Docker publishes ports through the `nat` table and the `FORWARD` chain, so
`ufw allow/deny` input rules do **not** apply: DNS and HTTP were reachable from
another host although ufw had no rule for 53 or 80. Handle it in two layers:

1. Publish ports only on the LAN address in compose (`<VM_IP>:80:80`), never `0.0.0.0`.
2. Restrict sources in the `DOCKER-USER` chain through `/etc/ufw/after.rules`,
   which survives `ufw reload`, Docker restarts and reboots:

```bash
sudo cp /etc/ufw/after.rules /root/after.rules.orig
sudo tee -a /etc/ufw/after.rules <<'EOF'

# --- LANoMAT: restrict Docker-published ports to LAN sources ---
*filter
:DOCKER-USER - [0:0]
-A DOCKER-USER -i <WAN_IF> -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN
-A DOCKER-USER -i <WAN_IF> -s <LAN_CIDR> -j RETURN
-A DOCKER-USER -i <WAN_IF> -s <ADMIN_WORKSTATION_CIDR> -j RETURN
-A DOCKER-USER -i <WAN_IF> -s <WG_CIDR> -j RETURN
-A DOCKER-USER -i <WAN_IF> -j DROP
-A DOCKER-USER -j RETURN
COMMIT
# --- end LANoMAT ---
EOF
sudo ufw reload; sudo iptables -S DOCKER-USER
```

Verify: from another LAN host `curl http://<VM_IP>/lancache-heartbeat` returns 204.
Negative test: delete the `<LAN_CIDR>` RETURN rule with `iptables -D`; the curl
now times out (rc 28); `sudo ufw reload` restores it.

Notes:

- The allowed-source list exists twice (ufw port 22 and DOCKER-USER). Automation
  must render both from one list.
- Every Docker-published service is covered by the DOCKER-USER source rule. For a
  source subset per port, add `-p tcp --dport` rules; in DOCKER-USER the dport is
  the **container** port after DNAT, so match the host port with
  `-m conntrack --ctorigdstport`.
- Take care that the appended block is not added twice (see Idempotency notes).

## Step 3: LanCache (lancachenet)

Checked against the upstream `lancachenet/docker-compose` repository (README,
`.env`, `docker-compose.yml`). Images: `lancachenet/monolithic` (nginx cache;
HTTP on 80, HTTPS as SNI passthrough on 443, the old sniproxy is no longer
needed) and `lancachenet/lancache-dns` (bind9, port 53). Environment variables:
`USE_GENERIC_CACHE`, `LANCACHE_IP`, `DNS_BIND_IP`, `UPSTREAM_DNS`, `CACHE_ROOT`,
`CACHE_DISK_SIZE` (needs a `g`/`t` suffix), `MIN_FREE_DISK`, `CACHE_INDEX_SIZE`
(250m per TB advised), `CACHE_MAX_AGE` (`d` suffix), `TZ`. Volumes:
`${CACHE_ROOT}/cache:/data/cache` and `${CACHE_ROOT}/logs:/data/logs`.

Pre-check the upstream resolver: `dig @<GATEWAY_IP> example.com +short` must
answer. Port 53: the systemd-resolved stub listens only on `127.0.0.53` and
`127.0.0.54`, so binding lancache-dns to `<VM_IP>:53` does not conflict and the
stub is left alone (`ss -tulnp | grep :53`).

The heredocs are quoted, so substitute the placeholders in the text yourself
before running (or render the files from your inputs).

```bash
sudo mkdir -p /opt/lancache <CACHE_ROOT>/cache <CACHE_ROOT>/logs
cd /opt/lancache
sudo tee .env <<'EOF'
USE_GENERIC_CACHE=true
LANCACHE_IP=<VM_IP>
DNS_BIND_IP=<VM_IP>
UPSTREAM_DNS=<GATEWAY_IP>
CACHE_ROOT=<CACHE_ROOT>
CACHE_DISK_SIZE=150g
MIN_FREE_DISK=15g
CACHE_INDEX_SIZE=500m
CACHE_MAX_AGE=3650d
TZ=<TZ>
EOF
sudo chmod 640 .env
sudo tee docker-compose.yml <<'EOF'
services:
  dns:
    image: lancachenet/lancache-dns:latest
    env_file: .env
    restart: unless-stopped
    ports:
      - ${DNS_BIND_IP}:53:53/udp
      - ${DNS_BIND_IP}:53:53/tcp
  monolithic:
    image: lancachenet/monolithic:latest
    env_file: .env
    restart: unless-stopped
    ports:
      - ${LANCACHE_IP}:80:80/tcp
      - ${LANCACHE_IP}:443:443/tcp
    volumes:
      - ${CACHE_ROOT}/cache:/data/cache
      - ${CACHE_ROOT}/logs:/data/logs
EOF
sudo docker compose config -q && sudo docker compose pull && sudo docker compose up -d
sudo docker compose ps
```

Differences from upstream: ports are bound to `LANCACHE_IP` (upstream publishes
80/443 on all addresses); `restart: unless-stopped` is set directly instead of
upstream's `enable_autostart.sh`; `.env` is mode 640 (no secrets in it, but it
keeps configuration private). Image sizes: monolithic about 560 MB, lancache-dns
about 450 MB. Upstream only publishes `latest`; a provisioning feature should
**record and pin digests** and update deliberately. Rollback: `docker compose down`
(cache data stays in `<CACHE_ROOT>`; `rm -rf <CACHE_ROOT>/cache/*` purges it).

### Verify DNS (from another host, not from the VM)

```bash
dig @<VM_IP> lancache.steamcontent.com +short        # steam.cache.lancache.net. / <VM_IP>
dig +tcp @<VM_IP> lancache.steamcontent.com +short   # same (TCP works)
dig @<VM_IP> example.com +short                      # real upstream addresses (passthrough)
dig @<VM_IP> dist.blizzard.com.edgesuite.net +short  # <VM_IP>
curl -s -i -H "Host: lancache.steamcontent.com" http://<VM_IP>/lancache-heartbeat   # 204 + X-LanCache-Processed-By
```

### Verify caching end to end (no game client)

Steam depot chunk URLs need a hash from an authenticated manifest, so a static
Microsoft WSUS object (service `wsus`, cached by lancache) is the proxy test:

```bash
U=download.windowsupdate.com/msdownload/update/v3/static/trustedr/en/authroot.stl   # 204675 bytes
for i in 1 2 3; do curl -s -o /dev/null --resolve download.windowsupdate.com:80:<VM_IP> http://$U; done
sudo tail -n 4 <CACHE_ROOT>/logs/access.log
```

Expected in `access.log` (the quoted field before the host is
`$upstream_cache_status`):

```text
[wsus] <CLIENT_IP> / - - - [...] "GET /msdownload/update/v3/static/trustedr/en/authroot.stl HTTP/1.1" 200 204675 ... "MISS" "download.windowsupdate.com" "-"
[wsus] <CLIENT_IP> / - - - [...] "GET ... authroot.stl HTTP/1.1" 200 204675 ... "HIT" "download.windowsupdate.com" "-"
```

`du -sh <CACHE_ROOT>/cache` grows. Logs live in
`<CACHE_ROOT>/logs/{access,error,stream-access,stream-error,upstream-error}.log`.

## Step 4: service ports

All Docker-published services are LAN-only through DOCKER-USER and bound to
`<VM_IP>` only: 22/tcp (ufw), 53/udp+tcp (lancache-dns), 80/tcp and 443/tcp
(lancache monolithic). Later services (for example Pelican) are added the same
way: bind to `<VM_IP>`, covered by the DOCKER-USER source rule.

## Step 5: network isolation

Requirement: the VM must not initiate connections into local networks; the LAN
may reach the VM; the internet works. It is enforced **inside the VM** only, with
no hypervisor change (see the optional host-side variant below). Apply it
**last**, with a lock-out safety net.

The private ranges below are the standard RFC 1918 / link-local / CGNAT blocks
(`PRIVATE4`); they are constants, not site data.

```bash
sudo cp /etc/nftables.conf /root/nftables.conf.orig
sudo mkdir -p /etc/nftables.d
sudo tee /etc/nftables.d/10-isolation.nft <<'EOF'
define WAN_IF = "<WAN_IF>"
define GATEWAY = <GATEWAY_IP>
define WG_NET = <WG_CIDR>
define PRIVATE4 = { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16, 100.64.0.0/10 }

table inet lanomat_isolation
flush table inet lanomat_isolation
table inet lanomat_isolation {
	chain output {
		type filter hook output priority filter; policy accept;
		ct state established,related accept
		oif lo accept
		oifname != $WAN_IF accept
		icmpv6 type { nd-router-solicit, nd-router-advert, nd-neighbor-solicit, nd-neighbor-advert } accept
		ip daddr $GATEWAY udp dport 53 accept
		ip daddr $GATEWAY tcp dport 53 accept
		ip daddr $GATEWAY icmp type { echo-request, echo-reply, destination-unreachable, time-exceeded } accept
		ip daddr $WG_NET accept
		ip daddr $PRIVATE4 counter reject with icmp type admin-prohibited
		ip6 daddr { fc00::/7, fe80::/10 } counter reject with icmpv6 type admin-prohibited
	}
	chain forward {
		type filter hook forward priority filter; policy accept;
		ct state established,related accept
		oifname != $WAN_IF accept
		ip daddr $GATEWAY udp dport 53 accept
		ip daddr $GATEWAY tcp dport 53 accept
		ip daddr $WG_NET accept
		ip daddr $PRIVATE4 counter reject with icmp type admin-prohibited
		ip6 daddr { fc00::/7, fe80::/10 } counter reject with icmpv6 type admin-prohibited
	}
}
EOF
sudo tee /etc/nftables.conf <<'EOF'
#!/usr/sbin/nft -f
# No "flush ruleset": docker and ufw (iptables-nft) own other tables.
# Each file flushes only its own table.
include "/etc/nftables.d/*.nft"
EOF
sudo nft -c -f /etc/nftables.conf && echo syntax-ok
```

Apply with the safety net armed; cancel it only after a **second, fresh** SSH
session works:

```bash
sudo systemd-run --unit=isolation-safety-net --on-active=6min /usr/sbin/nft delete table inet lanomat_isolation
sudo nft -f /etc/nftables.conf
ssh debian@<VM_IP> 'echo second-session-ok'          # from the controlling host, FRESH connection
sudo systemctl stop isolation-safety-net.timer       # cancel once verified
sudo systemctl enable nftables && sudo systemctl reload-or-restart nftables
```

Design notes:

- Drops are scoped with `oifname <WAN_IF>` (everything leaving the physical NIC),
  so traffic to the VM's own Docker bridges, loopback and container-to-container
  stays untouched.
- The forward chain only matches traffic **leaving** through `<WAN_IF>`, so
  LAN-to-container (published ports, inbound) is unaffected; replies pass
  `ct state established,related`.
- `reject` (admin-prohibited) instead of `drop` makes failures immediate.
- Containers keep DNS to the gateway (lancache-dns forwards to `<GATEWAY_IP>`).
  The gateway ICMP exemption also allows ping and path-MTU discovery.
- Do not use `nft flush ruleset` or the stock Debian `nftables.conf` (it contains
  `flush ruleset` and would wipe the Docker and ufw tables on
  `systemctl restart nftables`; `systemctl stop nftables` still flushes the whole
  ruleset).
- Targeted rollback: `sudo nft delete table inet lanomat_isolation`, disable the
  service, restore `/root/nftables.conf.orig`.

Verification (all executed after applying):

```text
VM:        curl -m5 -sS http://<OTHER_HOST_IP>     -> curl: (7) Failed to connect ... after 0 ms (fast fail)
VM:        ping -c2 <OTHER_HOST_IP>                -> 100% loss, admin prohibited
VM:        ssh <user>@<OTHER_HOST_IP>              -> No route to host
VM:        dig @<GATEWAY_IP> debian.org +short     -> answers
VM:        ping -c1 <GATEWAY_IP>                   -> reply (gateway ICMP allowed)
VM:        curl -sSI https://deb.debian.org        -> HTTP/2 200; apt-get update works
container: docker run --rm curlimages/curl -m5 http://<OTHER_HOST_IP>  -> fast fail
container: docker run --rm curlimages/curl -m8 -sSI https://debian.org -> works
LAN host:  ssh, dig @<VM_IP>, heartbeat 204, cached object 200 -> all fine
           a 12 s SSH session with output every 4 s survived (stateful replies)
Counters:  both reject rules show hits
```

Reboot test: `sudo systemctl reboot`; after about 45 s `nftables`, `docker` and
`ufw` are enabled, table `lanomat_isolation` exists, DOCKER-USER has all its
rules, both containers are up through the restart policy, the guest agent
answers `qm agent <VMID> ping`, other LAN hosts are still unreachable from the
VM, and the internet still works.

### Optional: hard host-side enforcement (not applied)

On the Proxmox host, an nft `table bridge` with a `forward` hook rule matching the
VM's tap interface (`tap<VMID>i0`) and `ip daddr` in the private ranges with
`ct state new drop` (exceptions for gateway DNS), or a dedicated VLAN/firewall
zone. Benefit: cannot be disabled from inside a compromised VM. Risks: rules
are not managed by Proxmox (lost on reboot unless scripted), ordering and
conflicts with pve-firewall (`br_netfilter`), the tap name changes with VMID/NIC
index, and a mistake can cut off other guests on the same bridge. Enabling
pve-firewall instead flips every guest whose NIC has `firewall=1` to default
DROP, so check that first. Deliberately not applied to avoid touching a shared
hypervisor.

## Findings and gotchas

1. **lancache-dns has an RPZ passthrough for the cache host's own IP.** Queries
   _from_ the cache VM return real upstream addresses, not `<VM_IP>`. This is by
   design (prevents nginx looping to itself) but looks like "DNS is broken" when
   you run `dig` on the VM. Test DNS from another host, and give the VM its own
   upstream resolver (`<GATEWAY_IP>`), not itself.
2. The current monolithic image does **not** add an `X-LanCache-Status` response
   header (only `X-LanCache-Processed-By`). MISS/HIT is the
   `$upstream_cache_status` column in `access.log`. Headers such as `Cache: HIT`
   on static objects come from the origin CDN and are misleading.
3. WSUS `authrootstl.cab`, `pinrulesstl.cab`, `disallowedcertstl.cab` are bypassed
   (never cached) by monolithic (`22_wsus_cabs.conf`); use `authroot.stl` for
   tests. Steam depot chunks need an authenticated manifest. Only clients that
   resolve through lancache-dns hit the cache (the RPZ maps Blizzard, Epic,
   Origin, Riot, Uplay, WSUS and more).
4. Docker bypasses ufw (step 2). Without DOCKER-USER rules the lancache ports
   answer to everyone.
5. The administrator's workstation may reach the VM from a routed or NATed subnet
   that is **not** `<LAN_CIDR>`. Include `<ADMIN_WORKSTATION_CIDR>` in ufw (22)
   and DOCKER-USER, or you lock yourself out. At an event the client LAN has a
   different subnet: parametrize.
6. Port 443 on monolithic is SNI stream passthrough (no HTTPS caching); entries
   go to `stream-access.log`.
7. Cache and logs live on the root disk in the test VM (single 200 GB disk; about
   2 GB used after setup, 600 MB in `/var/lib/docker`). With `CACHE_DISK_SIZE=150g`
   and `MIN_FREE_DISK=15g` about 30 GB stay free. A physical server should have a
   dedicated cache disk or partition (upstream recommendation) mounted at
   `<CACHE_ROOT>`.
8. Idle resource use: about 540 MB of 8 GB RAM used (monolithic about 65 MB, dns
   about 80 MB; the rest is kernel and page cache), CPU about 0%. The 500 MB
   nginx index is allocated lazily; the page cache takes the remaining RAM.
9. The isolation rejects private-range broadcasts, such as a DHCP renewal to a
   LAN DHCP server. The test VM has a static address through cloud-init, so this
   is fine; a DHCP-configured venue server needs an exception (udp 67/68).
10. `nft list ruleset` warns that `table ip filter` is managed by iptables-nft:
    expected, ignore.

## Open questions (defaults chosen)

- Allowed source subnets: the VM LAN, the workstation subnet and the VPN subnet.
  Add the event client subnet(s) if they differ.
- Pin image digests (recommended) versus `latest` (used here; digests recorded).
- `UPSTREAM_DNS` is the router. If the venue router is slow or blocks, use a
  public resolver; those are public addresses and already pass the isolation.
- HTTPS caching is not available in lancache.
- `CACHE_DISK_SIZE=150g` fits the 200 GB test disk only.
- Real game-client verification (a Steam download twice) is not done; the WSUS
  object stands in for it.
- The login user is not in the `docker` group; automation runs as root or via sudo.

## What LANoMAT automation would need

### Existing building blocks

| Module / class                                              | What it gives us                                                                                                                                      |
| ----------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| `App\Modules\Hosts\Models\RemoteHost`                       | registry row: host/IP, SSH port, user, role (`lancache`, ...), encrypted private key, pinned host fingerprint                                         |
| `Hosts\Actions\RegisterHost`                                | validates the key with phpseclib, authorizes through the host policy                                                                                  |
| `Hosts\Contracts\RemoteExecutor` (`run`, `upload`, `probe`) | the only SSH path; `SshRemoteExecutor` pins the host key **before** `login()` (`strict_host_key`, `host_fingerprint`); `FakeRemoteExecutor` for tests |
| `Hosts\Actions\ProbeHost`                                   | connectivity probe, returns the observed fingerprint                                                                                                  |
| `Lancache\Actions\ApplyLancacheSetup`                       | today: one `docker compose` bootstrap command (image, upstream DNS, volume from `services.lancache.*`), values `escapeshellarg`-quoted                |
| `Lancache\Actions\ProbeLancache`                            | health probe of the cache host                                                                                                                        |
| `GameServers\Contracts\PelicanClient`                       | `createServer`, `getServer`, `powerAction`, `deleteServer` for game servers on Pelican; not for VM provisioning                                       |

### Step to module mapping

| Step                                      | Becomes                                                                                                                                                                                    | New piece needed                                                                                                                                                                                                                                                                                             |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| 0 Create VM (`qm create`, cloud-init)     | `ProvisionVirtualMachine` Action dispatching a queued `ProvisionVmJob`                                                                                                                     | **new contract `HypervisorClient`** (Proxmox VE API: create or clone from template, set cloud-init, resize, start, agent ping, exists/get, destroy) with `ProxmoxHypervisorClient` (API token) and `FakeHypervisorClient`; an encrypted, non-fillable hypervisor credential store like the `RemoteHost` keys |
| 0 First contact and key trust             | `RegisterHost` + `ProbeHost`: register the new VM as `RemoteHost` (`role=lancache`), pin the fingerprint                                                                                   | obtain the host key **out of band** from the hypervisor (guest agent or serial console) instead of trust on first use, then store it as `host_fingerprint`                                                                                                                                                   |
| 1 Base packages, timezone                 | `RemoteExecutor::run` script `base.sh` (idempotent apt)                                                                                                                                    | a small `ProvisioningScript` value object (name, template, rendered inputs) so scripts are testable                                                                                                                                                                                                          |
| 1b sshd drop-in                           | `RemoteExecutor::upload` of the drop-in, then `sshd -t && reload`                                                                                                                          | none; the Action must open a second connection itself to verify before finishing                                                                                                                                                                                                                             |
| 1c, 2 ufw + DOCKER-USER                   | render one allowed-source list into ufw rules and the after.rules block; `upload` plus guarded replace of the marked block                                                                 | pure `FirewallPolicy` domain object rendering both layers from one list (unit-testable, like the bracket engine)                                                                                                                                                                                             |
| 2 Docker Engine                           | `run` script `docker.sh`                                                                                                                                                                   | none                                                                                                                                                                                                                                                                                                         |
| 3 LanCache stack                          | extend `ApplyLancacheSetup`: `upload` `.env` and `docker-compose.yml`, then `compose pull` and `up -d`; replaces the single bootstrap command                                              | typed `LancacheConfig` (IP, upstream DNS, cache root, sizes, pinned digests) replacing loose `services.lancache.*` values                                                                                                                                                                                    |
| 3 Verification (DNS, heartbeat, MISS/HIT) | extend `ProbeLancache`; remote `dig`/`curl` run from the **app server** (another host, see gotcha 1), MISS/HIT read from `access.log` through `run`                                        | a probe that separates results "from the cache host" from results "from a client"                                                                                                                                                                                                                            |
| 5 Egress isolation                        | `ApplyEgressIsolation` Action: `upload` the nft file, arm the timer through `run`, apply, open a second SSH session from the app (`ProbeHost`), cancel the timer; always the **last** step | the safety-net sequence as a reusable Job step: cancel the timer on success, never on failure                                                                                                                                                                                                                |
| Reboot persistence check                  | Job step: `systemctl reboot`, wait for `HypervisorClient::agentPing`, re-run the verification suite                                                                                        | `HypervisorClient::agentPing`                                                                                                                                                                                                                                                                                |
| Rollback                                  | per-step compensating command (remove drop-in, `nft delete table`, `compose down`); VM level `HypervisorClient::destroy`                                                                   | explicit `rollback()` on each step                                                                                                                                                                                                                                                                           |

The result is an ordered pipeline of idempotent steps (a `ProvisioningStep`
interface with `apply`, `verify`, `rollback`) run by a single queued job, with a
status row per step so the Filament host page can show progress and the existing
preflight probes can reuse the `verify` parts. Nothing here is implemented yet.

### Idempotency notes

- apt installs are idempotent. sshd settings and the nft file use drop-ins and a
  dedicated table with `flush table` plus full re-declaration, so re-applying is
  safe.
- The `after.rules` append is **not** idempotent: guard it with the `LANoMAT`
  marker (grep before appending), or manage the block between the markers and
  replace it.
- `docker compose up -d`, `ufw allow` and `ufw --force enable` are idempotent.
- Image digests: pin them in the rendered compose file so re-runs do not drift.
- The isolation step always runs last and always arms the rollback timer
  (`systemd-run --on-active=... nft delete table inet lanomat_isolation`). It
  cancels the timer only after a fresh SSH connection opened **by the
  automation** succeeded.
- Interface name and subnets are rendered into both `after.rules` and the nft
  file from one source of truth (the `<WAN_IF>` and allow-list inputs).
- Re-running step 0 against an existing `<VMID>` must refuse (or reconcile), never
  recreate: the `HypervisorClient` needs an exists/get call.

### Secrets handling

- This stack has **no secrets** in `.env`; SSH keys used for provisioning are
  public keys.
- LANoMAT-side secrets (the SSH private key per `RemoteHost`, the future
  hypervisor API token) stay encrypted at rest, non-fillable, and never appear in
  command strings or logs; commands are built only from typed config with
  per-value `escapeshellarg`, as `ApplyLancacheSetup` already does.
- Secrets generated on a host for later services (Pelican, WireGuard keys) go to
  root-only files (mode 0600/0640), are never echoed, and are passed through
  compose `env_file` or Docker secrets. They are never committed.
- The hypervisor credential should be a scoped API token (VM create, config,
  power, agent on one pool), not a root password.
- Provisioning scripts are templates in the repo; rendered output containing
  inputs is uploaded, never stored in the database in clear text.

### Verification suite to automate

- Guest agent ping (`HypervisorClient`).
- `ss -tulnp`: 53, 80 and 443 bound to `<VM_IP>` only.
- Remote `dig` returns `<VM_IP>` for cached services and real addresses for
  other names.
- Heartbeat returns 204.
- MISS then HIT on the WSUS `authroot.stl` object, read from `access.log`.
- Egress negative tests (a private address fails fast, from the host and from a
  container) and positive tests (DNS to the gateway, HTTPS to the internet).
- Reboot persistence.
