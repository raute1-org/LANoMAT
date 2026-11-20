---
title: Models & fuel
description: Venice AI is the default; any pi provider works. Precedence, local LLMs and the privacy boundary.
sidebar_icon: fuel
---

The agent is only as good as the model behind it. LANoMAT's default is **Venice AI**, because it is
OpenAI-compatible, has a model with a large context and tool calling, and can run in a TEE. But the
agent is pi underneath: any pi provider works, including a local LLM on the LAN.

<div class="live">LIVE 17:42 · venice · e2ee-deepseek-v4-1-flash-1017-abliterated · tools ok</div>

## Venice in one minute

| Property | Value |
|---|---|
| API | OpenAI-compatible |
| Base URL | `https://api.venice.ai/api/v1` |
| Auth | `Authorization: Bearer $VENICE_API_KEY` |
| Keys | <https://venice.ai/settings/api> |
| Models | `GET https://api.venice.ai/api/v1/models` (read at runtime, never hardcoded) |
| Docs | <https://docs.venice.ai/> |

```bash
export VENICE_API_KEY="…"
# PowerShell: $env:VENICE_API_KEY = "…"

lanomat-agent model setup
```

`model setup` writes `~/.pi/agent/models.json` with a `venice` provider and a default model:

```json
{
  "providers": {
    "venice": {
      "api": "openai-completions",
      "baseUrl": "https://api.venice.ai/api/v1",
      "apiKey": "$VENICE_API_KEY",
      "models": [
        {
          "id": "e2ee-deepseek-v4-1-flash-1017-abliterated",
          "name": "DeepSeek V4.1 Flash 1017 Abliterated (E2EE)"
        }
      ]
    }
  },
  "defaultModel": "venice/e2ee-deepseek-v4-1-flash-1017-abliterated"
}
```

The provider reads the key from the environment; `$VENICE_API_KEY` is a reference, not the secret.
The model list is fetched live on `model setup` and on `model list`; the JSON above only records the
selection.

## The default model

```text
venice/e2ee-deepseek-v4-1-flash-1017-abliterated
```

DeepSeek V4.1 Flash 1017 Abliterated, served by Venice with E2EE/TEE:

| Property | Value |
|---|---|
| Context | 1M tokens |
| Max output | 128K tokens |
| Price | $0.375 / 1M input, $1.50 / 1M output |
| API | OpenAI-compatible |
| Tool calling | Yes (probe it with `lanomat-agent doctor`) |

Use it directly:

```bash
lanomat-agent ask "install ut2004 for me on my pc"
pi --model venice/e2ee-deepseek-v4-1-flash-1017-abliterated
```

**TEE, not an E2EE envelope.** The model supports Venice's end-to-end encryption, but pi speaks the
plain OpenAI-compatible API and cannot send Venice's E2EE envelope. Requests are therefore
**TEE-only**: they run in a trusted execution environment at Venice, while the transport is ordinary
TLS. That is a strong privacy property, not the same as E2EE from the pi process to the model.

**Tool-calling probe.** Some models accept chat but fail tool calls. `lanomat-agent doctor` runs a
real tool-calling round trip (a `lanomat_me` call) and names the failure. If the default model fails
the probe, switch to the documented fallback:

```bash
lanomat-agent ask --model venice/zai-org-glm-5 "install ut2004 for me"
# or persist it
lanomat-agent model use venice/zai-org-glm-5
```

## Other models (alternatives, not defaults)

IDs change. Always read the live list (`GET /models`, `lanomat-agent model list`) instead of copying
a catalogue from a blog post.

| Model ID | Note |
|---|---|
| `zai-org/glm-5` → `venice/zai-org-glm-5` | Documented fallback if tool calls fail |
| `kimi-k2-6` → `venice/kimi-k2-6` | Long context alternative |
| `claude-opus-4-8` → `venice/claude-opus-4-8` | Strong reasoning, different price |
| `venice-uncensored-1-2` → `venice/venice-uncensored-1-2` | Uncensored, for content work |

The `venice/` prefix is pi's provider qualifier. The bare id is what Venice's API returns.

## Precedence

The agent resolves the model in this order, first match wins:

1. **CLI** — `--model provider/model` on `ask`, `pi --model …`, or `lanomat-agent model use`.
2. **`LANOMAT_AGENT_MODEL`** — a full provider/model, e.g. `venice/zai-org-glm-5`.
3. **`OPENAI_MODEL_*`** — a generic OpenAI-compatible endpoint (see below).
4. **Saved config** — the model chosen by `lanomat-agent model setup` / `model use`.
5. **Venice default** — `venice/e2ee-deepseek-v4-1-flash-1017-abliterated`.

`LANOMAT_AGENT_MODEL` is a single full id. The `OPENAI_MODEL_*` variables describe an endpoint, not
a model choice; if they are set, they beat the saved config but lose to an explicit CLI model.

## Any OpenAI-compatible endpoint

The agent honours the generic provider variables:

| Variable | Meaning |
|---|---|
| `OPENAI_MODEL_BASE_URL` | Base URL including `/v1` |
| `OPENAI_MODEL_API_KEY` | Bearer key (can be a placeholder for a local server) |
| `OPENAI_MODEL_NAME` | Model name the endpoint expects |
| `OPENAI_MODEL_PROVIDER` | Optional provider label used in the model id |
| `LANOMAT_AGENT_MODEL` | Full `provider/model`, overrides the four above |

### Local LLM (offline LAN)

```bash
export OPENAI_MODEL_BASE_URL="http://127.0.0.1:11434/v1"
export OPENAI_MODEL_API_KEY="ollama"          # local servers usually ignore it
export OPENAI_MODEL_NAME="qwen2.5-coder:32b"
export OPENAI_MODEL_PROVIDER="local"

lanomat-agent ask "plan the ut2004 install for windows"
# resolves to local/qwen2.5-coder:32b
```

Or pin it explicitly:

```bash
export LANOMAT_AGENT_MODEL="local/qwen2.5-coder:32b"
```

A local model makes the agent fully offline: skills are on disk, recipes and files come from the LAN,
and no prompt leaves the venue.

### Another hosted provider

```bash
export OPENAI_MODEL_BASE_URL="https://api.example.com/v1"
export OPENAI_MODEL_API_KEY="…"
export OPENAI_MODEL_NAME="example-large"
export OPENAI_MODEL_PROVIDER="example"
```

Any pi-native provider also works without these variables: `pi /login` for a subscription, then
`--model <provider>/<model>` on the CLI.

## Privacy

- **The key never leaves the pi process.** LANoMAT's server never sees `VENICE_API_KEY`, never stores
  it in its config, and never proxies a model call.
- The key is not in this repository, not in `models.json` (only the `$VENICE_API_KEY` reference), and
  not in the docs.
- Prompts and responses are handled per Venice's docs: <https://docs.venice.ai/>. Read the data
  policy before sending anything private.
- The LANoMAT token and the model key are separate credentials: revoking one does not touch the
  other.
- With a local model, no prompt or response leaves the machine at all.

<div class="callout-live">
  <strong>Model choice is not a security boundary.</strong> The agent's tools are scoped by the
  LANoMAT token, not by the model. A weaker model can still read the same recipes and files; a
  stronger one just plans better.
</div>
