<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Models\User;
use App\Modules\Assistant\Enums\AssistantActionStatus;
use App\Modules\Assistant\Tools\ApprovableTool;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;

/**
 * The open approval cards of a conversation. The source of truth is the SDK's
 * stored pause of the newest turn; the audit row is created from it on first
 * sight, so the stream event, the page load and the decision endpoint agree.
 */
final class PendingCards
{
    public function __construct(private readonly AssistantActionLog $log) {}

    /** @return list<PendingApproval> */
    public function approvals(string $conversationId): array
    {
        return resolve(ConversationStore::class)->pendingApprovalsFor($conversationId);
    }

    /**
     * Cards for the page: server-built previews of the stored arguments.
     *
     * @return list<array{callId: string, tool: string, preview: array<string, mixed>, status: string, decidable: bool}>
     */
    public function forPage(User $user, string $conversationId): array
    {
        $cards = [];

        foreach ($this->approvals($conversationId) as $approval) {
            $row = $this->log->propose($user, $conversationId, $approval->id, $approval->tool, $approval->arguments);
            $tool = $this->toolFor($user, $approval->tool);

            $cards[] = [
                'callId' => $approval->id,
                'tool' => $approval->tool,
                'preview' => ($tool?->safePreview($approval->arguments) ?? new ApprovalPreview($approval->tool))->toArray(),
                'status' => $row->status->value,
                'decidable' => $tool !== null && $row->status === AssistantActionStatus::Proposed,
            ];
        }

        return $cards;
    }

    public function toolFor(User $user, string $name): ?ApprovableTool
    {
        foreach (ToolRegistry::forUser($user) as $tool) {
            if ($tool instanceof ApprovableTool && $tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }
}
