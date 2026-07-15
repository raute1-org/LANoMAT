<?php

namespace App\Modules\Lfg\Console;

use App\Modules\Lfg\Models\LfgPost;
use Illuminate\Console\Command;

class PruneExpiredLfgPostsCommand extends Command
{
    protected $signature = 'lanomat:prune-lfg';

    protected $description = 'Delete LFG posts whose expires_at has passed.';

    public function handle(): int
    {
        $count = LfgPost::query()
            ->where('expires_at', '<=', now())
            ->delete();

        $this->components->info("Pruned {$count} expired LFG post(s).");

        return self::SUCCESS;
    }
}
