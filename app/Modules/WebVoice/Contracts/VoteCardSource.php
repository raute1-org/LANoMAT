<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Contracts;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\WebVoice\Domain\NowCard;

/**
 * Feeds the native client's "now" list with an open poll the user has not voted in. The owning module implements this contract;
 * while that module is switched off a null implementation (\App\Modules\WebVoice\NativeClient\Support\Now\NullVoteCardSource)
 * is bound instead, so the feed never reaches into an optional module.
 */
interface VoteCardSource
{
    /**
     * @return list<NowCard>
     */
    public function cards(User $user, Event $event): array;
}
