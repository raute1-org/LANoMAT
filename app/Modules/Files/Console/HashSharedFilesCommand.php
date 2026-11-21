<?php

declare(strict_types=1);

namespace App\Modules\Files\Console;

use App\Modules\Events\Models\Event;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Support\SharedFileHasher;
use Illuminate\Console\Command;
use Illuminate\Console\View\TaskResult;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

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

        $hashed = 0;
        $failed = 0;
        $listed = 0;

        // `--limit` caps what the run actually hashes, not the rows it looks
        // at: an unreadable file therefore never keeps later files out of the
        // window (the starvation a SQL LIMIT would cause).
        $query->chunkById(100, function ($files) use ($hasher, $force, $dryRun, $limit, &$hashed, &$failed, &$listed): bool {
            foreach ($files as $file) {
                if ($limit > 0 && ($dryRun ? $listed : $hashed) >= $limit) {
                    return false;
                }

                if ($dryRun) {
                    // No byte is read: only the queue is shown.
                    $this->line(sprintf('#%d %s (%d bytes, %s)', $file->id, $file->original_name, $file->size_bytes, $file->disk));
                    $listed++;

                    continue;
                }

                $hashedOk = false;

                $this->components->task(
                    sprintf('#%d %s', $file->id, $file->original_name),
                    function () use ($hasher, $file, $force, &$hashedOk): int {
                        try {
                            $hashedOk = $hasher->hash($file, $force) !== null;
                        } catch (Throwable $exception) {
                            // One unreadable row or a transient save error must
                            // not abort a long backfill and lose the progress.
                            report($exception);
                        }

                        return $hashedOk ? TaskResult::Success->value : TaskResult::Failure->value;
                    },
                );

                if (! $hashedOk) {
                    $failed++;

                    continue;
                }

                $hashed++;
            }

            return ! ($limit > 0 && ($dryRun ? $listed : $hashed) >= $limit);
        });

        if ($dryRun) {
            $this->components->info("{$listed} file(s) would be hashed.");

            return self::SUCCESS;
        }

        if ($hashed === 0 && $failed === 0) {
            $this->components->info('Nothing to hash.');

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
