<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Jobs;

use App\Modules\Tournaments\Models\Tournament;
use App\Modules\WebVoice\Actions\ProvisionTeamChannels;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionTeamChannelsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $tournamentId) {}

    public function handle(ProvisionTeamChannels $provision): void
    {
        $tournament = Tournament::query()->find($this->tournamentId);

        if ($tournament !== null) {
            $provision->handle($tournament);
        }
    }
}
