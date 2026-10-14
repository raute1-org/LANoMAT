# Unattended VM session

Runs the real-infra build (`docs/three-server-setup.md`) in a Claude Code session on a VM, so the
operator's laptop is not needed once it has started. Progress and questions reach the operator's
phone via ntfy/Discord; answers go back by writing one file over SSH.

## Working method (fixed)

Default model **sonnet**, advisor **opus** (`--model sonnet --advisor opus`), **superpowers
subagent-driven development** for all implementation with **haiku** implementer subagents, and the
**frontend-design** skill for any UI work. Both plugins are installed by `bootstrap.sh` /
`run-mission.sh`; override only via `MISSION_MODEL` / `MISSION_ADVISOR`.

## What runs where

- **Control VM** — any always-on Debian 12/13 or Ubuntu 22.04/24.04 machine with a public IP or
  Tailscale reachability to the three servers. The **public** server itself works well (the session
  then has root locally and reaches `games`/`cache` over Tailscale). A small separate VM works too.
- The session runs as the unprivileged user `claude` (Claude Code refuses
  `--dangerously-skip-permissions` as root) with passwordless sudo, inside `tmux`, driven by
  `run-mission.sh`.

> **Trust boundary.** The session runs with permission prompts off. Use a VM that holds nothing but
> this project, take snapshots of all three servers first, and give it only scoped tokens (a
> fine-grained GitHub token for this repo, DNS token limited to the one zone, a Discord bot you can
> rotate). Rotate every secret afterwards if the VM is ever shared.

## Files

| File | Purpose |
|---|---|
| `bootstrap.sh` | One-time root setup: user, Docker, Tailscale, PHP 8.4 + Composer + Node 22, `gh`, Claude Code, repo clone |
| `secrets.env.example` | Everything the session needs; copy to `~/secrets/lanomat.env` (mode 600) |
| `MISSION.md` | The unattended mission prompt: goal, definition of done, rules, stop conditions |
| `run-mission.sh` | Supervisor loop: resumes the session, backs off on errors, waits for operator answers |
| `notify.sh` | Phone notifications (ntfy topic and/or Discord webhook) |

## One-time start (about 15 minutes)

1. Create the VM and snapshot the three servers.
2. On the VM, as root (it creates the `claude` user and clones the repo to `/home/claude/LANoMAT`):
   ```bash
   apt-get update && apt-get install -y git curl
   git clone https://github.com/raute1-org/LANoMAT.git /tmp/lanomat-bootstrap
   bash /tmp/lanomat-bootstrap/ops/vm-session/bootstrap.sh
   ```
3. Fill `/home/claude/secrets/lanomat.env` (created from the example). Minimum for the first run:
   `CLAUDE_CODE_OAUTH_TOKEN` (run `claude setup-token` once on any device with a browser),
   `GH_TOKEN`, `GIT_EMAIL`, `NTFY_TOPIC` (or a Discord webhook), `TS_AUTHKEY`, the three hosts, the
   domain values and the Discord values. Copy the SSH key for the servers to `/home/claude/.ssh/`
   (`chmod 600`) and set `SSH_KEY_FILE`.
4. Re-run `sudo bash /home/claude/LANoMAT/ops/vm-session/bootstrap.sh` — this time Tailscale joins the
   tailnet with the key from the secrets file. Check that the three servers answer
   (`tailscale ping <host>`, `ssh -i … <user>@<host> true`).
5. Start the mission:
   ```bash
   su - claude
   tmux new -s mission
   bash ~/LANoMAT/ops/vm-session/run-mission.sh
   ```
   Detach with `Ctrl-b d`. Close the laptop.

## While it runs

- **Status:** `ssh claude@vm 'cat ~/mission-state/STATE.md'` (German, short). Open operator items: `~/mission-state/NEEDS_HUMAN.md`.
- **Live view:** `ssh claude@vm -t tmux attach -t mission` (iteration logs under `~/mission-state/log/`).
- **Answering a question:** when `WAITING_HUMAN` appears you get a notification. Write your answer
  with `ssh claude@vm 'echo "Antwort …" >> ~/mission-state/ANSWERS.md'`; the supervisor resumes
  within five minutes.
- **Stop:** `Ctrl-c` in tmux, or `touch ~/mission-state/DONE`. Nothing is lost: `STATE.md` is the memory.
- **Resume after a VM reboot:** repeat step 5; the session id and state files are kept.

## Done

`~/mission-state/DONE` exists and the final report is in `STATE.md`. Then: review the findings,
tick the remaining manual items in `docs/pre-lan-acceptance-checklist.md`, rotate the secrets you no
longer need, and take fresh snapshots.

## Known limits

- Usage limits: iterations that hit a limit back off (`ERROR_SLEEP`, default 15 min) and retry.
- Some steps are inherently human (Discord portal toggles, router DNS, captchas); the mission records
  them in `NEEDS_HUMAN.md` and keeps working on the rest.
- `claude setup-token` creates a token that can only make model requests (no Remote Control, no
  claude.ai connectors); that is all the session needs.
- Tuning knobs for `run-mission.sh`: `MAX_ITERATIONS`, `ITERATION_TIMEOUT`, `ERROR_SLEEP`, `HUMAN_POLL`, `STATE_DIR`, `REPO`.
