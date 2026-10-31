<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use App\Modules\Files\Contracts\FileStore;
use App\Modules\Files\Exceptions\FileException;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A Laravel Storage disk, streamed by the app itself. The behaviour every
 * install had before the file host existed, and still the default.
 */
final class LocalFileStore implements FileStore
{
    public function __construct(private readonly string $disk = 'local') {}

    public function key(): string
    {
        return $this->disk;
    }

    public function put(string $localPath, string $path): void
    {
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw FileException::unreadable();
        }

        try {
            if (Storage::disk($this->disk)->writeStream($path, $stream) === false) {
                throw FileException::storeUnavailable();
            }
        } catch (FileException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw FileException::storeUnavailable();
        } finally {
            fclose($stream);
        }
    }

    public function delete(string $path): void
    {
        try {
            Storage::disk($this->disk)->delete($path);
        } catch (Throwable) {
            throw FileException::storeUnavailable();
        }
    }

    public function exists(string $path): bool
    {
        return Storage::disk($this->disk)->exists($path);
    }

    public function download(string $path, string $name): Response
    {
        // A row whose file is gone from the disk is a missing resource, not a server error.
        abort_unless($this->exists($path), 404);

        // Always an opaque attachment: never rendered from our origin, never sniffed.
        return Storage::disk($this->disk)->download($path, $name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
