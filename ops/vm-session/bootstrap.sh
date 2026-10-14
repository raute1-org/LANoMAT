#!/usr/bin/env bash
# Prepare a fresh Debian 12/13 or Ubuntu 22.04/24.04 VM to run the unattended
# LANoMAT build session (see ops/vm-session/README.md).
#
# Run once, as root:   sudo bash bootstrap.sh
# Idempotent: safe to re-run. Every step can be skipped with SKIP_<STEP>=1
# (DOCKER, TAILSCALE, TOOLCHAIN, GH, CLAUDE, CLONE).
set -euo pipefail

CLAUDE_USER="${CLAUDE_USER:-claude}"
REPO_URL="${REPO_URL:-https://github.com/raute1-org/LANoMAT.git}"
REPO_DIR="${REPO_DIR:-/home/${CLAUDE_USER}/LANoMAT}"
SECRETS_FILE="/home/${CLAUDE_USER}/secrets/lanomat.env"

# shellcheck disable=SC1091
. "$(dirname "$(readlink -f "$0")")/lib.sh"

log() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
skip() { [ "${1:-0}" = "1" ]; }

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

# shellcheck disable=SC1091
. /etc/os-release
case "${ID}" in
    debian | ubuntu) ;;
    *) echo "Unsupported OS '${ID}' (need Debian or Ubuntu)." >&2; exit 1 ;;
esac
export DEBIAN_FRONTEND=noninteractive

log "Base packages"
apt-get update -y
apt-get install -y --no-install-recommends \
    ca-certificates curl gnupg git tmux jq unzip rsync openssh-client sudo \
    lsb-release software-properties-common apt-transport-https \
    dnsutils netcat-openbsd

log "User '${CLAUDE_USER}'"
if ! id -u "${CLAUDE_USER}" >/dev/null 2>&1; then
    adduser --disabled-password --gecos "" "${CLAUDE_USER}"
fi
# Passwordless sudo: the session administers the three servers and this VM.
# Claude Code refuses --dangerously-skip-permissions as root, hence a normal
# user that can sudo. The VM must hold nothing but what this project needs.
SUDOERS="/etc/sudoers.d/90-${CLAUDE_USER}"
echo "${CLAUDE_USER} ALL=(ALL) NOPASSWD:ALL" >"${SUDOERS}.tmp"
chmod 0440 "${SUDOERS}.tmp"
visudo -cf "${SUDOERS}.tmp" >/dev/null
mv "${SUDOERS}.tmp" "${SUDOERS}"

install -d -m 0700 -o "${CLAUDE_USER}" -g "${CLAUDE_USER}" "/home/${CLAUDE_USER}/secrets"
install -d -m 0700 -o "${CLAUDE_USER}" -g "${CLAUDE_USER}" "/home/${CLAUDE_USER}/.ssh"
install -d -m 0755 -o "${CLAUDE_USER}" -g "${CLAUDE_USER}" "/home/${CLAUDE_USER}/mission-state"

if ! skip "${SKIP_DOCKER:-0}"; then
    log "Docker Engine + compose plugin"
    if ! command -v docker >/dev/null 2>&1; then
        curl -fsSL https://get.docker.com | sh
    fi
    usermod -aG docker "${CLAUDE_USER}"
    systemctl enable --now docker 2>/dev/null || true
fi

if ! skip "${SKIP_TAILSCALE:-0}"; then
    log "Tailscale"
    if ! command -v tailscale >/dev/null 2>&1; then
        curl -fsSL https://tailscale.com/install.sh | sh
    fi
    # Join the tailnet only when an auth key is available (secrets file).
    if [ -f "${SECRETS_FILE}" ]; then
        TS_AUTHKEY="$(secret_value "${SECRETS_FILE}" TS_AUTHKEY)"
        TS_HOSTNAME="$(secret_value "${SECRETS_FILE}" TS_HOSTNAME)"
        if [ -n "${TS_AUTHKEY}" ] && ! tailscale status >/dev/null 2>&1; then
            tailscale up --authkey="${TS_AUTHKEY}" --hostname="${TS_HOSTNAME:-lanomat-control}" --ssh=false
        fi
    else
        echo "No ${SECRETS_FILE} yet: skipping 'tailscale up' (re-run after filling the secrets)."
    fi
fi

if ! skip "${SKIP_TOOLCHAIN:-0}"; then
    log "Toolchain: PHP 8.4, Composer, Node 22 (to run composer check / npm gates when fixing bugs)"
    if ! command -v php >/dev/null 2>&1 || ! php -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);'; then
        # Only add a third-party repo when the distribution does not ship PHP 8.4 itself.
        if ! apt-cache policy php8.4-cli 2>/dev/null | grep -q 'Candidate: [0-9]'; then
            if [ "${ID}" = "ubuntu" ]; then
                add-apt-repository -y ppa:ondrej/php
            else
                install -d -m 0755 /etc/apt/keyrings
                curl -fsSL https://packages.sury.org/php/apt.gpg -o /etc/apt/keyrings/sury-php.gpg
                echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main" \
                    >/etc/apt/sources.list.d/sury-php.list
            fi
        fi
        apt-get update -y
        apt-get install -y --no-install-recommends \
            php8.4-cli php8.4-common php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip \
            php8.4-intl php8.4-pgsql php8.4-redis php8.4-gd php8.4-bcmath
    fi
    if ! command -v composer >/dev/null 2>&1; then
        curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
    fi
    if ! command -v node >/dev/null 2>&1 || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 22 ]; then
        curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
        apt-get install -y nodejs
    fi
fi

if ! skip "${SKIP_GH:-0}"; then
    log "GitHub CLI"
    if ! command -v gh >/dev/null 2>&1; then
        install -d -m 0755 /etc/apt/keyrings
        curl -fsSL https://cli.github.com/packages/githubcli-archive-keyring.gpg -o /etc/apt/keyrings/githubcli.gpg
        chmod go+r /etc/apt/keyrings/githubcli.gpg
        echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/githubcli.gpg] https://cli.github.com/packages stable main" \
            >/etc/apt/sources.list.d/github-cli.list
        apt-get update -y
        apt-get install -y gh
    fi
fi

if ! skip "${SKIP_CLAUDE:-0}"; then
    log "Claude Code (native installer, as ${CLAUDE_USER})"
    if ! sudo -u "${CLAUDE_USER}" -H bash -lc 'command -v claude' >/dev/null 2>&1 \
        && ! [ -x "/home/${CLAUDE_USER}/.local/bin/claude" ]; then
        sudo -u "${CLAUDE_USER}" -H bash -c 'curl -fsSL https://claude.ai/install.sh | bash'
    fi
    # Plugins required by the mission (the supervisor re-checks and installs them if this fails).
    for plugin in superpowers frontend-design; do
        sudo -u "${CLAUDE_USER}" -H bash -lc "claude plugin install ${plugin}@claude-plugins-official" \
            || echo "NOTE: plugin ${plugin} not installed yet (needs Claude login/network); run-mission.sh retries."
    done
    PROFILE="/home/${CLAUDE_USER}/.profile"
    grep -q '.local/bin' "${PROFILE}" 2>/dev/null \
        || echo 'export PATH="$HOME/.local/bin:$PATH"' >>"${PROFILE}"
    chown "${CLAUDE_USER}:${CLAUDE_USER}" "${PROFILE}"
fi

if ! skip "${SKIP_CLONE:-0}"; then
    log "Repository"
    if [ ! -d "${REPO_DIR}/.git" ]; then
        sudo -u "${CLAUDE_USER}" -H git clone "${REPO_URL}" "${REPO_DIR}"
    else
        sudo -u "${CLAUDE_USER}" -H git -C "${REPO_DIR}" pull --ff-only || true
    fi
    if [ ! -f "${SECRETS_FILE}" ] && [ -f "${REPO_DIR}/ops/vm-session/secrets.env.example" ]; then
        install -m 0600 -o "${CLAUDE_USER}" -g "${CLAUDE_USER}" \
            "${REPO_DIR}/ops/vm-session/secrets.env.example" "${SECRETS_FILE}"
        echo "Created ${SECRETS_FILE} from the example: fill it in."
    fi
fi

cat <<EOF

Bootstrap finished. Next steps (see ops/vm-session/README.md):
  1. Fill in ${SECRETS_FILE}
  2. Copy the SSH key for the other servers to /home/${CLAUDE_USER}/.ssh/ (chmod 600)
  3. sudo bash ${REPO_DIR}/ops/vm-session/bootstrap.sh   # re-run: joins Tailscale with the key from step 1
  4. su - ${CLAUDE_USER}; tmux new -s mission; bash ~/LANoMAT/ops/vm-session/run-mission.sh
EOF
