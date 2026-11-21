<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Policies;

use App\Models\User;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;

/**
 * Conversations belong to the user they were started for. The SDK's
 * `continue()` does not check that, so every page, chat, decision and delete
 * goes through here, backed by the store's own ownership lookup.
 */
final class AssistantConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function continue(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    private function owns(User $user, Conversation $conversation): bool
    {
        if (! $user->isOrga()) {
            return false;
        }

        // The configured store (database by default) answers the ownership question itself.
        return resolve(ConversationStore::class)->conversationBelongsTo(
            (string) $conversation->getKey(),
            Conversation::participantType($user),
            Conversation::participantKey($user),
        );
    }
}
