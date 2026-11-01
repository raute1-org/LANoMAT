<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Support\Now;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\WebVoice\Contracts\OrderCardSource;

/** Bound while the owning module is disabled: contributes nothing to the feed. */
final class NullOrderCardSource implements OrderCardSource
{
    public function cards(User $user, Event $event): array
    {
        return [];
    }
}
