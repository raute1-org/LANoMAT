<?php

declare(strict_types=1);

namespace App\Modules\Files\Support;

use App\Modules\Files\Contracts\FileStore;
use App\Modules\Files\Exceptions\FileException;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Support\Facades\Log;

/**
 * Resolves the {@see FileStore} for a `shared_files.disk` value, and the
 * default store new uploads go to (`FILES_STORE=local|host`).
 *
 * Existing rows keep working across a switch: `disk = 'local'` (or any other
 * Laravel disk name) is a {@see LocalFileStore}, `disk = 'filehost'` is the
 * {@see RemoteHostFileStore}. The file-host store is built lazily, so a
 * misconfiguration only fails file operations (clear German error, logged)
 * and never takes the whole app down at boot.
 */
class FileStores
{
    /** @var array<string, FileStore> */
    private array $overrides = [];

    public function __construct(private readonly Modules $modules) {}

    /** Registers a store for a key, ahead of the built-in resolution (tests, alternative stores). */
    public function extend(FileStore $store): void
    {
        $this->overrides[$store->key()] = $store;
    }

    public function default(): FileStore
    {
        $mode = config('files.store');

        return $this->for($mode === 'host' ? RemoteHostFileStore::KEY : 'local');
    }

    public function for(string $key): FileStore
    {
        if (isset($this->overrides[$key])) {
            return $this->overrides[$key];
        }

        if ($key !== RemoteHostFileStore::KEY) {
            return new LocalFileStore($key);
        }

        return $this->remoteHostStore();
    }

    private function remoteHostStore(): RemoteHostFileStore
    {
        $hostId = (int) config('files.host.id');
        $url = (string) config('files.host.url');
        $secret = (string) config('files.host.secret');
        $path = (string) config('files.host.path');

        if (! $this->modules->enabled(Module::Hosts)) {
            $this->misconfigured('the Hosts module is disabled');
        }

        if ($hostId <= 0 || $path === '' || ! str_starts_with($url, 'http')) {
            $this->misconfigured('FILES_HOST_ID, FILES_HOST_PATH and FILES_HOST_URL must be set');
        }

        if (! FileUrlSigner::isAcceptableSecret($secret)) {
            $this->misconfigured('FILES_HOST_SECRET must be at least 32 characters of [A-Za-z0-9_-]');
        }

        return new RemoteHostFileStore(
            app(RemoteExecutor::class),
            $hostId,
            $path,
            new FileUrlSigner($url, $secret, max(30, (int) config('files.host.url_ttl_seconds'))),
        );
    }

    private function misconfigured(string $problem): never
    {
        // Names the setting, never its value.
        Log::error('files: the file host store is misconfigured: '.$problem.'.');

        throw FileException::storeUnavailable();
    }
}
