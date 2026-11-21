<?php

declare(strict_types=1);

namespace App\Modules\Files\Console;

use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\SharedFileHasher;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fills in the server-side SHA-256 of files that predate the column (or whose
 * store was unreadable when they were uploaded). Hashes are streamed, so the
 * command is safe for multi-GB ISOs and can be re-run: rows that already carry
 * a digest are skipped unless `--force`.
 *
 * `--dry-run` only lists what would be read, for a first look on the live
 * server; `--event` and `--limit` keep a run short.
 */
class HashSharedFilesCommand extends Command
{
    protected $signature = 'lanomat:files:hash
        {--event= : Only the files of this event (id or slug)}
        {--limit=100 : Maximum number of files to hash; 0 = no limit}
        {--force : Re-hash files that already have a sha256}
        {--dry-run : Only report what would be hashed, without reading or writing}';

    protected $description = 'Backfill the server-side SHA-256 of stored shared files';

    public function handle(SharedFileHasher $hasher): int
    {
        $eventId = null;
        $eventOption = $this->option('event');
        $eventQuery = is_string($eventOption) ? $eventOption : '';

        if ($eventQuery !== '') {
            $eventId = $this->eventId($eventQuery);

            if ($eventId === null) {
                $this->components->error('No event matches '.$eventQuery.'.');

                return self::FAILURE;
            }
        }

        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $query = SharedFile::query()
            ->when(! $force, fn (Builder $builder): Builder => $builder->whereNull('sha256'))
            ->when($eventId !== null, fn (Builder $builder): Builder => $builder->where('event_id', $eventId))
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $files = $query->get();

        if ($files->isEmpty()) {
            $this->components->info('Nothing to hash.');

            return self::SUCCESS;
        }

        $hashed = 0;
        $failed = 0;

        foreach ($files as $file) {
            if ($dryRun) {
                // No byte is read: only the queue is shown.
                $this->line(sprintf('#%d %s (%d bytes, %s)', $file->id, $file->original_name, $file->size_bytes, $file->disk));

                continue;
            }

            if ($hasher->hash($file, $force) === null) {
                $this->components->warn(sprintf('#%d %s could not be read; sha256 stays null.', $file->id, $file->original_name));
                $failed++;

                continue;
            }

            $hashed++;
        }

        if ($dryRun) {
            $this->components->info("{$files->count()} file(s) would be hashed.");

            return self::SUCCESS;
        }

        $this->components->info("Hashed {$hashed} file(s), {$failed} could not be read.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function eventId(string $event): ?int
    {
        $id = Event::query()
            ->when(
                ctype_digit($event),
                fn (Builder $builder): Builder => $builder->whereKey((int) $event),
                fn (Builder $builder): Builder => $builder->where('slug', $event),
            )
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
