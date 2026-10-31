<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Console;

use App\Modules\WebVoice\Domain\ChannelKind;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use Illuminate\Console\Command;

/**
 * Deletes archived match channels, with their messages (cascade), once they are older than
 * `webvoice.lifecycle.prune_after_days`. Archived general channels are kept: an admin may restore
 * them. Chat in a match room is not meant to outlive the match for long.
 */
class PruneArchivedCommand extends Command
{
    protected $signature = 'webvoice:prune';

    protected $description = 'Delete archived web voice match channels and their chat history after the retention period';

    public function handle(): int
    {
        $deleted = WebVoiceChannel::query()
            ->where('kind', ChannelKind::Match->value)
            ->whereNotNull('archived_at')
            ->where('archived_at', '<', now()->subDays((int) config('webvoice.lifecycle.prune_after_days')))
            ->delete();

        $this->info("Pruned {$deleted} archived match channel(s).");

        return self::SUCCESS;
    }
}
