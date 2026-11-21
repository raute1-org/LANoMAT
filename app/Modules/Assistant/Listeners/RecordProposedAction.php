<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Listeners;

use App\Models\User;
use App\Modules\Assistant\Agents\LanomatAssistant;
use App\Modules\Assistant\Support\AssistantActionLog;
use Laravel\Ai\Events\ToolApprovalRequested;

/** Logs every pause of the assistant as a `proposed` audit row (idempotent per call id). */
final class RecordProposedAction
{
    public function __construct(private readonly AssistantActionLog $log) {}

    public function handle(ToolApprovalRequested $event): void
    {
        if (! $event->agent instanceof LanomatAssistant || ! $event->conversationUser instanceof User || $event->conversationId === null) {
            return;
        }

        foreach ($event->pendingApprovals as $approval) {
            $this->log->propose($event->conversationUser, $event->conversationId, $approval->id, $approval->tool, $approval->arguments);
        }
    }
}
