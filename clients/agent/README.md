# `@lanomat/agent` — LANoMAT desktop installer agent

A small command line agent for LAN parties. It signs in to a LANoMAT server, wires the LANoMAT
MCP server and the recipe skills into [pi](https://pi.dev), and installs LAN games by chatting —
including the files and the VRM avatars that live on the LAN server's drives.

- **Text first.** The chat agent is pi with the Venice AI default model. The model layer is prepared
  for other Venice model types (decision, speech-to-text, text-to-speech), but only `chat` is used
  today.
- **LAN first.** Game files and avatars are downloaded from the LANoMAT server. Internet fallbacks
  are only used when you pass `--allow-external`, and they are announced loudly.
- **Small footprint.** Node 22+, TypeScript, ESM, commander, the pi SDK and the MCP SDK. No React,
  no Tauri. The voice client's Tauri app is a different program.

## Install

```bash
cd clients/agent
npm ci
npm run build
npm link                 # puts `lanomat-agent` on your PATH
```

Or run the container (CI/testing):

```bash
docker build -t lanomat-agent clients/agent
docker run --rm -it -v "$HOME/.config/lanomat:/root/.config/lanomat" lanomat-agent --help
```

The binary is `lanomat-agent` (package `@lanomat/agent`, entry `dist/cli.js`).

## Quick start

```bash
lanomat-agent login --url http://lanomat.local
lanomat-agent mcp install                 # ~/.pi/agent/mcp.json
lanomat-agent skills sync                 # ~/.agents/skills/lanomat-<slug>/SKILL.md
lanomat-agent model setup                 # Venice provider + default model
lanomat-agent doctor

lanomat-agent install ut2004 --os windows # the one-shot installer
lanomat-agent ask "install ut2004 for me on my pc"
```

### Login (OAuth-style device flow)

`login` starts `POST /api/mcp/device`, prints the absolute verification URL and the short code, and
opens the browser when `xdg-open` (Linux), `open` (macOS) or `start` (Windows) is available
(`--no-browser` disables it). It polls `POST /api/mcp/token` every `interval` seconds, handles
`authorization_pending`, `slow_down` (+5 s), `expired_token`, `access_denied` and `invalid_grant`, and
stores the result in:

```
~/.config/lanomat/agent.json      # directory 0700, file 0600
```

```json
{
  "url": "http://lanomat.local",
  "token": "…",
  "token_expires_at": "2026-11-20T12:00:00.000Z",
  "user": { "id": 7, "name": "Rene" },
  "model": "venice/e2ee-deepseek-v4-1-flash-1017-abliterated",
  "modelRoles": { "chat": "venice/…", "decision": null, "stt": null, "tts": null }
}
```

The token is never printed. `logout` deletes only this file; MCP configuration and synced skills stay.

Environment overrides: `LANOMAT_CONFIG_DIR`, `XDG_CONFIG_HOME`, `LANOMAT_PI_DIR`,
`LANOMAT_SKILLS_DIR`, `LANOMAT_AVATARS_DIR`.

### pi setup: MCP server

```bash
lanomat-agent mcp install [--server-name lanomat] [--exposure direct|codemode|deferred]
```

Writes or merges `~/.pi/agent/mcp.json` (other servers and keys are preserved):

```json
{
  "mcpServers": {
    "lanomat": {
      "url": "http://lanomat.local/mcp",
      "headers": { "Authorization": "Bearer ${LANOMAT_TOKEN}" },
      "description": "LANoMAT: events, teams, tournaments, files, game recipes and LAN install plans.",
      "exposure": "deferred"
    }
  }
}
```

The placeholder is literal: pi expands `${LANOMAT_TOKEN}`. The command also prints the matching
`export LANOMAT_TOKEN='…'` line (the only place the token appears on screen). `lanomat-agent ask`
sets the variable for its own pi session automatically.

### pi setup: recipe skills

```bash
lanomat-agent skills sync [--dest ~/.agents/skills] [--prune]
```

Fetches `GET /api/v1/recipes?status=published`, then every `GET /api/v1/recipes/{slug}/skill`, and
writes one pi sub-skill per recipe:

```
~/.agents/skills/lanomat-<slug>/SKILL.md
~/.agents/skills/lanomat-<slug>/recipe.json     # {slug, title, version, source_url}
~/.agents/skills/lanomat-<slug>/.lanomat-source # {url, fetched_at}
```

The sync is idempotent: unchanged content writes nothing. `--prune` removes only `lanomat-*`
directories that carry a `.lanomat-source` marker; your own skills are never touched.

## Fuel: Venice

The default provider is [Venice AI](https://docs.venice.ai/), which is OpenAI-compatible at
`https://api.venice.ai/api/v1` and privacy-first: private models have zero data retention,
`venice-uncensored-*` models are available, and model IDs change often.

The primary model is:

```
venice/e2ee-deepseek-v4-1-flash-1017-abliterated
```

DeepSeek V4.1 Flash 1017 Abliterated, E2EE/TEE, 1M context, 128K max output. **Verified against the
live Venice API on 2026-11-20: OpenAI function calling (tool calls) works.** Note the encryption
story: the model is E2EE/TEE capable, but pi's OpenAI-compatible client cannot send the Venice E2EE
envelope, so treat this setup as **TEE-only, not full E2EE**. If tool calling ever fails on this
model, the documented fallback is:

```bash
lanomat-agent ask --model venice/zai-org-glm-5 "install ut2004 for me"
```

### Model setup

```bash
lanomat-agent model setup                 # reads VENICE_API_KEY, ~/.config/lanomat/venice.env, or prompts
lanomat-agent model setup --model venice/zai-org-glm-5
```

`model setup`:

1. resolves the key from `--key`, `$VENICE_API_KEY`, `$OPENAI_MODEL_API_KEY`, or
   `~/.config/lanomat/venice.env` (mode 600, `export VENICE_API_KEY=…`); an interactive prompt is
   hidden; the key is **never** written to the LANoMAT agent config.
2. reads `GET /models` at runtime (no hardcoded model list). If the requested model is missing from
   the account, it falls back to the first available chat model and says so. Offline is fine: the
   requested/default model is kept.
3. merges the provider into `~/.pi/agent/models.json`, keeping other providers:

```json
{
  "$schema": "https://pi.dev/schemas/models.schema.json",
  "providers": {
    "venice": {
      "baseUrl": "https://api.venice.ai/api/v1",
      "api": "openai-completions",
      "apiKey": "$VENICE_API_KEY",
      "models": [
        { "id": "e2ee-deepseek-v4-1-flash-1017-abliterated" },
        { "id": "zai-org-glm-5" }
      ]
    }
  }
}
```

4. merges `defaultProvider` + `defaultModel` into `~/.pi/agent/settings.json` (the keys documented at
   <https://pi.dev/docs/latest/settings>). The explicit fallback is always
   `pi --model venice/<id>`. Older/ambiguous pi versions: use that flag.
5. prints the `export VENICE_API_KEY=…` line and the PowerShell equivalent, plus
   `pi --model venice/<id>` and `lanomat-agent ask …`.

`doctor` performs a tiny **tool-call probe** (64 output tokens, because reasoning models spend the first tokens on thinking) against the configured model and reports whether
`tool_calls` came back — without printing the key.

### Bring your own OpenAI-compatible endpoint

No LANoMAT account or Venice key is needed for the provider setup. Any OpenAI-compatible chat
completions endpoint works:

| Variable | Meaning |
|---|---|
| `OPENAI_MODEL_BASE_URL` | Base URL, e.g. `https://api.venice.ai/api/v1` or `http://gpu.lan/v1` |
| `OPENAI_MODEL_API_KEY` | Key for pi only. Never sent to LANoMAT. |
| `OPENAI_MODEL_NAME` | Model ID, e.g. `e2ee-deepseek-v4-1-flash-1017-abliterated` |
| `OPENAI_MODEL_PROVIDER` | Optional id; defaults to `venice` for `api.venice.ai`, otherwise `lanomat-openai` |
| `LANOMAT_AGENT_MODEL` | Full `provider/model`, highest environment priority |

Model precedence everywhere:

1. CLI flags (`--model`, `--provider`)
2. `LANOMAT_AGENT_MODEL`
3. `OPENAI_MODEL_*`
4. saved `~/.config/lanomat/agent.json` (`modelRoles.chat`, then legacy `model`)
5. built-in Venice default `venice/e2ee-deepseek-v4-1-flash-1017-abliterated`

```bash
export OPENAI_MODEL_BASE_URL=http://gpu.lan/v1
export OPENAI_MODEL_API_KEY=local
export OPENAI_MODEL_NAME=my-coder
lanomat-agent model setup
lanomat-agent ask "install ut2004"
```

The standard `OPENAI_API_KEY` is **not** required. `OPENAI_MODEL_API_KEY` belongs to pi; LANoMAT
never receives it.

### Model roles (text is the only implemented path)

```bash
lanomat-agent model roles                                  # show
lanomat-agent model roles --set chat=venice/zai-org-glm-5  # edit
lanomat-agent model roles --set decision=venice/jev
lanomat-agent model roles --set stt=venice/whisper-large-v3
```

`~/.config/lanomat/agent.json` stores `modelRoles` with defaults:

| Role | Default | Runtime |
|---|---|---|
| `chat` | resolved Venice chat model | used by `ask` and `install --chat` |
| `decision` | `null` | reserved |
| `stt` | `null` | reserved |
| `tts` | `null` | reserved |

`doctor` prints all four roles and where each value came from. Decision/classifier models and
speech-to-text are planned; no runtime code calls them yet.

### Next: more Venice models

Text is the only implemented path. The roles above are the seam for what comes next, using the same
OpenAI-compatible endpoint where it applies:

- **Decision / classifier** models, e.g. [Jev](https://docs.venice.ai/models/jev), for routing and
  yes/no style decisions. Configure with `--set decision=venice/<id>`.
- **Speech-to-text**, e.g. [whisper-large-v3](https://docs.venice.ai/models/whisper-large-v3), for
  transcribed voice commands. Configure with `--set stt=venice/whisper-large-v3`.
- **Text-to-speech** later, same `tts` role.

No dependencies are added for these yet and nothing runs them at runtime; they are configuration
plus documentation so the architecture is ready.

## Install a game (UT2004 example)

```bash
lanomat-agent recipes
lanomat-agent plan ut2004 --os windows
lanomat-agent install ut2004 --os windows
```

`install`:

1. requests the plan (`POST /api/v1/recipes/{slug}/plan`, `{os, event_slug?, include_avatars:true}`);
2. prints it and lists **missing required files**; without `--allow-external` it exits **2** and tells
   you what to ask the Orga to upload;
3. shows an avatar checkbox list (space toggles, arrows/`j`/`k` move, `a` toggles all, enter confirms,
   `q` cancels). Non-TTY: use repeated `--avatar <id>` or `--avatar all`. `--yes` selects none unless
   `--avatar` is given;
4. downloads every LAN file first (resume-safe `.part`, HTTP Range, sha256 verification when the
   server sends one) and then the avatars in parallel (default `--jobs 4`), to the plan's
   `target_dir` and to `{{avatars}}`;
5. runs the plan's `commands` for the OS (`powershell`, `cmd`, `bash`) in the target directory,
   substitutes `{{avatars}}`, `{{target_dir}}`, `{{os}}`, `{{slug}}`, `{{event}}` and
   `{{recipe_dir}}`, asks before optional steps unless `--yes`, and runs the `verify` checks after
   each step;
6. `--dry-run` prints the plan and every command without downloading or executing.

`--allow-external` is required for files that are not on the LAN. Such files are marked `EXTERNAL`
in the plan and in the installer output. Only LAN files are downloaded by default.

## Avatars from the LAN

```bash
lanomat-agent avatars                       # checkbox list, downloads to the default directory
lanomat-agent avatars --recipe ut2004
lanomat-agent avatars --all --jobs 8
lanomat-agent avatars --avatar marcus --avatar kane
```

The catalog comes from `GET /api/v1/avatars?recipe=<slug>&q=<text>`; the VRM files come from the
LANoMAT server's `download_url` (or `GET /api/v1/avatars/{id}/download`), never from the internet.
Avatars without a LAN copy are shown disabled as `(not on the LAN yet)`.

Local download directory:

| OS | Path |
|---|---|
| Linux | `~/.local/share/lanomat/avatars/` (`$XDG_DATA_HOME/lanomat/avatars` when set) |
| Windows | `%LOCALAPPDATA%\LANoMAT\avatars` |

Override with `--dest <dir>` or `LANOMAT_AVATARS_DIR`. The directory keeps a `manifest.json` with
the source server URL, `fetched_at`, and the downloaded avatar records. Downloads support resume via
HTTP Range + `.part`, sha256 verification, and never overwrite a verified existing file. The
progress display is compact: bytes, percentage, speed, per-file state.

`install` places avatars in `plan.avatars_dir` when the server sends it, else the default directory,
and passes that path to step commands as `{{avatars}}`. This is the UT2004+VRM path.

## Commands

| Command | What |
|---|---|
| `login [--url]` | device flow, browser open, 0600 config |
| `logout` | delete the token file |
| `mcp install [--server-name] [--exposure]` | merge `~/.pi/agent/mcp.json` |
| `skills sync [--dest] [--prune]` | one pi sub-skill per published recipe |
| `recipes [--json]` | published recipe list |
| `plan <slug> [--os] [--event] [--json]` | readable plan |
| `install <slug> [--os] [--yes] [--avatar …] [--allow-external] [--dry-run] [--jobs] [--chat]` | installer |
| `avatars [--recipe] [--all] [--avatar …] [--dest] [--jobs]` | VRM catalog + downloads |
| `ask <prompt…> [--model] [--provider] [--print-command]` | pi session with LANoMAT MCP + skills |
| `model setup [--key] [--model] [--provider]` | Venice/OpenAI-compatible provider |
| `model roles [--set role=model]` | chat/decision/stt/tts config |
| `doctor [--json]` | config, server, MCP, pi, skills, smbclient, Venice key/models, tool-call probe |

## Troubleshooting

- **Not signed in / session expired**: `lanomat-agent login --url http://lanomat.local`.
- **Cannot reach the server**: check the URL (scheme included), the LAN, and `GET /api/v1/me` with
  `curl -H "Authorization: Bearer …"`.
- **Browser does not open**: copy the printed verification URL; `--no-browser` is supported.
- **MCP tools missing in pi**: `mcp install` must have run, `LANOMAT_TOKEN` must be exported for a
  plain `pi` session, and `doctor` shows the MCP handshake. pi loads `~/.pi/agent/mcp.json`.
- **No skills**: `lanomat-agent skills sync`; check `~/.agents/skills/` and the MCP `skills` path.
- **pi not found**: `npm install -g @earendil-works/pi-coding-agent`; the agent bundles the SDK, but
  the CLI fallback needs the binary.
- **Venice key not found**: `echo $VENICE_API_KEY`, or create
  `~/.config/lanomat/venice.env` with `export VENICE_API_KEY=…` (mode 600). Never commit it.
- **Tool calls fail on the default model**: use `--model venice/zai-org-glm-5`; `doctor` runs the
  1-token probe.
- **Offline model setup**: provider and settings are still written; export the key later.
- **UT2004 needs files that are not on the LAN**: ask the Orga to upload them (installer exit 2), or
  `--allow-external`.
- **SMB/LAN share copies fail on Linux**: install `smbclient` (`pacman -S samba` on Arch);
  `doctor` checks it.
- **Avatar is shown disabled**: the server has no LAN copy yet (`download_url` missing).

## Contract assumptions and defensive behaviour

The server is built in parallel. This client follows the documented contract exactly and is
defensive where the contract leaves room:

- `verify` entries are accepted as `{type: "file_exists", path}`, `{type: "dir_exists", path}` and
  `{type: "command", command, args?, shell?}`; `optional` is honoured.
- `plan.avatars_dir` is used when present (an additive field); otherwise the default avatar
  directory is used. `plan.avatars` is used when present, else `GET …/avatars` is called.
- A missing `source` is inferred: `status:"external"` or a `fallback_url` means external, everything
  else is LAN. External downloads require `--allow-external`.
- `sha256` is verified when present; when absent, an existing file with the expected size (or any
  non-empty file when no size is known) is treated as complete. Verified files are never re-written.
- Signed download URLs are used as returned. The bearer header is only attached to URLs on the
  LANoMAT origin, never to a signed LAN/CDN URL.
- `GET /api/v1/recipes/{slug}/skill` must return Markdown; a missing `name`/`description`
  frontmatter produces a warning, not a failure.
- pi settings use `defaultProvider`/`defaultModel` (documented); `pi --model provider/model` is the
  always-working fallback.
- `venice.env` is parsed as `export KEY=value` / `KEY=value` with optional quotes. It is read, never
  written, and the key is never logged.
- No endpoints beyond the contract are invented.

## Development

```bash
npm run build     # tsc
npm run lint      # eslint
npm test          # vitest, 92 tests, no network
```

Tests cover config permissions, device-flow states, MCP merge, skills idempotence/prune, plan
rendering, non-TTY avatar selection, resumable downloads, sha mismatches, parallel job caps,
never-overwrite, manifests, command dry-run, model precedence, models.json/settings merge, key
resolution, role merge, Venice offline fallback, tool-call probe and ask argv.

The test suite is offline. A live smoke test is optional:

```bash
source ~/.config/lanomat/venice.env
lanomat-agent doctor
lanomat-agent ask "say hello in one sentence"
```

## License

MIT — see [`LICENSE`](LICENSE).
