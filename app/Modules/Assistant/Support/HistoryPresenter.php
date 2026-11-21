<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use Laravel\Ai\Models\ConversationMessage;
use Laravel\Ai\Vercel\Vercel;

/**
 * A conversation as Vercel UI messages for the page. The per-turn time prefix
 * the controller adds to each prompt is not part of what the orga wrote, so it
 * is cut from user text again.
 */
final class HistoryPresenter
{
    public const string TIME_PREFIX_PATTERN = '/^\[Jetzt: [^\]]*\]\s*/u';

    /**
     * @param  list<string>  $pendingCallIds  Calls that really still wait for a decision. A call the SDK settled
     *                                        as "not executed" (a newer message superseded its card) has no stored
     *                                        result and would otherwise read as waiting forever.
     * @return list<array<string, mixed>>
     */
    public function messages(string $conversationId, array $pendingCallIds = []): array
    {
        $rows = ConversationMessage::query()->where('conversation_id', $conversationId)->orderBy('created_at')->orderBy('id')->get();

        return array_map(function (array $message) use ($pendingCallIds): array {
            $message['parts'] = array_map(function (array $part) use ($pendingCallIds): array {
                if (($part['state'] ?? null) === 'approval-requested' && ! in_array($part['toolCallId'] ?? null, $pendingCallIds, true)) {
                    $part['state'] = 'output-denied';
                    unset($part['approval']);
                }

                return $part;
            }, $message['parts']);

            if (($message['role'] ?? null) === 'user') {
                $message['parts'] = array_map(function (array $part): array {
                    if (($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                        $part['text'] = (string) preg_replace(self::TIME_PREFIX_PATTERN, '', $part['text']);
                    }

                    return $part;
                }, $message['parts']);
            }

            return $message;
        }, Vercel::toUiMessages($rows));
    }
}
