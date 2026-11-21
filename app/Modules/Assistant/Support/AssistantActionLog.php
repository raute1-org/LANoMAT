<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Models\User;
use App\Modules\Assistant\Enums\AssistantActionStatus;
use App\Modules\Assistant\Models\AssistantAction;
use App\Support\SafeText;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of `assistant_actions`. Moves a row through
 * proposed -> approved|rejected|superseded -> executed|failed and guards
 * each step (a row is decided once, executed once, and a superseded card
 * can never run).
 */
final class AssistantActionLog
{
    private const SECRET_KEY = '/pass(word|wd)?|secret|token|api[_-]?key/i';

    /**
     * Records a pending card. Idempotent per conversation and call id, so the
     * stream event, the page load and the decision endpoint may all call it.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function propose(User $user, string $conversationId, ?string $callId, string $tool, array $arguments): AssistantAction
    {
        $hash = ArgumentsHash::of($tool, $arguments);

        if ($callId !== null) {
            // Same call id and same arguments is the same card. A provider that recycles an id for different
            // arguments (some local models do) gets its own row, never the old, possibly closed one.
            $existing = AssistantAction::query()
                ->where('conversation_id', $conversationId)->where('tool_call_id', $callId)
                ->where('user_id', $user->id)->where('tool', $tool)->where('arguments_hash', $hash)
                ->latest('id')->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $action = new AssistantAction([
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'tool_call_id' => $callId,
            'tool' => $tool,
            'arguments' => self::mask($arguments),
            'arguments_hash' => $hash,
        ]);
        $action->forceFill(['status' => AssistantActionStatus::Proposed])->save();

        return $action;
    }

    /**
     * A new chat message ends every card of the conversation that has not run
     * yet (proposed or approved-but-not-executed). Their secrets are dropped.
     */
    public function supersedeOpen(User $user, string $conversationId): int
    {
        return AssistantAction::query()
            ->where('user_id', $user->id)->where('conversation_id', $conversationId)
            ->whereIn('status', [AssistantActionStatus::Proposed->value, AssistantActionStatus::Approved->value])
            ->update(['status' => AssistantActionStatus::Superseded->value, 'secret' => null, 'updated_at' => now()]);
    }

    /**
     * Approvals that were not executed within a couple of minutes (the run died, the client vanished)
     * are disarmed so a card-entered secret never lingers.
     */
    public function disarmStale(User $user): int
    {
        return AssistantAction::query()
            ->where('user_id', $user->id)->where('status', AssistantActionStatus::Approved->value)
            ->where('updated_at', '<', now()->subMinutes(2))
            ->update(['status' => AssistantActionStatus::Superseded->value, 'secret' => null, 'updated_at' => now()]);
    }

    /**
     * After a resume: cards that were approved but did not run (the run ended
     * early) must not stay armed. Only the given calls, only `approved`: a new
     * card the same run proposed is left alone.
     *
     * @param  list<string>  $callIds
     */
    public function disarmApproved(User $user, string $conversationId, array $callIds): int
    {
        return AssistantAction::query()
            ->where('user_id', $user->id)->where('conversation_id', $conversationId)
            ->whereIn('tool_call_id', $callIds)->where('status', AssistantActionStatus::Approved->value)
            ->update(['status' => AssistantActionStatus::Superseded->value, 'secret' => null, 'updated_at' => now()]);
    }

    /**
     * @throws DomainException unless the card is still proposed
     */
    public function decide(AssistantAction $action, bool $approve, ?string $secret = null): void
    {
        DB::transaction(function () use ($action, $approve, $secret): void {
            $locked = AssistantAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw new DomainException('This card is no longer open.');
            }

            $locked->forceFill([
                'status' => $approve ? AssistantActionStatus::Approved : AssistantActionStatus::Rejected,
                'secret' => $approve && $secret !== null && $secret !== '' ? $secret : null,
            ])->save();
        });

        $action->refresh();
    }

    /**
     * Atomically takes an approved card for execution (so it can run at most
     * once) and returns its card-entered secret, which is cleared right here.
     * Null when the card is not approved (fail closed).
     *
     * @return array{0: AssistantAction, 1: string|null}|null
     */
    public function claim(AssistantAction $action): ?array
    {
        return DB::transaction(function () use ($action): ?array {
            $locked = AssistantAction::query()->whereKey($action->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== AssistantActionStatus::Approved) {
                return null;
            }

            $secret = $locked->secret;
            $locked->forceFill(['status' => AssistantActionStatus::Executed, 'secret' => null])->save();

            return [$locked, $secret];
        });
    }

    public function executed(AssistantAction $action, string $summary): void
    {
        $action->forceFill(['status' => AssistantActionStatus::Executed, 'result_summary' => SafeText::line($summary, 500)])->save();
    }

    public function failed(AssistantAction $action, string $error): void
    {
        // A card-entered secret never outlives the run, whatever way it ended.
        $action->forceFill(['status' => AssistantActionStatus::Failed, 'error' => SafeText::line($error, 500), 'secret' => null])->save();
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     * @return array<array-key, mixed>
     */
    public static function mask(array $arguments): array
    {
        foreach ($arguments as $key => $value) {
            if (is_array($value)) {
                $arguments[$key] = self::mask($value);
            } elseif (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1 && $key !== 'password_action') {
                $arguments[$key] = '***';
            }
        }

        return $arguments;
    }
}
