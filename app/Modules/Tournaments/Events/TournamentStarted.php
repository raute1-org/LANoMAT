<?php

namespace App\Modules\Tournaments\Events;

use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Foundation\Events\Dispatchable;

class TournamentStarted
{
    use Dispatchable;

    public function __construct(
        public readonly Tournament $tournament,
    ) {}
}
