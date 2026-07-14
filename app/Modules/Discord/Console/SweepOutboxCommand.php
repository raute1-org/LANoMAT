<?php

namespace App\Modules\Discord\Console;

use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Models\DiscordOutbox;
use Illuminate\Console\Command;
use Throwable;

class SweepOutboxCommand extends Command
{
    protected $signature = 'lanomat:sweep-discord-outbox';

    protected $description = 'Retry Discord outbox rows that were enqueued but never confirmed sent (sent_at IS NULL), older than 5 minutes.';

    public function handle(DiscordClient $client): int
    {
        $stale = DiscordOutbox::query()
            ->whereNull('sent_at')
            ->whereNotNull('channel_id')
            ->where('created_at', '<=', now()->subMinutes(5))
            ->get();

        foreach ($stale as $row) {
            try {
                $client->sendMessage((string) $row->channel_id, (string) $row->content);

                $row->update(['sent_at' => now()]);
            } catch (Throwable $e) {
                // One row failing (e.g. Discord still unavailable) must not
                // abort the sweep for the remaining rows — report and move on.
                $this->components->error("Failed to resend outbox row #{$row->id} ({$row->dedup_key}): {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
