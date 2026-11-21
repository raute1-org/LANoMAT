<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Assistant\Agents\LanomatAssistant;
use App\Modules\Assistant\Enums\AssistantActionStatus;
use App\Modules\Assistant\Http\Requests\ChatRequest;
use App\Modules\Assistant\Http\Requests\DecisionRequest;
use App\Modules\Assistant\Policies\AssistantConversationPolicy;
use App\Modules\Assistant\Support\AssistantActionLog;
use App\Modules\Assistant\Support\HistoryPresenter;
use App\Modules\Assistant\Support\PendingCards;
use App\Support\DisplayTimezone;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Orga chat. Thin: the agent, the tools and the audit log own the behaviour.
 *
 * - Every conversation id is checked against its owner before it is used
 *   (the SDK would continue any id it is given).
 * - The chat endpoint takes plain text; the decision endpoint takes only
 *   approve/reject per call id. Tool arguments never come from the client:
 *   the SDK resumes with the arguments it stored when the card was proposed.
 */
class AssistantController extends Controller
{
    public function __construct(
        private readonly AssistantActionLog $log,
        private readonly PendingCards $cards,
        private readonly HistoryPresenter $history,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return $this->page($this->user($request), null);
    }

    public function show(Request $request, Conversation $conversation): InertiaResponse
    {
        $this->owns($this->user($request), 'view', $conversation);

        return $this->page($this->user($request), $conversation);
    }

    public function chat(ChatRequest $request): Response
    {
        $user = $this->user($request);
        $conversationId = $request->validated('conversation_id');
        $text = (string) $request->validated('message');

        if (is_string($conversationId) && $conversationId !== '') {
            $conversation = Conversation::query()->find($conversationId) ?? abort(404);
            $this->owns($user, 'continue', $conversation);
            $conversationId = (string) $conversation->getKey();
        } else {
            $conversationId = resolve(ConversationStore::class)->storeConversation(
                Conversation::participantType($user),
                Conversation::participantKey($user),
                Str::limit(trim((string) preg_replace('/\s+/u', ' ', $text)), 60, '…'),
            );
        }

        // A new message closes every card that has not run yet: an old card can never be approved against changed data.
        $this->log->supersedeOpen($user, $conversationId);

        [$provider, $model] = LanomatAssistant::providerArguments();
        $prompt = '[Jetzt: '.DisplayTimezone::local(now())->format('Y-m-d H:i').' '.DisplayTimezone::name()."]\n".$text;

        $streamed = LanomatAssistant::make($user)
            ->continue($conversationId, as: $user)
            ->stream($prompt, provider: $provider, model: $model);

        return $this->streamResponse($request, $streamed, $conversationId);
    }

    public function decide(DecisionRequest $request, Conversation $conversation): Response|JsonResponse
    {
        $user = $this->user($request);
        $this->owns($user, 'continue', $conversation);

        $conversationId = (string) $conversation->getKey();
        /** @var array<string, string> $choices */
        $choices = $request->validated('decisions');
        /** @var array<string, string|null> $secrets */
        $secrets = $request->validated('secrets') ?? [];

        $pending = $this->cards->approvals($conversationId);

        // One resume decides the whole paused turn, so every open card needs an answer.
        if ($pending === [] || array_diff(array_keys($choices), array_map(fn ($a) => $a->id, $pending)) !== [] || count($choices) !== count($pending)) {
            $this->log->supersedeOpen($user, $conversationId);

            return response()->json(['message' => 'Diese Karten sind nicht mehr offen. Bitte lade die Unterhaltung neu.'], 422);
        }

        $rows = [];

        foreach ($pending as $approval) {
            $row = $this->log->propose($user, $conversationId, $approval->id, $approval->tool, $approval->arguments);
            $tool = $this->cards->toolFor($user, $approval->tool);
            $approve = ($choices[$approval->id] ?? null) === 'approve';

            if ($row->status !== AssistantActionStatus::Proposed || ($tool === null && $approve)) {
                $this->log->supersedeOpen($user, $conversationId);

                return response()->json(['message' => 'Eine dieser Karten ist nicht mehr gültig. Bitte lade die Unterhaltung neu.'], 422);
            }

            $secret = $approve && $tool->needsSecret($approval->arguments) ? trim((string) ($secrets[$approval->id] ?? '')) : null;

            if ($approve && $tool->needsSecret($approval->arguments) && $secret === '') {
                return response()->json(['message' => 'Bitte gib das Passwort auf der Karte ein.', 'errors' => ['secrets.'.$approval->id => ['Passwort fehlt.']]], 422);
            }

            $rows[] = [$row, $approve, $secret, $approval->id];
        }

        try {
            DB::transaction(function () use ($rows): void {
                foreach ($rows as [$row, $approve, $secret]) {
                    $this->log->decide($row, $approve, $secret);
                }
            });
        } catch (DomainException) {
            $this->log->supersedeOpen($user, $conversationId);

            return response()->json(['message' => 'Eine dieser Karten wurde bereits entschieden.'], 422);
        }

        [$provider, $model] = LanomatAssistant::providerArguments();

        try {
            $streamed = LanomatAssistant::make($user)
                ->continue($conversationId, as: $user)
                ->stream(Decisions::from(collect($rows)->mapWithKeys(fn (array $r): array => [$r[3] => $r[1]])->all()), provider: $provider, model: $model);
        } catch (Throwable $exception) {
            // Nothing ran: drop the approvals (and their secrets) instead of leaving them armed.
            $this->log->disarmApproved($user, $conversationId, array_map(fn (array $r): string => $r[3], $rows));

            throw $exception;
        }

        // Whatever the run left unexecuted must not stay armed.
        $callIds = array_map(fn (array $r): string => $r[3], $rows);
        $streamed->then(fn () => $this->log->disarmApproved($user, $conversationId, $callIds));

        return $this->streamResponse($request, $streamed, $conversationId);
    }

    public function destroy(Request $request, Conversation $conversation): Response
    {
        $user = $this->user($request);
        $this->owns($user, 'delete', $conversation);

        $id = (string) $conversation->getKey();

        DB::transaction(function () use ($conversation, $user, $id): void {
            $this->log->supersedeOpen($user, $id);
            ConversationMessage::query()->where('conversation_id', $id)->delete();
            $conversation->delete();
        });

        return response()->noContent();
    }

    private function page(User $user, ?Conversation $conversation): InertiaResponse
    {
        $id = $conversation?->getKey();
        $id = $id === null ? null : (string) $id;

        return Inertia::render('Orga/Assistant', [
            'conversations' => DB::table('agent_conversations')
                ->where('participant_type', Conversation::participantType($user))
                ->where('participant_id', Conversation::participantKey($user))
                ->orderByDesc('updated_at')->limit(50)->get(['id', 'title', 'updated_at'])
                ->map(fn (object $c): array => ['id' => (string) $c->id, 'title' => (string) $c->title, 'updated_at' => Carbon::parse((string) $c->updated_at)->toIso8601String()])
                ->all(),
            'conversationId' => $id,
            'messages' => $id === null ? [] : $this->history->messages($id),
            'pending' => $id === null ? [] : $this->cards->forPage($user, $id),
            'provider' => ['name' => (string) config('assistant.provider')],
        ]);
    }

    private function streamResponse(Request $request, StreamableAgentResponse $streamed, string $conversationId): Response
    {
        $response = $streamed->usingVercelDataProtocol()->toResponse($request);
        $response->headers->set('X-Conversation-Id', $conversationId);
        // Server-sent events must not be buffered by a proxy (Traefik forwards event-streams unbuffered; this is the nginx-style hint).
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    /**
     * Ownership is checked against the policy directly, not through Gate: the
     * application's admin `Gate::before` would otherwise let an admin read
     * and decide on other people's conversations.
     */
    private function owns(User $user, string $ability, Conversation $conversation): void
    {
        abort_unless(app(AssistantConversationPolicy::class)->{$ability}($user, $conversation), 403);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
