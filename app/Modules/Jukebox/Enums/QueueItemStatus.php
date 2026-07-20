<?php

declare(strict_types=1);

namespace App\Modules\Jukebox\Enums;

enum QueueItemStatus: string
{
    case Queued = 'queued';
    case Playing = 'playing';
    case Played = 'played';
    case Skipped = 'skipped';
}
