# WireGuard tunnel between the app server and a venue host

A runbook for a point-to-point WireGuard tunnel between the LANoMAT app server
(a public server with a firewall and a WireGuard interface) and a venue host
(for example the test VM from
[proxmox-test-vm-lancache.md](proxmox-test-vm-lancache.md)) that sits behind
NAT. LANoMAT uses the tunnel to reach the Pelican panel on the venue host
without exposing the panel to the internet
([pelican-panel-wings.md](pelican-panel-wings.md)). Every command was run for
real; addresses and keys are placeholders.

## Placeholders

| Placeholder                | Meaning                                                                             |
| -------------------------- | ----------------------------------------------------------------------------------- |
| `<APP_SERVER>`             | the LANoMAT app server (administrator access over SSH)                              |
| `<APP_PUBLIC_IP>`          | public address (or DNS name) of `<APP_SERVER>`, the tunnel endpoint                 |
| `<ADMIN_USER>`             | administrative SSH user on `<APP_SERVER>` (root-equivalent via sudo)                |
| `<APP_COMPOSE_NETWORK>`    | Docker network of the LANoMAT compose project on the app server                     |
| `<PANEL_PORT>`             | TCP port of the Pelican panel on the venue host (`8080` in the examples)            |
| `<VM_IP>`                  | LAN address of the venue host (see the base runbook)                                |
| `<VM_SSH_USER>`            | administrative SSH user on the venue host (sudo)                                    |
| `<WG_CIDR>`                | tunnel subnet, a private /24 that is used nowhere else, e.g. an unused RFC 1918 /24 |
| `<WG_APP_ADDR>`            | tunnel address of the app server (first host of `<WG_CIDR>`)                        |
| `<WG_VM_ADDR>`             | tunnel address of the venue host                                                    |
| `<WG_PORT>`                | UDP listen port on the app server                                                   |
| `<MTU>`                    | tunnel MTU; this runbook uses `1380`                                                |
| `<APP_PRIVATE_KEY>`        | private key of the app server, never leaves it, never committed                     |
| `<VM_PRIVATE_KEY>`         | private key of the venue host, never leaves it, never committed                     |
| `<APP_PUBLIC_KEY>`         | public key of the app server (safe to exchange)                                     |
| `<VM_PUBLIC_KEY>`          | public key of the venue host (safe to exchange)                                     |
| `<PSK>`                    | optional preshared key, generated on the app server only                            |
| `<ADMIN_WORKSTATION_CIDR>` | as in the base runbook; keep it in every SSH allow list                             |
| `<WAN_IF>`                 | name of the venue host NIC, as in the base runbook                                  |

Replace the placeholders on your own machines only; never commit them back.

## Design

- The venue host sits behind NAT, the app server has a public address. The
  **venue host initiates** the connection and `PersistentKeepalive` keeps the NAT
  mapping open; the app server only listens on UDP `<WG_PORT>`.
- Only `<WG_CIDR>` is routed through the tunnel. No `DNS=`, no default route
  (`0.0.0.0/0`), no `PostUp` hooks. The venue host never sends internet traffic
  through the app server.
- Traffic flows app server to venue host (LANoMAT calls the panel); replies are
  ESTABLISHED. Therefore the **only** firewall change on the app server is
  allowing UDP `<WG_PORT>` inbound.

## Prerequisites

- The base VM from the first runbook, including its ufw, DOCKER-USER and egress
  isolation layers.
- The kernel module on both ends: `modprobe -n -v wireguard` shows
  `wireguard.ko` (any current distribution kernel has it).
- Root access on `<APP_SERVER>`. Before touching it, save the firewall state:
  `ufw status verbose > /root/ufw-before-wg.txt`.
- The app server's existing compose project, containers and `.env` are **not**
  touched by this runbook.

## Step 1: keys (one command block per host)

On `<APP_SERVER>` as root:

```bash
export DEBIAN_FRONTEND=noninteractive
apt-get install -y wireguard-tools
install -d -m 700 /etc/wireguard && cd /etc/wireguard && umask 077
wg genkey > app.key; wg pubkey < app.key > app.pub
wg genpsk > psk.key            # preshared key, generated on the app server only
```

On the venue host (one `sudo sh -c` so the directory change and the `umask`
apply to the redirect):

```bash
sudo apt-get install -y wireguard-tools
sudo sh -c 'install -d -m 700 /etc/wireguard && cd /etc/wireguard && umask 077 && wg genkey > vm.key && wg pubkey < vm.key > vm.pub'
```

Gotcha: `/etc/wireguard` is `0700 root`. Running `cd /etc/wireguard` as the
unprivileged user fails, and a following `sudo sh -c "umask 077; wg genkey > vm.key"`
then silently writes the key into the **current** directory. Always put the
whole sequence inside one `sudo sh -c 'cd ... && ...'`.

Private keys never leave their host; only the two public keys are exchanged. The
preshared key travels once over the existing SSH channel, without touching the
administrator's workstation or shell history:

```bash
ssh <ADMIN_USER>@<APP_SERVER> 'cat /etc/wireguard/psk.key' \
  | ssh <VM_SSH_USER>@<VM_IP> 'sudo sh -c "umask 077; cat > /etc/wireguard/psk.key"'
```

## Step 2: configuration files (mode 0600)

Render the key and PSK values into the files with a small script that reads the
key files, instead of typing or pasting them.

App server `/etc/wireguard/wg0.conf`:

```ini
[Interface]
Address = <WG_APP_ADDR>/24
ListenPort = <WG_PORT>
MTU = <MTU>
PrivateKey = <APP_PRIVATE_KEY>

[Peer]
# venue host
PublicKey = <VM_PUBLIC_KEY>
PresharedKey = <PSK>
AllowedIPs = <WG_VM_ADDR>/32
```

Venue host `/etc/wireguard/wg0.conf`:

```ini
[Interface]
Address = <WG_VM_ADDR>/24
MTU = <MTU>
PrivateKey = <VM_PRIVATE_KEY>

[Peer]
# LANoMAT app server
PublicKey = <APP_PUBLIC_KEY>
PresharedKey = <PSK>
Endpoint = <APP_PUBLIC_IP>:<WG_PORT>
AllowedIPs = <WG_CIDR>
PersistentKeepalive = 25
```

Notes:

- `AllowedIPs = <WG_VM_ADDR>/32` on the app server means it does not route any
  other host through the tunnel. A second venue needs its own `/32` peer.
- `wg-quick` with `Address = x/24` installs the `/24` route on each end.
- The app server already forwards IPv4 when Docker is installed; nothing else
  needs enabling.

## Step 3: activate

```bash
# app server
ufw allow <WG_PORT>/udp comment wireguard
systemctl enable --now wg-quick@wg0

# venue host
sudo systemctl enable --now wg-quick@wg0
```

The only difference in the app server's `ufw status` afterwards is the
`<WG_PORT>/udp` rule (plus its IPv6 twin). Nothing else is opened for `wg0`
because LANoMAT initiates all traffic. Add
`ufw allow in on wg0 from <WG_VM_ADDR> to any port <p>` later only if the venue
host must call something on the app server.

## Step 4: verify

```bash
sudo wg show wg0                 # latest handshake: seconds ago, on both sides
ping -c3 <WG_APP_ADDR>           # from the venue host
ping -c3 <WG_VM_ADDR>            # from the app server
```

Observed: handshake a few seconds after start, round trip about 23 ms in both
directions, no loss (home uplink to a data-centre server; your numbers differ).

MTU probe: `ping -M do -s <MTU-28> <peer>` succeeds and `-s <MTU-27>` fails with
"Message too long". `1380` is a conservative value (the default of 1420 also
worked on the test path) that leaves headroom for PPPoE, IPv6 endpoints and
other venue uplinks. Note that the probe size is `MTU - 28` (IP + ICMP header),
so a payload of `MTU - 8` fails by design.

The egress isolation of the base runbook matches `oifname <WAN_IF>` only and
accepts `<WG_CIDR>`, so neither the tunnel nor the UDP handshake to the public
endpoint is affected (verified with the isolation active).

Containers on the app server reach the venue host through the tunnel as well:

```bash
docker run --rm --network <APP_COMPOSE_NETWORK> alpine ping -c3 <WG_VM_ADDR>
```

Docker masquerades container traffic that leaves via `wg0`, so the venue host
sees the **app server's tunnel address** (`<WG_APP_ADDR>`) as source, not the
container address. This matters for Pelican API key `allowed_ips`
([pelican-panel-wings.md](pelican-panel-wings.md)). Verify with
`tcpdump -ni wg0 'tcp port <PANEL_PORT> and tcp[tcpflags]&tcp-syn!=0'`.

## Step 5: venue-host firewall for the tunnel

The base runbook's `DOCKER-USER` rules only matched `-i <WAN_IF>`, so traffic
arriving over `wg0` into Docker-published ports would be unfiltered. Insert
these lines **after** the `-i <WAN_IF> -j DROP` line in the marked LANoMAT block
of `/etc/ufw/after.rules`:

```text
-A DOCKER-USER -i wg0 -m conntrack --ctstate RELATED,ESTABLISHED -j RETURN
-A DOCKER-USER -i wg0 -s <WG_CIDR> -j RETURN
-A DOCKER-USER -i wg0 -j DROP
```

Then `sudo ufw reload; sudo iptables -S DOCKER-USER`. Back up `after.rules`
first. Host processes (for example Wings) are governed by ufw `INPUT`, not
`DOCKER-USER`; allow them separately, e.g.
`ufw allow from <WG_CIDR> to any port <N> proto tcp`.

## Step 6: start order at boot

If a container publishes a port on `<WG_VM_ADDR>` (the Pelican panel does),
Docker must start after `wg0` exists. Binding a not-yet-existing address makes
the container fail to start. Found by the reboot test:

```bash
sudo mkdir -p /etc/systemd/system/docker.service.d
sudo tee /etc/systemd/system/docker.service.d/10-wg0.conf <<'EOT'
[Unit]
After=wg-quick@wg0.service
Wants=wg-quick@wg0.service
EOT
sudo systemctl daemon-reload
```

`wg-quick@` is ordered after `network-online.target`, which is needed to resolve
a DNS endpoint at boot. `Wants=` rather than `Requires=` means Docker still
starts if WireGuard fails.

Reboot result: `wg0` came up by itself, handshake within a minute, isolation
table present, firewall rules present, all containers back.

## Rollback

```bash
# app server
systemctl disable --now wg-quick@wg0; ufw delete allow <WG_PORT>/udp; rm /etc/wireguard/*
# venue host: the same, plus
sudo rm /etc/systemd/system/docker.service.d/10-wg0.conf && sudo systemctl daemon-reload
# and delete the three "-i wg0" DOCKER-USER lines, then: sudo ufw reload
```

## Gotchas

1. Key written to the wrong directory: see step 1.
2. Probe size is `MTU - 28`.
3. A host process listening on `<WG_VM_ADDR>` is **not** reachable from the app
   server until ufw allows it (`INPUT` applies to host processes; Docker-published
   ports bypass it). Verified with a throwaway HTTP server.
4. Source seen on the venue host is the app server's tunnel address (masquerade).
5. The venue host's UDP source port is random. The 25 s keepalive recovers NAT
   rebinding, and the app server learns the new endpoint from the authenticated
   handshake.
6. Reverse-proxy or panel redirects to a LAN address (`APP_URL`) do not work from
   the app server; test with `curl -sI` and expect `302` or `200`.

## Open questions

- A second venue: one `/32` peer per venue and one `/24` per site, or one
  hub-and-spoke subnet with explicit per-peer firewall rules. Default: one peer
  per venue.
- Whether the venue host ever has to call the app server over the tunnel
  (match telemetry). Default: use the public HTTPS URL; nothing is opened on
  the app server.
- Key rotation: no schedule yet. Rotating means regenerating one side,
  exchanging the public key and `wg syncconf` (see below).

## What LANoMAT automation would need

| Step                             | Becomes                                                                                                                         | New piece needed                                                                                          |
| -------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------- |
| Keys per host                    | `RemoteExecutor::run` on each host with `umask 077`, one `sudo sh -c`                                                           | none; the controller reads only the `*.pub` files back                                                    |
| PSK distribution                 | `RemoteExecutor::run` on the app host, then `upload` to the venue host, never stored in the database in clear text              | a short-lived, memory-only secret hand-off                                                                |
| `wg0.conf` rendering             | pure `WireguardConfig` value object (addresses, MTU, endpoint, peers) rendered into both files and into the `DOCKER-USER` block | one source of truth shared with the `FirewallPolicy` from the base runbook                                |
| Activation and ordering          | `RemoteExecutor::run`: `systemctl enable --now`, drop-in for Docker                                                             | none                                                                                                      |
| Re-apply without dropping tunnel | `wg syncconf wg0 <(wg-quick strip wg0)`                                                                                         | none                                                                                                      |
| Verification                     | handshake age below two minutes on both ends, ping, MTU probe, panel `302/200` from an app container                            | a probe in the preflight registry (`HealthCheck`) so the existing Filament tile can show the tunnel state |
| Rollback                         | per-step compensating commands (above)                                                                                          | `rollback()` on each provisioning step                                                                    |

Inputs per venue host: app endpoint and port, tunnel subnet and per-venue `/32`,
MTU, PSK yes or no. Secrets (private keys, PSK) stay in root-only files
(mode 0600) on their hosts; LANoMAT never stores a WireGuard private key. The
app-server side touches a production machine, so the Action must demand an
explicit confirmation and always save the firewall state first.
