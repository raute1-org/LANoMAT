<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Contracts;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\WebVoice\Domain\NowCard;

/**
 * Feeds the native client's "now" list with the next schedule item. The owning module implements this contract;
 * while that module is switched off a null implementation (\App\Modules\WebVoice\NativeClient\Support\Now\NullScheduleCardSource)
 * is bound instead, so the feed never reaches into an optional module.
 */
interface ScheduleCardSource
{
    /**
     * @return list<NowCard>
     */
    public function cards(User $user, Event $event): array;
}
