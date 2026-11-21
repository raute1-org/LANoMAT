<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use App\Modules\Files\Exceptions\FileException;
use App\Modules\Files\Models\SharedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The server-side SHA-256 of a shared file's bytes.
 *
 * Bytes are streamed in chunks through {@see hash_update_stream()}, so a
 * multi-GB ISO never lands in memory. Hashing is a convenience, never a
 * precondition for keeping a file: an unreadable store, a missing file or a
 * size mismatch leaves `sha256 = null` (logged, hashed later by
 * `lanomat:files:hash`) instead of failing an upload.
 */
final class SharedFileHasher
{
    public function __construct(private readonly FileStores $stores) {}

    /**
     * Hashes the stored bytes of `$file` and persists the digest. An existing
     * digest is kept unless `$force`: re-reading a 50 GB ISO is expensive and
     * only the backfill command (or an explicit verification) needs it.
     */
    public function hash(SharedFile $file, bool $force = false): ?string
    {
        if (! $force && $file->sha256 !== null) {
            return $file->sha256;
        }

        $digest = $this->digest($file);

        if ($digest !== null) {
            $file->forceFill(['sha256' => $digest])->save();
        }

        return $digest;
    }

    /**
     * The SHA-256 of the stored bytes, or null when the store cannot read
     * them right now. Never persists.
     */
    public function digest(SharedFile $file): ?string
    {
        $stream = null;

        try {
            $stream = $this->stores->for($file->disk)->readStream($file->path);

            if (! is_resource($stream)) {
                throw FileException::unreadable();
            }

            $digest = $this->digestStream($stream, $file->size_bytes);

            if ($digest === null) {
                Log::warning('files: the stored bytes did not hash to the recorded size.', [
                    'shared_file_id' => $file->getKey(),
                    'disk' => $file->disk,
                ]);
            }

            return $digest;
        } catch (Throwable $exception) {
            // The message never carries a path or a secret: only the reason class is logged.
            Log::warning('files: the stored bytes could not be hashed.', [
                'shared_file_id' => $file->getKey(),
                'disk' => $file->disk,
                'reason' => $exception::class,
            ]);

            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * The SHA-256 of a local path, for bytes the app already holds (the
     * uploaded temp file, before the row is written). Streamed, never loaded.
     */
    public function digestOfPath(string $localPath): ?string
    {
        $stream = @fopen($localPath, 'rb');

        if ($stream === false) {
            Log::warning('files: an upload could not be opened for hashing.');

            return null;
        }

        try {
            $size = fstat($stream)['size'] ?? 0;

            return $this->digestStream($stream, $size);
        } catch (Throwable $exception) {
            Log::warning('files: an upload could not be hashed.', ['reason' => $exception::class]);

            return null;
        } finally {
            fclose($stream);
        }
    }

    /**
     * A short read (or a file whose bytes no longer match the recorded size)
     * yields null rather than a hash of half a file.
     *
     * @param  resource  $stream
     */
    private function digestStream($stream, int $expectedBytes): ?string
    {
        $context = hash_init('sha256');
        $hashedBytes = hash_update_stream($context, $stream);

        if ($expectedBytes > 0 && $hashedBytes !== $expectedBytes) {
            return null;
        }

        return hash_final($context);
    }
}
