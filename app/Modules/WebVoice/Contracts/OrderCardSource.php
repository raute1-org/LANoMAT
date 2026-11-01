<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Contracts;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\WebVoice\Domain\NowCard;

/**
 * Feeds the native client's "now" list with an ordering window that closes soon. The owning module implements this contract;
 * while that module is switched off a null implementation (\App\Modules\WebVoice\NativeClient\Support\Now\NullOrderCardSource)
 * is bound instead, so the feed never reaches into an optional module.
 */
interface OrderCardSource
{
    /**
     * @return list<NowCard>
     */
    public function cards(User $user, Event $event): array;
}
