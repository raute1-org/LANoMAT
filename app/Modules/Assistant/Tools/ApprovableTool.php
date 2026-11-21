<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools;

use App\Modules\Assistant\Models\AssistantAction;
use App\Modules\Assistant\Support\ApprovalPreview;
use App\Modules\Assistant\Support\ArgumentsHash;
use App\Modules\Assistant\Support\AssistantActionLog;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * A write tool. The SDK pauses before `handle()` and the orga decides on a
 * preview card; `handle()` then runs only against an approved audit row:
 *
 * - `needsApproval()` is pure and never throws (the SDK calls it outside the
 *   tool's error mapping). Arguments that cannot work (unknown id, invalid
 *   value, not allowed) skip the card: `handle()` then answers with the
 *   reason and writes nothing.
 * - `handle()` is fail-closed: no `Approved` row for this actor, tool and call
 *   id whose arguments hash equals the live arguments, no execution. The row
 *   is claimed atomically (status executed) before the work starts, so a call
 *   can never run twice, and a failure is logged as `failed`.
 * - `execute()` re-authorizes through the Policies against the actor again.
 */
abstract class ApprovableTool extends AssistantTool implements Approvable
{
    use InteractsWithApprovals;

    /**
     * Cheap validation before a card is shown: returns a readable reason when
     * the call can never succeed, null when a card makes sense. May throw
     * (mapped to an answer in `handle()`).
     *
     * @param  array<string, mixed>  $arguments
     */
    abstract protected function precheck(array $arguments): ?string;

    /**
     * The card content, from the stored arguments only.
     *
     * @param  array<string, mixed>  $arguments
     */
    abstract public function preview(array $arguments): ApprovalPreview;

    /**
     * The work. Receives the arguments exactly as stored and the card-entered
     * secret (null unless {@see needsSecret()}).
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|string
     */
    abstract protected function execute(array $arguments, ?string $secret): array|string;

    /** @param  array<string, mixed>  $arguments */
    public function needsSecret(array $arguments): bool
    {
        return false;
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        try {
            if (! $this->actor->isOrga() || $this->precheck($request->all()) !== null) {
                return false;
            }

            return Approval::required($this->preview($request->all())->title);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The preview for an already stored call; never throws (a vanished
     * record still yields a card that shows the raw arguments).
     *
     * @param  array<string, mixed>  $arguments
     */
    public function safePreview(array $arguments): ApprovalPreview
    {
        try {
            return $this->preview($arguments);
        } catch (Throwable) {
            return new ApprovalPreview(
                static::toolName(),
                array_map(
                    fn (string $key, mixed $value): array => ApprovalPreview::row($key, is_scalar($value) ? $value : json_encode($value), true),
                    array_keys(AssistantActionLog::mask($arguments)),
                    array_values(AssistantActionLog::mask($arguments)),
                ),
            );
        }
    }

    /** @return array<string, mixed>|string */
    final protected function run(Request $request): array|string
    {
        $arguments = $request->all();

        if (($reason = $this->precheck($arguments)) !== null) {
            return $reason;
        }

        $action = $this->approvedRow($request->toolCallId(), $arguments);
        $claimed = $action === null ? null : app(AssistantActionLog::class)->claim($action);

        if ($claimed === null) {
            return __('assistant.not_approved');
        }

        [$action, $secret] = $claimed;
        $log = app(AssistantActionLog::class);

        try {
            $result = $this->execute($arguments, $secret);
        } catch (AuthorizationException $exception) {
            $log->failed($action, 'Forbidden');
            throw $exception;
        } catch (Throwable $exception) {
            $log->failed($action, $this->redact($exception->getMessage(), $secret));
            throw $exception;
        }

        $log->executed($action, $this->redact(is_string($result) ? $result : (string) json_encode($result, JSON_UNESCAPED_UNICODE), $secret));

        return $result;
    }

    /** A card-entered secret must not leak into the audit text, whatever an exception echoes. */
    private function redact(string $text, ?string $secret): string
    {
        return $secret === null || $secret === '' ? $text : str_replace($secret, '***', $text);
    }

    /** @param  array<string, mixed>  $arguments */
    private function approvedRow(?string $callId, array $arguments): ?AssistantAction
    {
        if ($callId === null) {
            return null;
        }

        return AssistantAction::query()
            ->where('user_id', $this->actor->id)
            ->where('tool', static::toolName())
            ->where('tool_call_id', $callId)
            ->where('status', 'approved')
            ->where('arguments_hash', ArgumentsHash::of(static::toolName(), $arguments))
            ->latest('id')
            ->first();
    }
}
