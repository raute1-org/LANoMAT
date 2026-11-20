# The venue LAN on a MikroTik CRS354

A runbook and a list of measured limits for a small venue network: one MikroTik CRS354 as core, a server on a 2x10G
LACP link, the LanCache as the DNS for every client, an access point, and a WireGuard tunnel to the cloud entry. It was
built and run for a live event. Every address, key and name is a placeholder (this repository is public).

Read it together with [app-at-the-venue.md](app-at-the-venue.md) (the app behind the cloud entry) and
[lan-tls-certificates.md](lan-tls-certificates.md).

## Placeholders

| Placeholder         | Meaning                                                                 |
| ------------------- | ----------------------------------------------------------------------- |
| `<LAN_CIDR>`        | the venue subnet, one flat /22 (about 1000 addresses)                   |
| `<GATEWAY_IP>`      | the switch's address in the LAN, handed out as the gateway              |
| `<UPLINK_PORT>`     | the switch port that goes to the router with internet                   |
| `<SERVER_PORTS>`    | the two SFP+ ports that form the LACP bond to the server                |
| `<UPSTREAM_NET>`    | the network of the router behind the uplink                             |
| `<LANCACHE_IP>`     | the LanCache host (DNS on port 53, cache on 80/443)                     |
| `<VENUE_SERVER_IP>` | the host that runs the app stack                                        |
| `<WG_APP_ADDR>`     | tunnel address of the cloud server; `<WG_SWITCH_ADDR>` the switch's own |

## Design

- **One flat network, no VLANs.** All switching (clients to LanCache, client to client) is done by the switch chip and
  costs no CPU. Only traffic that leaves the LAN is routed.
- **The CRS354 is gateway, DHCP and local DNS** (`<GATEWAY_IP>`). Its CPU is a single 650 MHz core: it routes the
  internet traffic of the whole party, so everything below is about not wasting CPU.
- **DHCP hands out the LanCache DNS first and the switch second** (`dns-server=<LANCACHE_IP>,<GATEWAY_IP>`). If the
  LanCache host is down, clients fall back to the switch resolver instead of losing DNS.
- **The LanCache DNS forwards to public resolvers directly,** not through the switch: the switch's own DNS forwarder
  took about a fifth of its CPU.
- **Server link:** two SFP+ ports in one `802.3ad` bond to the server (`transmit-hash-policy=layer-3-and-4`,
  `lacp-rate=1sec`), on the server `bond-xmit-hash-policy layer3+4` and `bond-lacp-rate fast`.
- **Access point in bridge mode,** management address in the static range, services limited to the LAN.

## Switch recipes (RouterOS 7)

Order for the bond: take the member ports out of the bridge first, then create the bond, then add the bond.

```routeros
/interface bridge port remove [find interface=<SERVER_PORT_1>]
/interface bridge port remove [find interface=<SERVER_PORT_2>]
/interface bonding add name=bond-server mode=802.3ad slaves=<SERVER_PORT_1>,<SERVER_PORT_2> \
    transmit-hash-policy=layer-3-and-4 lacp-rate=1sec
/interface bridge port add bridge=bridge interface=bond-server
```

Check that the bond's bridge port shows the `H` flag (hardware offload) and that on the server both slaves share one
aggregator id with the switch as partner (`/proc/net/bonding/bond0`).

Firewall in short (stateful, with fasttrack for established flows):

- `input`: accept established, drop invalid, accept ICMP, accept from the LAN interface list, drop the rest.
- `forward`: a drop rule for private ranges leaving `<UPLINK_PORT>` (company or home networks behind the uplink)
  **before** the fasttrack rule; fasttrack for established connections; drop DNS over TLS (TCP 853); drop everything
  from the tunnel interface except the cloud server's address to the LAN.
- NAT: masquerade on the switch **or** a static route to `<LAN_CIDR>` on the router behind the uplink (that router then
  masquerades). Either works; with no NAT on the switch it only forwards.

WireGuard on the switch (the venue initiates, so the switch needs no open port): create the interface with an MTU of
1380, an address `<WG_SWITCH_ADDR>`, a peer with the cloud server's public key and endpoint, `persistent-keepalive=25s`,
and a pre-shared key. On the cloud server add the peer with `AllowedIPs = <WG_SWITCH_ADDR>/32, <LAN_CIDR>` and **add the
route `<LAN_CIDR>` via the tunnel interface yourself** (`wg set` does not create routes, `wg-quick` does when it
restarts). Do not put the tunnel interface into the LAN list.

Local DNS for the app name: see [app-at-the-venue.md](app-at-the-venue.md). Do not rely on `*.lan` names resolving when
the LanCache DNS answers directly.

## Measured limits

| Setup                                               | Result                                                                             |
| --------------------------------------------------- | ---------------------------------------------------------------------------------- |
| NAT, conntrack and fasttrack on the CRS354 CPU      | about 460 Mbit/s of internet traffic at 100 % CPU, packet loss at the uplink queue |
| Routing without NAT, still on the CPU               | about 420 Mbit/s at 95 % CPU                                                       |
| Local traffic (clients to LanCache, 20 Gbit/s link) | switched in hardware, no CPU use                                                   |
| The switch's own DNS forwarder for 25 clients       | about 7 to 20 % CPU                                                                |

The uplink of the venue (here 1 Gbit/s) was saturated by 20 clients downloading at the same time, which already causes
LanCache upstream timeouts. Every `tx-queue-drops` number on the uplink port is that saturation.

### Layer 3 hardware offload (`l3-hw-offloading`) on the 98DX3257

The chip can route in hardware, including fast-tracked connections and NAT (docs: "L3 Hardware Offloading"). What we
learned on RouterOS 7.19.6:

- Hardware-routed flows **bypass the CPU firewall.** A forward rule that blocks private ranges no longer applies; use a
  switch ACL instead (`/interface ethernet switch rule`, drop = `new-dst-ports=""`) on the client ports.
- With the server behind an SFP+ port, **routing from or to that port did not work** (the server and everything behind
  it lost internet while a wired client had full speed). The documented fix is an ACL that redirects only the routed
  packets of the server port to the CPU: match `dst-mac-address=<bridge MAC>/FF:FF:FF:FF:FF:FF` with
  `redirect-to-cpu=yes` on the server ports, and `dst-address=<server range>` with `redirect-to-cpu=yes` on
  `<UPLINK_PORT>`. L2 traffic to the LanCache stays in hardware. This worked in a test with one wired client.
- **With the real client population, several wired clients could not even ping the internet** while the setup worked
  for the test client. Switching the offload off fixed them at once. We left it off for the event. If you want to try
  it: upgrade RouterOS first, test with every kind of client (speed, port type, the AP port), set a revert timer that is
  longer than the whole test, and have a person at a client who confirms.
- A per-port rate limit (`ingress-rate`) on the uplink did not limit anything.

The robust long-term design is to route on a stronger router and keep the switch as pure layer 2: a dedicated VLAN
between the switch and the router, router address as the gateway (see "Do not couple layer 2" below).

## Traps (each one cost time or an outage)

- **Do not bridge the party LAN into the company or home LAN** without a VLAN. A cable whose far end sat on another
  network put two DHCP servers into one broadcast domain. Before enabling any new port on the bridge, check
  `/interface bridge host print where on-interface=<port>` for foreign MAC addresses.
- **Layer-2 cut-over to a second router failed:** the router received the clients' ARP requests but its replies did not
  reach most clients (cause not verified; an intermediate managed switch is the suspect: port security or ARP
  inspection). Use a VLAN you control.
- **A DHCP separation ACL on the uplink port (`udp src-port 67`) also blocks the switch's own DHCP client** on that port.
- **A new management address needs a known SSH host key** before the cut-over (`StrictHostKeyChecking=accept-new`),
  otherwise a scripted revert silently does not run.
- **A stale dynamic ARP entry for the router's own new address** can break replies: delete it after moving an IP.
- **Revert timers:** use a scheduler with `interval=0` for every risky change, but make it longer than your tests and
  remove it explicitly. One test finished after the timer had already fired.
- **`print` shows secrets in clear text** on RouterOS (pre-shared keys). Rotate a key that appeared in a log.
- **IPv6 and AAAA:** a host without an IPv6 route resolves AAAA records first and `curl` or the Docker daemon stalls
  on them. Prefer IPv4 (`/etc/gai.conf`, `net.ipv6.conf.*.disable_ipv6`) on hosts without IPv6.
- **Access point factory defaults:** a MikroTik AP in factory configuration is a router (WAN firewall, own DHCP, open
  SSID). It cannot be managed from the venue LAN. Join its open WLAN, convert it to a bridge AP with a script that runs
  on the device (`:execute "/import file-name=..."`), and never connect its LAN side to the venue LAN before that.
  WPA2 needs at least 8 characters.
- **NetworkManager on the admin notebook** falls back to an old profile when the cable is replugged. A dock or USB
  adapter with a second default route also confuses tests (`curl --interface` follows the other route).
- **Heavy clients:** one client pulling 370 Mbit/s over HTTPS bypassed the cache and starved the uplink. Steam's
  "allow downloads over HTTPS" must be off for the cache to work. A hardware rate rule
  (`/interface ethernet switch rule add switch=switch1 ports=<UPLINK_PORT> dst-address=<client>/32 rate=100M`) limits a
  single client without CPU.

## Server hardware notes (EPYC board with a BMC)

- The BMC web API may crash on writes to the BIOS settings object (reads work). Use the in-band `ipmitool` for the BMC
  address and users, and `ipmitool chassis bootdev bios options=efiboot` to enter the setup (the legacy option is
  ignored).
- PCIe link widths are set per slot in the BIOS: a 2-port 10G NIC needs the x8 setting (x4 limits it to about
  13 Gbit/s), NVMe drives behind a SlimSAS breakout need `x4x4x4x4` on that pair.
- Before wiping "free" disks, run `mdadm --examine` and mount read-only: an old LanCache RAID0 held three terabytes of
  cache that was worth keeping. NVMe device names change between boots; use `/dev/disk/by-id`.
- An unprivileged setup for Docker inside LXC is fiddly; a privileged container with `nesting=1` and
  `lxc.apparmor.profile: unconfined` runs Docker. A bind mount of the LanCache store into the container gives the cache
  full disk speed.

## What LANoMAT automation would need

The `Hosts` module and `RemoteExecutor` already cover the LanCache bootstrap. A network feature would additionally need
a RouterOS client contract (with a fake for tests), a snapshot-and-revert wrapper (backup, timed revert, explicit
confirmation) and read-only checks for the traps above (foreign MACs on a port, DHCP servers per network, bond state).
