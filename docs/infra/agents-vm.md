# Coding-agent VM on the venue Proxmox

A general-purpose VM for unattended coding agents (Claude Code, opencode, Codex, Gemini CLI,
Aider). It has **nothing to do with LANoMAT**: no LANoMAT clone, no app secrets. The venue
Proxmox host lends it its spare capacity outside LAN events. Concrete addresses are placeholders
(`<LAN-IP>`, `<OFFICE-IP>`, `<PVE>`); the real values live in the operator's notes, not here.

## Layout

| Item | Value |
|---|---|
| VM id / name | `140` / `agents` |
| OS | Debian 13 (cloud image, cloud-init) |
| CPU / RAM | 52 vCPU, 80 GB (balloon minimum 40 GB), `cpuunits 1024` |
| Disk | 500 GB on `local-lvm` (discard, SSD emulation) |
| NIC 0 | `vmbr0` (party LAN), static `<LAN-IP>/22`, no default route |
| NIC 1 | `vmbr1` (office LAN), DHCP `<OFFICE-IP>`, carries the default route |
| User | `claude` (sudo, docker group), SSH key only |
| Start | `onboot 1` |

Installed: Docker, Git, GitHub CLI, tmux/screen, Node 22, PHP 8.4 + Composer, Python 3 + uv,
Rust (rustup), Go, ripgrep, fd, jq, and the agent CLIs (`claude`, `opencode`, `codex`, `gemini`,
`aider`). Aider runs on Python 3.12 (`uv tool install --python 3.12 aider-chat`) because 3.13
removed `audioop`, which one of its dependencies imports.

## Network and firewall

The agents run without confirmation prompts, so the VM must not reach other machines:

- `/etc/nftables.conf` drops all **outbound** traffic to private ranges (`10/8`, `172.16/12`,
  `192.168/16`, `100.64/10`, `169.254/16`). Docker bridges (`docker0`, `br-*`) are exempt, and
  replies to inbound connections (SSH, herdr) pass through `ct state established`.
- DNS is pinned to public resolvers (`/etc/systemd/resolved.conf.d/agents.conf`) because the LAN
  resolver is now unreachable from the VM.
- Inbound is open: SSH from the office network works, which is how herdr connects.
- The firewall is loaded **before** the office NIC gets an address. Keep that order when
  rebuilding: otherwise the VM sits open in the office network for a moment.
- Test after every network change: public internet works, a private address does not
  (`curl -m4 http://<LAN-IP-of-another-host>/` returns `000`).

## Raise or lower resources

Run on the Proxmox host. The VM needs a reboot for CPU and maximum RAM changes.

```bash
qm shutdown 140
qm set 140 --cores 52 --memory 81920 --balloon 40960 --cpuunits 1024
qm start 140
```

- **During a LAN event:** give the VM low priority so game servers and the app win:
  `qm set 140 --cpuunits 50 --cores 24 --memory 40960 --balloon 0`.
- **After the event:** the game-server VM (130) is shut down (`qm set 130 --onboot 0`), freeing
  about 48 GB and 16 vCPU. Start it again before the next event.
- The LAN gateway/router VM and the app container must keep running; they are not touched.
- Grow the disk with `qm resize 140 scsi0 +200G`, then `sudo growpart /dev/sda 1 && sudo
  resize2fs /dev/sda1` (or the matching partition) in the VM.

## Accounts and secrets

- Claude accounts are managed with [claude-swap](https://github.com/realiti4/claude-swap)
  (`uv tool install claude-swap`, command `cswap`). Accounts are registered as setup tokens
  (`cswap add-token - --slot N`) and named with `cswap alias N <name>`. Pipe the token from the
  clipboard over SSH so it never lands in a file or shell history.
- `cswap run <name>` starts Claude Code with one account in the current terminal only;
  `cswap map <name> <dir>` ties a project directory to an account.
- `cswap export` writes plaintext: encrypt it (`cswap export - | gpg -c`) or do not export.
- API keys for other agents (Codex, Gemini, opencode) are set per agent; none are stored by this
  setup.

## herdr

The VM is registered as a herdr machine. `herdr machine add` asks to install herdr on the
remote from an interactive terminal; when that is not possible, copy the matching binary to
`~/.local/bin/herdr` on the VM first and run `herdr machine add claude@<OFFICE-IP> --label <name>`.

## Rebuild from scratch

1. Create the VM from the Debian 13 cloud image (`qm create`, `importdisk`, cloud-init user with
   an SSH key, static LAN address).
2. Copy `ops/vm-session/` to the VM and run `SKIP_TAILSCALE=1 sudo bash bootstrap.sh`. That is a
   mission bootstrap for LANoMAT; for a plain agent box delete the LANoMAT clone, the secrets
   template and `mission-state` afterwards.
3. Install the extra toolchains (uv, rustup, Go, npm global prefix in `~/.npm-global`, the agent
   CLIs) and the nftables ruleset above.
4. Add the office NIC (`qm set 140 --net1 virtio,bridge=vmbr1`), apply the ruleset, then
   configure the NIC with netplan (DHCP, `use-dns: false`, default route via this NIC, remove
   the default route from the LAN NIC).
