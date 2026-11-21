# AI assistant (orga chat)

The optional `Assistant` module adds an orga-only chat at `/orga/assistant`. An orga writes what they want
("Create a CS2 wingman tournament for Saturday 14:00, 16 teams", "Start a CS2 server from the competitive
preset for the test event", "Set the permanent CS2 server to de_mirage, 10 players, no password"); the
assistant reads the current state with read tools and proposes changes. **Every change waits on a preview card:
nothing happens before the orga clicks "Ausführen", and "Ablehnen" changes nothing.** Every proposal and its
outcome is logged in `assistant_actions`.

Design: [`docs/superpowers/specs/2026-11-21-ai-assistant-design.md`](../superpowers/specs/2026-11-21-ai-assistant-design.md),
plan: [`docs/superpowers/plans/2026-11-21-ai-assistant.md`](../superpowers/plans/2026-11-21-ai-assistant.md).

## Enable it

| Variable | Default | Meaning |
| --- | --- | --- |
| `MODULE_ASSISTANT` | off in `core`, on in `full` | Module toggle. No hard dependency; each tool is hidden while the module it wraps (Tournaments, GameServers) is off |
| `ASSISTANT_PROVIDER` | `anthropic` | `anthropic` (cloud) or `ollama` (local/offline) |
| `ASSISTANT_MODEL` | `claude-opus-5-5` | Model for `anthropic` |
| `ANTHROPIC_API_KEY` | empty | API key for `anthropic`; never committed, set it only in the server `.env` |
| `OLLAMA_URL` | `http://localhost:11434` (SDK default) | Base URL of the Ollama server, e.g. `http://ollama.internal:11434` |
| `ASSISTANT_OLLAMA_MODEL` | empty | Model name for `ollama`, must support tool calling |
| `ASSISTANT_MAX_STEPS` | `12` | Model/tool round trips per prompt |
| `ASSISTANT_THROTTLE_PER_MINUTE` | `20` | Chat and decision requests per minute and orga |

After changing the module toggle or the provider, rebuild/restart the app image as usual (config is read at boot).

## Claude (default)

1. Create an API key in the Anthropic console and put it into the server `.env` as `ANTHROPIC_API_KEY`.
2. `MODULE_ASSISTANT=true` (or `MODULES_PROFILE=full`), redeploy.
3. Preflight shows "KI-Assistent (Modell-Anbieter)". The probe calls `GET /v1/models` (no model run, no cost);
   a rejected key is "down", an unreachable API is a warning.

Cost: every chat turn is a paid API call, and a turn with tools is several calls (read tools, then the answer).
`ASSISTANT_THROTTLE_PER_MINUTE` and `ASSISTANT_MAX_STEPS` bound the worst case per orga.

## Ollama (offline at the venue)

Ollama runs with the **full tool set, write tools included**: the same approval cards protect it. The model must
call tools reliably; a model that invents arguments only produces cards the orga rejects, but it is a poor
experience. Pick a current tool-calling model that fits the GPU (for example one of the Qwen3 or Llama 3.x
instruct models) and test the three example prompts above before the event.

1. Run Ollama on a host the app server can reach (`OLLAMA_URL`), `ollama pull <model>`.
2. `ASSISTANT_PROVIDER=ollama`, `ASSISTANT_OLLAMA_MODEL=<model>` (an explicit tag such as `qwen3:8b` must match
   exactly; without a tag any pulled tag matches).
3. Preflight is "down" when the server is unreachable or the model is not pulled (there is no fallback).

Failover between providers (`config/assistant.php` `failover`) only covers rate-limit, overload and credit errors,
**not a network outage**. Offline at the venue, switch `ASSISTANT_PROVIDER` to `ollama` explicitly.

## What the assistant can do (v1)

| Tool | Kind | Needs |
| --- | --- | --- |
| `events_list`, `games_list`, `tournaments_list`, `tournament_get`, `servers_list`, `server_settings_get`, `server_logs` | read | orga |
| `tournament_create`, `tournament_update`, `tournament_advance` (announce, open enrollment, open check-in, start, back to draft) | write, card | orga |
| `server_start_preset`, `server_settings_update`, `server_change_map`, `server_power`, `server_console`, `server_restart_round` | write, card | orga |
| `server_create_permanent` | write, card | admin only |

Not exposed: deleting servers or tournaments, `ProvisionGameServer`, an ungated `PowerServer`. Catering and
participant tools are not part of v1; the registry (`App\Modules\Assistant\Support\ToolRegistry`) takes more
tool classes and roles without a rebuild.

## Security model

- The agent is built for the logged-in orga; every tool carries that user and re-checks the Policies at
  execution (`Gate::forUser($actor)`). A chat request never authorizes more than the same user could do in the UI.
- Writes run only against an **approved audit row** for the exact call id and a matching arguments hash
  (fail closed); the SDK resumes with the arguments it stored, the client sends only `approve`/`reject`.
  A new chat message supersedes open cards: an old card can never be approved against changed data.
- The card is built by the tool from the stored arguments, never from model text. Console commands are shown verbatim.
- Conversations belong to their owner. The ownership check calls the policy directly, because the application's
  admin `Gate::before` would otherwise let an admin read and decide on other people's chats.
- **Secrets never reach the model.** No tool has a password field. `server_settings_update` takes
  `password_action` (`set`, `clear`, `keep`); for `set` the orga types the password on the card. It is held
  encrypted on the audit row until the tool runs, then nulled, and it never appears in tool arguments, tool
  results, conversation storage or provider requests. Console commands that touch passwords or the Steam login
  token are refused before a card is shown; log and console output is scrubbed of secret patterns first.
- Untrusted text (names, logs, console output) is clamped and framed as data; the system prompt tells the model
  to ignore instructions inside tool results.
- Rate limit `assistant` per user on the chat and decision endpoints, `max_steps` on the agent.

## Streaming behind the proxy

The chat streams server-sent events (`Content-Type: text/event-stream`, `Cache-Control: no-cache, no-transform`,
`X-Accel-Buffering: no`). Production is FrankenPHP in classic mode behind Traefik: Traefik flushes event streams
as they arrive, and the Traefik `buffering` middlewares in `docker/traefik/dynamic.yml` buffer **request** bodies
only. The Caddyfile compresses responses (`encode zstd br gzip`), and compression would buffer a stream; the SDK marks the
response `no-transform`, but whether this Caddy build honours that for event streams is **not verified**. If the
answer arrives in one piece, exclude `text/event-stream` from `encode` (a `match` block) in
`docker/frankenphp/Caddyfile`. After the first deploy, verify end to end (see below) that the answer arrives in pieces and not at once.

## Privacy

With the cloud provider, names and data from tool results (participants, teams, tournament and server names,
logs the orga asked for) are sent to Anthropic as part of the conversation. Ollama keeps everything on the
venue network. Passwords never reach the provider. Conversations are kept until their owner deletes them
(trash button; there is no automatic expiry); audit rows (`assistant_actions`) survive a deleted conversation.
See ruling AU in [`docs/qa/rulings.md`](../qa/rulings.md).

## After the deploy

1. Set `ANTHROPIC_API_KEY` (or `OLLAMA_URL` plus `ASSISTANT_OLLAMA_MODEL` and `ASSISTANT_PROVIDER=ollama`) in the
   server `.env`, set `MODULE_ASSISTANT=true`, redeploy all four services as usual.
2. `php artisan lanomat:preflight` shows "KI-Assistent (Modell-Anbieter)" as OK.
3. Open `/orga/assistant` as orga. Check: a read question ("Welche Events gibt es?"); a write proposal shows a
   card, "Ablehnen" changes nothing, "Ausführen" does; the answer streams word by word
   (`curl -N -b <session> -d '{"message":"hi"}' -H 'Content-Type: application/json' https://<host>/orga/assistant/chat`
   with a valid session prints chunks over time); a console command shows verbatim; a server password is typed on
   the card and is not in the chat history.
4. `/admin/assistant-actions` lists every proposal and outcome.

## Spike notes (what was verified against `laravel/ai` 1.4.x)

Verified from the SDK source and a faked-gateway run (no provider reached):

- A paused run stores the pending call (`approval_reason`); `ConversationStore::pendingApprovalsFor()` returns it.
  The `ToolApprovalRequested` event fires only after the stream drains, so the stored pause is the authority for
  "what is pending" and the audit row is created idempotently from it.
- Resuming with `Decisions` executes the **stored** arguments; `Request::toolCallId()` is available in `handle()`,
  so secrets bind by call id (the arguments hash stays as the "card equals execution" check). The plan's
  arguments-hash-only binding was therefore not needed.
- A new plain prompt while approvals are pending is allowed; the SDK settles the abandoned call as "not executed".
- The SDK requires a decision for **every** gated call of the paused turn (a partial resume is a 409). The page
  collects one decision per card and sends them together; the endpoint refuses an incomplete set.
- `needsApproval()` is called outside the tool's error mapping, so it is pure and never throws; unusable arguments
  skip the card and `handle()` answers with the reason.
- The SDK's `Vercel::chat()` would parse client-supplied messages into decisions (including `edit`); it is not used.

Deviations from the plan, all in the safer direction:

- The chat page uses a small own reader for the Vercel UI message stream instead of `@ai-sdk/vue` `useChat`
  (its request shape does not fit the string/decisions endpoints and it could not be verified without a browser).
- Only the endpoints that call the model are throttled (chat and decisions), not page loads.
- Unknown conversation ids are 404, foreign ones 403.

Not verified (needs a key or a server, do it at the first real run): Opus through the SDK with thinking, the
approval round trip against a real Ollama model, the end-to-end proxy streaming above, and how a local model
behaves when it reuses a tool-call id (the audit log gives a call id with different arguments its own row, so
this cannot arm a wrong card, but the SDK's own pairing of such ids was not exercised).
