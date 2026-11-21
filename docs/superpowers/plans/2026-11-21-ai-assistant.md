# AI Assistant (Orga Chat) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An orga-only chat at `/orga/assistant` that reads LANoMAT state and, after a click on a preview card, creates/advances tournaments and starts/configures/controls game servers.

**Architecture:**
- **Module:** a new optional module `app/Modules/Assistant` built on first-party `laravel/ai`.
- **Tools:** one class per tool. Each is a thin wrapper around an existing Action plus a Policy check against an explicit actor.
- **Writes:** every write tool needs approval and every step is logged in `assistant_actions`.
- **Secrets:** they never reach the model. A password is typed on the card and matched to the call by an arguments hash.
- **UI:** an Inertia/Vue page with streaming.

**Tech Stack:** PHP 8.4, Laravel 13, `laravel/ai` ^1.4, Pest 4, Inertia v2 + Vue 3 + Tailwind v4 + shadcn-vue, `@ai-sdk/vue` (subject to Task 1).

**Spec:** `docs/superpowers/specs/2026-11-21-ai-assistant-design.md`. Read it first; its "Security rules (binding)" section applies to every task.

## Global Constraints

- **Module:**
  - Case `Module::Assistant = 'assistant'`, not core, no `requires()` entry.
  - Every tool gated by `Modules::enabled()` of the module it wraps (Tournaments, GameServers).
- **Defaults:** provider `anthropic`, model `claude-opus-5-5` (`ASSISTANT_MODEL`), Ollama via `ASSISTANT_PROVIDER=ollama` with the **full** tool set.
- **Actor:** tools never call `Auth::user()`. The actor is the `User` passed to the agent, and every `handle()` re-authorizes through `Gate::forUser($actor)`.
- **Never exposed as tools:** `ProvisionGameServer`, ungated `PowerServer`, `DeletePermanentServer`.
- **Untrusted text:** clamped with `App\Support\SafeText::line()`; logs scrubbed by `SecretScrubber` (Task 4) first.
- **No password field** in any tool schema. `password_action` ∈ `set|clear|keep`; for `set` the secret comes from the card only.
- **`assistant_actions.status`** ∈ `proposed|approved|rejected|superseded|executed|failed`.
- **Retention:** conversations are kept until their owner deletes them; audit rows survive (no cascade).
- **Copy:**
  - UI copy German via `lang/de/assistant.php`.
  - Code, comments and docs English.
  - Conventional Commits, each ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Gates after every task:** `composer check` green. Frontend tasks additionally need `npm run types:check && npm run lint:check && npm run format:check && npm run build`.
- **Tests never reach a provider:** every assistant test uses `LanomatAssistant::fake()->preventStrayPrompts()` or calls tools directly.
- **Public repo:** no keys, hosts or IPs in any file.

## Review Focus

1. **Stale card approved after the world changed:** the orga writes a new message, then clicks an old card. Expected: 409/422, and the row is `superseded`, never executed. Test is in Task 7.
2. **Same tool proposed twice with different arguments in one turn, one with a password:** the secret must bind to the right call, never the sibling. Test is in Task 7 (`secret binds by arguments hash`).
3. **The model invents an id** (tournament/server id from another event, or nonexistent): expect a readable "Not found." tool result, no 500, no write. Test is in Task 4 (base class).
4. **Orga asks for an admin-only action** (permanent server create): the card may be proposed, but approval returns a readable "Forbidden" and the row is `failed`. Test is in Task 6.
5. **A console command smuggles a secret** (`sv_password x; status`, mixed case, leading spaces): the tool rejects it before proposal. Test is in Task 6.

---

### Task 1: Spike: verify `laravel/ai` approval flow (throwaway)

Run by the controller/advisor model, not a haiku implementer. Only the dependency install and a decision record get committed; the spike code is deleted.

**Files:**
- Modify: `composer.json`, `composer.lock` (`composer require laravel/ai:^1.4`)
- Create (throwaway, never committed): `app/Spike/*`, `routes/spike.php`
- Modify: `docs/superpowers/specs/2026-11-21-ai-assistant-design.md` (append `## Spike result`)

**Interfaces:**
- Produces: the decision **Path A** (SDK approvals) or **Path B** (own two-step), and the verified names that Tasks 4–8 rely on:
  - the tool-name mechanism of `laravel/ai`
  - the event carrying pending approvals plus its payload fields
  - how `Decisions` are passed to a streamed resume
  - whether a new prompt is allowed while approvals are pending

Verified names override this plan's guesses; update the Interfaces blocks below in the same commit.

- [ ] **Step 1:** `composer require laravel/ai:^1.4`, then `php artisan vendor:publish --provider="Laravel\Ai\AiServiceProvider"`, then `php artisan migrate`. Read `vendor/laravel/ai/src` for `Approvable`, `InteractsWithApprovals`, `Decisions`, `ConversationStore`, `Vercel`, `ToolApprovalRequested`, and the Tool name resolution.
- [ ] **Step 2:** Build a spike agent with one approvable tool `echo_write(text)` and one read tool. Run it against Anthropic (`claude-opus-5-5`) through a spike route that streams with `usingVercelDataProtocol()`. If `ANTHROPIC_API_KEY` is not set locally, stop and ask the user for one (`! export …` in their shell).
- [ ] **Step 3:** Check each of these and record the answer:
  - (a) the stream emits an approval part with call id and arguments;
  - (b) `continue($id, as: $user)->stream(Decisions::from([...]))` resumes and executes the **stored** arguments;
  - (c) reject-without-result stops the loop;
  - (d) a new plain prompt while approvals are pending: error, auto-reject, or allowed;
  - (e) inside `handle()`, is the call id available? (If not, the arguments-hash binding from Task 7 is required.)
- [ ] **Step 4:** Repeat Steps 2–3 with Ollama (any tool-calling model available locally, `OLLAMA_URL`). If no Ollama is reachable, record "Ollama unverified" and continue.
- [ ] **Step 5:** Minimal Vue check: does `@ai-sdk/vue` `useChat` render the approval part and send decisions? Record the npm version.
- [ ] **Step 6:** Decide. **Path A** if (a), (b) and (d) are workable. Otherwise **Path B**: the write tool returns `"awaiting confirmation (action #id)"` after logging `proposed`, and `POST /orga/assistant/actions/{action}/decide` executes. Append `## Spike result` to the spec with answers (a)–(e), the path and the versions.
- [ ] **Step 7:** `git rm`/delete the spike files. Commit only composer files, published `config/ai.php`, the SDK migrations and the spec: `chore(assistant): add laravel/ai, record approval spike result`.

### Task 2: Module scaffold, config and audit model

**Files:**
- Modify: `app/Support/Modules/Module.php` (case `Assistant = 'assistant'`), `resources/js/types/index.ts` (`ModuleKey` union), `.env.example`, `app/Models/User.php` (`use HasConversations`), `tests/Architecture/module-dependencies.php` (regenerate with `LANOMAT_ARCH_DUMP=1`)
- Create:
  - `config/assistant.php`
  - `app/Modules/Assistant/Enums/AssistantActionStatus.php`
  - `app/Modules/Assistant/Models/AssistantAction.php`
  - `database/migrations/2026_11_21_130000_create_assistant_actions_table.php`
  - `database/factories/AssistantActionFactory.php`
  - `lang/de/assistant.php`
- Test: `tests/Feature/Assistant/AssistantModuleTest.php`, `tests/Unit/Assistant/AssistantActionTest.php`

**Interfaces:**
- Produces:
  - `config('assistant.provider')` (string, default `anthropic`)
  - `config('assistant.model')` (`claude-opus-5-5`)
  - `config('assistant.ollama_model')` (string|null)
  - `config('assistant.failover')` (list<string>, default `[]`)
  - `config('assistant.max_steps')` (int, 12)
  - `config('assistant.throttle_per_minute')` (int, 20)
  - `enum AssistantActionStatus: string { Proposed='proposed'; Approved='approved'; Rejected='rejected'; Superseded='superseded'; Executed='executed'; Failed='failed'; public function isOpen(): bool /* only Proposed */ }`
  - `AssistantAction` model:
    - columns `id, user_id (FK users cascade), conversation_id (string, indexed, no FK), tool_call_id (string, nullable), tool (string), arguments (jsonb), arguments_hash (string(64), indexed), status, secret (text nullable), result_summary (text nullable), error (text nullable), timestamps`
    - casts `arguments => array`, `status => AssistantActionStatus`, `secret => encrypted`; `$hidden = ['secret']`
    - `$fillable` excludes `status` and `secret`; Task 7's log service sets them through `forceFill`.
- `.env.example` lines: `MODULE_ASSISTANT=`, `ASSISTANT_PROVIDER=anthropic`, `ASSISTANT_MODEL=claude-opus-5-5`, `ANTHROPIC_API_KEY=`, `OLLAMA_URL=`, `ASSISTANT_OLLAMA_MODEL=`.

- [ ] **Step 1: Write the failing tests**

```php
it('is optional and off in the core profile', function () {
    expect(Module::Assistant->isCore())->toBeFalse()
        ->and(Module::Assistant->requires())->toBe([]);
});

it('encrypts and hides the secret', function () {
    $action = AssistantAction::factory()->create();
    $action->forceFill(['secret' => 'lan2026'])->save();
    $raw = DB::table('assistant_actions')->where('id', $action->id)->value('secret');
    expect($raw)->not->toContain('lan2026')
        ->and($action->fresh()->secret)->toBe('lan2026')
        ->and($action->fresh()->toArray())->not->toHaveKey('secret');
});

it('only treats proposed as open', function () {
    expect(collect(AssistantActionStatus::cases())->filter->isOpen()->all())
        ->toBe([AssistantActionStatus::Proposed]);
});
```

- [ ] **Step 2:** Run `./vendor/bin/pest tests/Feature/Assistant/AssistantModuleTest.php tests/Unit/Assistant/AssistantActionTest.php`. Expected: FAIL (class/case not found).
- [ ] **Step 3:** Implement the files listed above. `ModuleKeyDriftTest` and `EnvExampleTest` must pass.
- [ ] **Step 4:** Run `composer check`. Expected: green, including `tests/Architecture`.
- [ ] **Step 5:** Commit `feat(assistant): module scaffold, config and audit model`.

### Task 3: `CreateTournament` and `UpdateTournament` actions

**Files:**
- Create: `app/Modules/Tournaments/Actions/CreateTournament.php`, `app/Modules/Tournaments/Actions/UpdateTournament.php`
- Modify: `app/Modules/Tournaments/Filament/Resources/Tournaments/Pages/CreateTournament.php` (override `handleRecordCreation()` to call the action)
- Test: `tests/Feature/Tournaments/CreateTournamentActionTest.php`, `tests/Feature/Tournaments/UpdateTournamentActionTest.php`

**Interfaces:**
- Produces:
  - `CreateTournament::handle(User $actor, Event $event, array $data): Tournament`
    - Authorizes `create`.
    - Validates the same rules as `TournamentForm`:
      - `name` required|max:255
      - `format` enum `TournamentFormat`
      - `team_size` int 1–32
      - `starts_at` date
      - `game_id` nullable exists
      - `max_entries` nullable int 2–1024
      - `checkin_opens_at`/`checkin_closes_at` nullable, closes after opens
      - `rules` nullable max:10000
      - `settings` nullable array
    - Status stays `draft`.
  - `UpdateTournament::handle(User $actor, Tournament $tournament, array $data): Tournament`
    - Authorizes `update`.
    - Only while status ∈ `draft|announced`, otherwise throws `TournamentException` (new translation key `tournaments.errors.not_editable`).
    - Same rules, all `sometimes`.
- Both throw `ValidationException` and `AuthorizationException` unchanged.

- [ ] **Step 1:** Write the failing tests:
  - `creates a draft tournament for an orga`: status `Draft`, `TournamentSaved` dispatched.
  - `rejects a participant` (AuthorizationException).
  - `rejects checkin closing before opening` (ValidationException on `checkin_closes_at`).
  - `refuses to update a live tournament` (TournamentException).
  - `updates missing setup fields so the setup check passes`: after update, `TournamentSetupCheck::missingFields($t)` is `[]`.
  - `Filament create page uses the action`: Livewire `CreateTournament` page test creates a draft through the form.
- [ ] **Step 2:** Run `./vendor/bin/pest tests/Feature/Tournaments/CreateTournamentActionTest.php tests/Feature/Tournaments/UpdateTournamentActionTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement both actions and the page override.
- [ ] **Step 4:** Run the two tests plus `./vendor/bin/pest tests/Feature/Tournaments`. Expected: PASS.
- [ ] **Step 5:** Commit `feat(tournaments): create/update actions with policy and validation`.

### Task 4: Tool base, registry, scrubber and read tools

**Files:**
- Create:
  - `app/Modules/Assistant/Tools/AssistantTool.php`
  - `app/Modules/Assistant/Support/ToolRegistry.php`
  - `app/Modules/Assistant/Support/SecretScrubber.php`
  - `app/Modules/Assistant/Tools/Read/{EventsListTool,GamesListTool,TournamentsListTool,TournamentGetTool,ServersListTool,ServerSettingsGetTool,ServerLogsTool}.php`
- Test:
  - `tests/Unit/Assistant/SecretScrubberTest.php`
  - `tests/Feature/Assistant/Tools/AssistantToolBaseTest.php`
  - `tests/Feature/Assistant/Tools/ReadToolsTest.php`
  - `tests/Feature/Assistant/ToolRegistryTest.php`

**Interfaces:**
- Consumes: Task 1's verified Tool contract (`Laravel\Ai\Contracts\Tool`: `description()`, `handle(Request)`, `schema(JsonSchema)`) and its name mechanism.
- Produces:
  - `abstract class AssistantTool implements Tool`:
    - `__construct(protected readonly User $actor)`
    - `abstract public static function toolName(): string`
    - `final public function handle(Request $request): string`, which wraps `run()` with the error mapping of `app/Modules/Mcp/Tools/LanomatTool.php:41-96`: validation → `field: msg`, auth → `Forbidden: …`, not found → `Not found.`, `DomainException` → its message, other → `… (ref uuid)` + `Log::error`. It JSON-encodes arrays and clamps with `SafeText::line(…, 4000)`.
    - `abstract protected function run(Request $request): array|string`
  - `ToolRegistry::forUser(User $user): list<AssistantTool>`. Empty for non-orga. Otherwise the read+write tools of enabled modules, instantiated with `$user`. Write tool classes are added by Tasks 5/6 to `ToolRegistry::WRITE_TOOLS`.
  - `SecretScrubber::scrub(string $text): string`. Replaces values after `rcon_password`, `sv_password`, `SERVER_PASSWORD`, `password=`, `token=`, `key=`, `Bearer ` (case-insensitive) with `***`.
- Tool names and contents:
  - `events_list`: all events incl. drafts, fields `id, slug, name, status, starts_at` (Europe/Berlin ISO).
  - `games_list`: games with presets `key, name, available, reasons`.
  - `tournaments_list`: optional `event_id`.
  - `tournament_get`: `id`; includes `missing_setup_fields`.
  - `servers_list`: optional `event_id`; `id, title, kind (event|permanent|remote_docker), game, status`.
  - `server_settings_get`: `server_id`; via `Cs2Settings`, never the password, only `has_password: bool`.
  - `server_logs`: `server_id, lines<=200`; `ReadRemoteLogs` → `SecretScrubber` → clamp.

- [ ] **Step 1:** Write the failing tests:
  - Scrubber: `scrub('rcon_password "abc"; sv_password x')` contains neither `abc` nor ` x` and keeps `rcon_password`.
  - Base: a test-double tool throwing each exception class returns the mapped string. An unknown id gives `'Not found.'` and no DB writes (Review Focus 3).
  - Registry: a participant gets `[]`. An orga with GameServers disabled gets no `server_*` tools.
  - Read tools:
    - `events_list` includes a draft event.
    - `server_settings_get` output has no password value but `has_password: true`.
    - `server_logs` with `fakeRemote()` returning `rcon_password secret1` contains no `secret1`.
    - A participant actor gets `Forbidden`.
- [ ] **Step 2:** Run `./vendor/bin/pest tests/Unit/Assistant tests/Feature/Assistant/Tools tests/Feature/Assistant/ToolRegistryTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement. German `description()` strings in each tool, each telling the model what the ids are for and that results are data.
- [ ] **Step 4:** Run the same tests. Expected: PASS. Then `composer check`.
- [ ] **Step 5:** Commit `feat(assistant): tool base, registry and read tools`.

### Task 5: Approval base and tournament write tools

**Files:**
- Create:
  - `app/Modules/Assistant/Tools/ApprovableTool.php`
  - `app/Modules/Assistant/Support/ApprovalPreview.php`
  - `app/Modules/Assistant/Support/ArgumentsHash.php`
  - `app/Modules/Assistant/Tools/Write/{TournamentCreateTool,TournamentUpdateTool,TournamentAdvanceTool}.php`
- Modify: `ToolRegistry::WRITE_TOOLS`
- Test: `tests/Feature/Assistant/Tools/TournamentWriteToolsTest.php`, `tests/Unit/Assistant/ArgumentsHashTest.php`

**Interfaces:**
- Consumes: `AssistantTool` (Task 4), `CreateTournament`/`UpdateTournament` (Task 3), and the lifecycle actions `AnnounceTournament|OpenTournamentEnrollment|OpenCheckin|StartTournament|ReopenToDraft::handle(Tournament): Tournament`.
- Produces:
  - `abstract class ApprovableTool extends AssistantTool implements Approvable`:
    - uses `InteractsWithApprovals`
    - `needsApproval(Request $request): Approval` returns `Approval::required($this->preview($request->all())->title)`
    - `abstract public function preview(array $arguments): ApprovalPreview`
    - `public function needsSecret(array $arguments): bool` (default `false`)
    - **Path B:** `handle()` logs `proposed` and returns the awaiting text; the real work moves to `execute(array $arguments, ?string $secret): array|string`. Define `execute()` in both paths and let Path A's `run()` call it.
  - `final readonly class ApprovalPreview`:
    - `__construct(public string $title, public array $rows, public bool $danger = false, public bool $needsSecret = false)`
    - rows are `list<array{label: string, value: string, mono: bool}>`
    - `toArray(): array`
  - `ArgumentsHash::of(string $tool, array $arguments): string`: sha256 of tool + recursively key-sorted JSON.
  - Tools:
    - `tournament_create` (`event_id` + Task 3 fields): preview rows for name, format (German label), team size, start, check-in window, max entries, game.
    - `tournament_update` (`tournament_id` + fields): preview shows only changed fields, old → new.
    - `tournament_advance` (`tournament_id`, `step` enum `announce|open_enrollment|open_checkin|start|reopen_draft`): re-checks `Gate::forUser($actor)->authorize('manage', $t)` in `execute()`; `danger = step === 'start'`.

- [ ] **Step 1:** Write the failing tests:
  - `ArgumentsHash` is order-insensitive (`['a'=>1,'b'=>2]` equals `['b'=>2,'a'=>1]`) and tool-sensitive.
  - `tournament_create` preview lists the stored arguments verbatim (name `CS2 5v5`, `16` entries).
  - `execute()` creates the draft. As a participant it returns `Forbidden…` and creates nothing.
  - `tournament_advance` `open_enrollment` with missing setup fields returns the translated `TournamentSetupCheck` message, not a ref id.
  - `tournament_advance` `start` has `danger = true`.
- [ ] **Step 2:** Run `./vendor/bin/pest tests/Feature/Assistant/Tools/TournamentWriteToolsTest.php tests/Unit/Assistant/ArgumentsHashTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Same run, PASS; then `composer check`.
- [ ] **Step 5:** Commit `feat(assistant): approval base and tournament write tools`.

### Task 6: Server write tools

**Files:**
- Create: `app/Modules/Assistant/Tools/Write/{ServerStartPresetTool,ServerCreatePermanentTool,ServerSettingsUpdateTool,ServerChangeMapTool,ServerPowerTool,ServerConsoleTool,ServerRestartRoundTool}.php`
- Modify: `ToolRegistry::WRITE_TOOLS`
- Test: `tests/Feature/Assistant/Tools/ServerWriteToolsTest.php`

**Interfaces:**
- Consumes: `ApprovableTool::execute(array $arguments, ?string $secret)`, `needsSecret()` (Task 5). Also these actions:
  - `StartServerFromPreset::handle(Event, Game, string $presetKey, ?string $title, array $overrides, User): ServerLink`
  - `CreatePermanentServer::pelican(User, Game, string $presetKey, string $title, ?string $description, ?string $nodeId, array $overrides = []): ServerLink`
  - `UpdateServerSettings::handle(User, ServerLink, array $input, bool $restart = false): array`
  - `ChangeServerMap::handle(User, ServerLink, ?string $map, ?string $workshopId): void`
  - `PowerConfiguredServer::handle(User, ServerLink, PowerAction): void`
  - `ControlRemoteDockerPower::handle(User, ServerLink, PowerAction): ?string`
  - `PowerServer::handle(ServerLink, PowerAction): void`
  - `SendRemoteConsoleCommand::handle(User, ServerLink, string): string`
  - `RestartRound::handle(User, ServerLink): void`
- Produces the tools:
  - `server_start_preset` (`event_id, game_id, preset_key, title?, overrides?`)
  - `server_create_permanent` (`game_id, preset_key, title, description?, node_id?`)
  - `server_settings_update`:
    - arguments `server_id, server_name?, mode?, map?, workshop_id?, max_players?, password_action: set|clear|keep (default keep), restart: bool`
    - `needsSecret = password_action === 'set'`
    - `execute` maps `set` → `input['password'] = $secret` (a missing/empty secret is rejected with a German message) and `clear` → `input['clear_password'] = true`
    - the preview shows `Passwort: wird auf der Karte gesetzt` / `entfernt`, never a value
  - `server_change_map` (`server_id, map?, workshop_id?`)
  - `server_power` (`server_id, action: start|stop|restart|kill`):
    - permanent link → `PowerConfiguredServer`
    - remote-docker link → `ControlRemoteDockerPower`
    - event/match link → `Gate::forUser($actor)->authorize('power', $link)` then `PowerServer`
    - `danger` for stop/kill
  - `server_console` (`server_id, command`):
    - Before proposal (inside `needsApproval`/`preview` and again in `execute`), reject a `command` matching `/(^|[;\s])(rcon_password|sv_password|sv_setsteamaccount)\b/i` with the German message "Passwörter bitte über die Server-Einstellungen setzen".
    - The preview shows the command verbatim, `mono = true`, `danger = true`.
  - `server_restart_round` (`server_id`).

- [ ] **Step 1:** Write the failing tests, using `fakePelican()` and `fakeRemote()`:
  - An orga's `server_create_permanent` `execute()` returns `Forbidden…`, and an admin's creates a link (Review Focus 4).
  - `server_settings_update` with `password_action=set` and secret `lan2026` passes `lan2026` to Pelican. The preview and `ArgumentsHash` input contain no `lan2026`.
  - `password_action=set` without a secret returns the German error and changes nothing.
  - `server_console` rejects `sv_password x; status`, `  RCON_PASSWORD y`, `status;sv_password z` (Review Focus 5) and accepts `mp_restartgame 1`.
  - `server_power stop` on an event link as a participant gives `Forbidden`, and `PowerServer` is not called.
- [ ] **Step 2:** Run `./vendor/bin/pest tests/Feature/Assistant/Tools/ServerWriteToolsTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Same run, PASS; then `composer check`.
- [ ] **Step 5:** Commit `feat(assistant): server write tools with card-only secrets`.

### Task 7: Agent, approval lifecycle, controller and routes

**Files:**
- Create:
  - `app/Modules/Assistant/Agents/LanomatAssistant.php`
  - `app/Modules/Assistant/Support/AssistantActionLog.php`
  - `app/Modules/Assistant/Support/ApprovalSecrets.php`
  - `app/Modules/Assistant/Policies/AssistantConversationPolicy.php`
  - `app/Modules/Assistant/Http/AssistantController.php`
  - `app/Modules/Assistant/Http/Requests/{ChatRequest,DecisionRequest}.php`
  - `app/Modules/Assistant/Listeners/RecordProposedAction.php` (Path A)
- Modify:
  - `routes/web.php` (module block)
  - `app/Providers/AppServiceProvider.php` (`RateLimiter::for('assistant', …)`, listener, policy)
  - `tests/Feature/Security/RouteAuthorizationMatrixTest.php` (only if the suite demands entries; the routes carry `role:orga`)
- Test:
  - `tests/Feature/Assistant/AssistantAgentTest.php`
  - `tests/Feature/Assistant/AssistantHttpTest.php`
  - `tests/Feature/Assistant/ApprovalLifecycleTest.php`

**Interfaces:**
- Consumes: `ToolRegistry::forUser()` (Task 4), `ApprovableTool::preview()/needsSecret()/execute()` (Tasks 5–6), `ArgumentsHash::of()`, `AssistantAction`.
- Produces:
  - `LanomatAssistant` (implements `Agent, Conversational, HasTools`, uses `Promptable, RemembersConversations`):
    - `static make(User $actor)`
    - `instructions()`: the German system prompt from the spec "Configuration" section, static text only
    - `tools()` = `ToolRegistry::forUser($actor)`
    - provider, model and max steps from `config/assistant.php`
    - the current time (Europe/Berlin) is prefixed to each user prompt by the controller as `[Jetzt: 2026-11-21 14:03]`
  - `AssistantActionLog`:
    - `propose(User $user, string $conversationId, ?string $callId, string $tool, array $arguments): AssistantAction` (stores masked arguments and `arguments_hash`)
    - `supersedeOpen(User $user, string $conversationId): int`
    - `decide(AssistantAction $action, bool $approve, ?string $secret): void` (throws `DomainException` unless status is `Proposed`)
    - `executed(…)`, `failed(…)`
  - `ApprovalSecrets::for(User $actor, string $tool, array $arguments): ?string`. Looks up the newest `Approved` row with matching `arguments_hash` for the actor, returns its secret and nulls it (single use). `ApprovableTool::run()` calls this before `execute()`.
  - `AssistantConversationPolicy::view|continue|delete(User $user, string $conversationId): bool` via `ConversationStore::conversationBelongsTo`.
  - Routes, inside `if ($modules->routable(Module::Assistant))`, middleware `auth, role:orga, throttle:assistant`, names `orga.assistant.*`:
    - `GET /orga/assistant` → `index`
    - `GET /orga/assistant/{conversation}` → `show`
    - `POST /orga/assistant/chat` → `chat` (body `conversation_id?`, `message` max:4000): calls `supersedeOpen` first, then streams
    - `POST /orga/assistant/{conversation}/decisions` → `decide` (body `decisions: {callId: approve|reject}`, `secrets?: {callId: string max:128}`), resumes as a stream
    - `DELETE /orga/assistant/{conversation}` → `destroy`
    - Path B adds `POST /orga/assistant/actions/{action}/decide`.
  - Page props for `Orga/Assistant`:
    - `conversations: {id, title, updated_at}[]`
    - `conversationId: string|null`
    - `messages` (Vercel UI messages)
    - `pending: {callId, tool, preview: ApprovalPreview::toArray()}[]`
    - `provider: string`

- [ ] **Step 1:** Write the failing tests. Each starts with `LanomatAssistant::fake()->preventStrayPrompts()` or `fakeWithPendingApprovals`:
  - `chat` as a participant gives 403. As an orga with the module off gives 404.
  - `show`, `decide` and `destroy` on another orga's conversation give 403, and no row changes.
  - `chat` with a pending `Proposed` row marks it `Superseded`. A later `decide` on it gives 422, and the tool was not executed (Review Focus 1).
  - Approve writes `Approved` then `Executed` and the tournament exists. Reject writes `Rejected` and nothing is created. Two cards from one turn can be decided individually.
  - **Secret binds by arguments hash:** two pending `server_settings_update` calls with different `server_id`. A secret given for call A reaches only server A, and the secret column is `null` afterwards (Review Focus 2).
  - The stored `agent_conversation_messages` never contain the secret string.
  - Throttle: request 21 within a minute gives 429.
- [ ] **Step 2:** Run `./vendor/bin/pest tests/Feature/Assistant/AssistantAgentTest.php tests/Feature/Assistant/AssistantHttpTest.php tests/Feature/Assistant/ApprovalLifecycleTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement. On Path A, `decide` builds `Decisions::from()` only for ids whose `AssistantAction` row is `Proposed` and owned by the caller. It stores secrets via `AssistantActionLog::decide()` before resuming.
- [ ] **Step 4:** Run the same tests plus `./vendor/bin/pest tests/Feature/Security`. Expected: PASS. Then `composer check`.
- [ ] **Step 5:** Commit `feat(assistant): agent, approval lifecycle and HTTP endpoints`.

### Task 8: Chat page, sidebar and Filament link

Invoke the `frontend-design` skill first and design against `docs/design.md` (Signalpult). Implementer: sonnet, since the UI work is too broad for haiku.

**Files:**
- Create:
  - `resources/js/pages/Orga/Assistant.vue`
  - `resources/js/components/assistant/{ConversationList,MessageStream,ToolChip,ApprovalCard,Composer}.vue`
  - `resources/js/types/assistant.ts`
- Modify:
  - `resources/js/lib/pageLayouts.ts` (the `Orga/` prefix already maps to `app`; add an explicit entry if `PageLayoutsTest` requires one)
  - `resources/js/components/AppSidebar.vue` (`orgaItems`: "Assistent" when `has('assistant')`; it is global, so it needs no current event)
  - `app/Providers/Filament/AdminPanelProvider.php` (`NavigationItem` "Assistent" → `/orga/assistant`, visible when routable; label constant in `App\Support\ShellParity`)
  - `package.json` (`@ai-sdk/vue` at the Task 1 version, unless Task 1 chose the own SSE reader)
- Test: `tests/Feature/Assistant/AssistantPageTest.php` (Inertia props), `tests/Feature/ShellParity/ShellParityTest.php` (must stay green)

**Interfaces:**
- Consumes: the Task 7 routes and props; `ApprovalPreview::toArray()` (`title, rows[{label,value,mono}], danger, needsSecret`).
- Produces the UI behaviour:
  - **Composer:** disabled while streaming. Hint text "Passwörter nie in den Chat – sie werden auf der Karte gesetzt".
  - **ApprovalCard:**
    - buttons "Ausführen" (primary; destructive style when `danger`) and "Ablehnen"
    - a password input only when `needsSecret`
    - after a new message, an open card turns into "Überholt" and is read-only
  - **ToolChip:** read calls as compact chips; `mono` only for ids and commands.
  - **States:** empty (example prompts), loading (streaming indicator, reduced-motion safe), error (provider down: German message + retry), normal.
  - **Deleting a conversation:** confirm dialog.

- [ ] **Step 1:** Write the failing test `AssistantPageTest`:
  - An orga sees `Orga/Assistant` with `conversations` and `pending` props.
  - A pending row exposes the `preview` from the server, not model text: assert the `title` equals `ApprovalPreview::title`.
- [ ] **Step 2:** Run `./vendor/bin/pest tests/Feature/Assistant/AssistantPageTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement the page and components.
- [ ] **Step 4:** Run `./vendor/bin/pest tests/Feature/Assistant tests/Feature/ShellParity tests/Architecture` and `npm run types:check && npm run lint:check && npm run format:check && npm run build`. Expected: all green.
- [ ] **Step 5:** Manual check with `composer run dev`:
  - empty state
  - a streamed answer
  - a card with password field
  - an approve → result
  - 375 px width
  - keyboard focus visible
- [ ] **Step 6:** Commit `feat(assistant): orga chat page with approval cards`.

### Task 9: Preflight check and audit resource

**Files:**
- Create: `app/Modules/Preflight/Checks/AssistantProviderCheck.php`, `app/Modules/Assistant/Filament/Resources/AssistantActions/{AssistantActionResource.php,Pages/ListAssistantActions.php}`
- Modify: `app/Providers/AppServiceProvider.php` (tag the check when `Module::Assistant` is enabled), `lang/de/preflight.php`
- Test: `tests/Feature/Preflight/AssistantProviderCheckTest.php`, `tests/Feature/Assistant/AssistantActionResourceTest.php`

**Interfaces:**
- Produces:
  - `AssistantProviderCheck` (key `assistant_provider`):
    - `skipped` when the provider is `anthropic` without a key.
    - `anthropic`: `GET https://api.anthropic.com/v1/models` with `x-api-key`, timeout 3s.
    - `ollama`: `GET {OLLAMA_URL}/api/tags`, and `down` if the configured model is missing.
    - Use `Http::fake` in tests.
  - `AssistantActionResource`: read-only list (user, tool, status, created_at, result_summary/error), group `AdminNavigationGroup::Nutzer`, admin+orga view, no create/edit/delete. It never shows `secret`.

- [ ] **Step 1:** Write the failing tests:
  - anthropic without key → skipped
  - ollama with model present → ok
  - ollama missing model → down
  - the resource list renders rows and the page HTML never contains a stored secret
- [ ] **Step 2:** Run `./vendor/bin/pest tests/Feature/Preflight/AssistantProviderCheckTest.php tests/Feature/Assistant/AssistantActionResourceTest.php`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Same run, PASS; then `composer check`.
- [ ] **Step 5:** Commit `feat(assistant): preflight provider check and audit list`.

### Task 10: Docs and final verification

**Files:**
- Create: `docs/infra/assistant.md` (enable the module, Anthropic key, Ollama setup + model choice for tool calling, offline mode, cost note, privacy summary)
- Modify:
  - `docs/qa/rulings.md` (privacy ruling from the spec)
  - `README.md` (`## Project state`, env notes)
  - `CHANGELOG.md`
  - `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` (new section with insights from the spike)
  - `docs/architecture.md` (module list)

- [ ] **Step 1:** Write the docs. Use placeholders only (`lan.example`, `OLLAMA_URL=http://ollama.internal:11434`).
- [ ] **Step 2:** Run `scripts/ops/check-public-safe.sh && gitleaks detect --no-banner`. Expected: clean.
- [ ] **Step 3:** Run `composer check` and the four frontend gates. Expected: green.
- [ ] **Step 4:** Do the manual end-to-end run from the spec "Verification" list, items 1–6, against a local Anthropic key and, if available, Ollama. Record the results in the PR description.
- [ ] **Step 5:** Commit `docs(assistant): setup, privacy ruling, project state`. Then open the PR to `main`; the standing approval allows merging after the local gates pass. Deploying needs its own confirmation.
