<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use App\Modules\Files\Contracts\FileStore;
use App\Modules\Files\Exceptions\FileException;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Exceptions\RemoteExecutionException;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Files live only on the LanCache host: uploads go over SFTP through a managed
 * {@see RemoteHost} (host-key pinning included), downloads are a redirect to a
 * short-lived signed URL on the nginx file host. The app never streams the
 * bytes and a download needs no remote call at all (nginx answers 404 itself
 * when a file is gone).
 */
final class RemoteHostFileStore implements FileStore
{
    public const KEY = 'filehost';

    public function __construct(
        private readonly RemoteExecutor $remote,
        private readonly int $hostId,
        private readonly string $directory,
        private readonly FileUrlSigner $signer,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function put(string $localPath, string $path): void
    {
        $this->guarded(fn () => $this->remote->uploadFile($this->host(), $localPath, $this->remotePath($path)));
    }

    public function delete(string $path): void
    {
        $this->guarded(fn () => $this->remote->deleteFile($this->host(), $this->remotePath($path)));
    }

    public function exists(string $path): bool
    {
        return $this->guarded(fn (): bool => $this->remote->fileExists($this->host(), $this->remotePath($path)));
    }

    /**
     * The file host serves the bytes over HTTPS; the app streams that response
     * (never buffering it) through the same short-lived signed URL a download
     * uses. Hashing is the only caller in normal operation.
     */
    public function readStream(string $path)
    {
        $url = $this->signer->url($path, basename($path));

        try {
            $response = Http::withOptions([
                'stream' => true,
                // A 50 GB file may take a while: bound the connect, not the transfer.
                'connect_timeout' => 15,
                'timeout' => 0,
            ])->get($url);
        } catch (Throwable $exception) {
            // The signed URL is a credential; never log the exception message (it contains it).
            Log::warning('files: the file host could not be reached for reading.', ['reason' => $exception::class]);

            throw FileException::storeUnavailable();
        }

        if (! $response->successful()) {
            Log::warning('files: the file host answered a read with an error.', ['status' => $response->status()]);

            throw FileException::storeUnavailable();
        }

        $stream = $response->toPsrResponse()->getBody()->detach();

        if (! is_resource($stream)) {
            throw FileException::unreadable();
        }

        return $stream;
    }

    public function download(string $path, string $name): Response
    {
        $response = new RedirectResponse($this->signer->url($path, $name));
        // The link is a short-lived credential: never cache it, never leak it in a Referer.
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function remotePath(string $path): string
    {
        return rtrim($this->directory, '/').'/'.ltrim($path, '/');
    }

    private function host(): RemoteHost
    {
        $host = RemoteHost::query()->find($this->hostId);

        if ($host === null) {
            Log::error('files: the configured file host is not a registered remote host.', ['host_id' => $this->hostId]);

            throw FileException::storeUnavailable();
        }

        return $host;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function guarded(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (RemoteExecutionException $exception) {
            // The exception message is generic by design (no key, no stdout); safe to log.
            Log::warning('files: the file host could not be reached.', ['reason' => $exception->getMessage()]);

            throw FileException::storeUnavailable();
        }
    }
}
