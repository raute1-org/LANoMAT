<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Jobs;

use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\WebVoice\Actions\ProvisionMatchChannels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionMatchChannelsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $matchId) {}

    public function handle(ProvisionMatchChannels $provision): void
    {
        $match = GameMatch::query()->find($this->matchId);

        if ($match !== null) {
            $provision->handle($match);
        }
    }
}
