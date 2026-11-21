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

    /** @return list<array<string, mixed>> */
    public function messages(string $conversationId): array
    {
        $rows = ConversationMessage::query()->where('conversation_id', $conversationId)->orderBy('created_at')->orderBy('id')->get();

        return array_map(function (array $message): array {
            if (($message['role'] ?? null) === 'user') {
                $message['parts'] = array_map(function (array $part): array {
                    if (($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                        $part['text'] = (string) preg_replace(self::TIME_PREFIX_PATTERN, '', $part['text']);
                    }

                    return $part;
                }, $message['parts'] ?? []);
            }

            return $message;
        }, Vercel::toUiMessages($rows));
    }
}
