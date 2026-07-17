<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Testing;

use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Domain\CommandResult;
use App\Modules\Hosts\Domain\HostProbe;
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
