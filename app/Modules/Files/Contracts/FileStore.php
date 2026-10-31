<?php

declare(strict_types=1);

namespace App\Modules\Files\Contracts;

use App\Modules\Files\Exceptions\FileException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where the bytes of a shared file live. The Files module only ever talks to
 * storage through this contract: `LocalFileStore` (a Laravel disk, streamed by
 * the app), `RemoteHostFileStore` (SFTP to the LanCache host, downloads
 * redirected to a signed URL there) and `FakeFileStore` for tests.
 *
 * `key()` is what `shared_files.disk` stores, so every row remembers which
 * store holds its file and keeps working after the configured default changes.
 */
interface FileStore
{
    public function key(): string;

    /**
     * Stores the local file under `$path` (relative, e.g. `event-3/<uuid>`).
     * The caller owns `$localPath`; it is read, never moved or deleted.
     *
     * @throws FileException when the store cannot take the file
     */
    public function put(string $localPath, string $path): void;

    /**
     * Idempotent: a file that is already gone is not an error.
     *
     * @throws FileException when the store cannot be reached
     */
    public function delete(string $path): void;

    /**
     * @throws FileException when the store cannot be reached
     */
    public function exists(string $path): bool;

    /**
     * The HTTP response that hands the file to the downloader: an attachment
     * stream (local) or a redirect to a short-lived signed URL (file host).
     * Called only after the policy allowed the download.
     *
     * @throws FileException when the store cannot serve right now
     */
    public function download(string $path, string $name): Response;
}
