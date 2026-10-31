<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Testing;

use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Domain\HostProbe;
use App\Modules\Hosts\Exceptions\RemoteExecutionException;
use App\Modules\Hosts\Models\RemoteHost;
use PHPUnit\Framework\Assert;

class FakeRemoteExecutor implements RemoteExecutor
{
    /** @var array<int, array{host_id: int, command: string}> */
    public array $commands = [];

    /** @var array<int, array{host_id: int, contents: string, remote_path: string}> */
    public array $uploads = [];

    /**
     * Queued {@see CommandResult}s keyed by a command substring, consulted
     * in insertion order by {@see run()}; falls back to a default
     * `CommandResult(0, '', '')` (success, no output) when nothing matches.
     *
     * @var array<string, CommandResult>
     */
    public array $results = [];

    /**
     * Local files streamed via {@see uploadFile()}, recorded with their bytes
     * (tests use small files) so assertions can compare content.
     *
     * @var array<int, array{host_id: int, local_path: string, contents: string, remote_path: string}>
     */
    public array $fileUploads = [];

    /** @var array<int, array{host_id: int, remote_path: string}> */
    public array $deletes = [];

    /** @var array<string, true> remote paths currently "on" the fake host, keyed by host id and path */
    public array $remoteFiles = [];

    /** When set, every SFTP file operation throws it (simulates an unreachable host). */
    public ?RemoteExecutionException $failWith = null;

    public HostProbe $nextProbe;

    public function __construct()
    {
        $this->nextProbe = new HostProbe(true, null, null);
    }

    public function queueResult(string $match, CommandResult $result): void
    {
        $this->results[$match] = $result;
    }

    public function run(RemoteHost $host, string $command): CommandResult
    {
        $this->commands[] = ['host_id' => $host->id, 'command' => $command];

        foreach ($this->results as $match => $result) {
            if (str_contains($command, $match)) {
                return $result;
            }
        }

        return new CommandResult(0, '', '');
    }

    public function upload(RemoteHost $host, string $contents, string $remotePath): void
    {
        $this->uploads[] = [
            'host_id' => $host->id,
            'contents' => $contents,
            'remote_path' => $remotePath,
        ];
    }

    public function probe(RemoteHost $host): HostProbe
    {
        return $this->nextProbe;
    }

    public function uploadFile(RemoteHost $host, string $localPath, string $remotePath): void
    {
        $this->failIfConfigured();

        $this->fileUploads[] = [
            'host_id' => $host->id,
            'local_path' => $localPath,
            'contents' => (string) file_get_contents($localPath),
            'remote_path' => $remotePath,
        ];
        $this->remoteFiles[$host->id.':'.$remotePath] = true;
    }

    public function deleteFile(RemoteHost $host, string $remotePath): void
    {
        $this->failIfConfigured();

        $this->deletes[] = ['host_id' => $host->id, 'remote_path' => $remotePath];
        unset($this->remoteFiles[$host->id.':'.$remotePath]);
    }

    public function fileExists(RemoteHost $host, string $remotePath): bool
    {
        $this->failIfConfigured();

        return isset($this->remoteFiles[$host->id.':'.$remotePath]);
    }

    public function assertFileUploaded(string $remotePath): void
    {
        $match = collect($this->fileUploads)->contains(
            fn (array $u) => $u['remote_path'] === $remotePath
        );

        Assert::assertTrue($match, "No file upload to [{$remotePath}] was recorded.");
    }

    private function failIfConfigured(): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }

    public function assertRan(string $commandSubstring): void
    {
        $match = collect($this->commands)->contains(
            fn (array $c) => str_contains($c['command'], $commandSubstring)
        );

        Assert::assertTrue($match, "No command matching [{$commandSubstring}] was run.");
    }

    public function assertUploaded(string $remotePath): void
    {
        $match = collect($this->uploads)->contains(
            fn (array $u) => $u['remote_path'] === $remotePath
        );

        Assert::assertTrue($match, "No upload to [{$remotePath}] was recorded.");
    }

    public function assertNothingRan(): void
    {
        Assert::assertEmpty($this->commands, 'Expected no commands to have been run.');
    }
}
