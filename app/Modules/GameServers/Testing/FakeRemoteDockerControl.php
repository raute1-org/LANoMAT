<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Testing;

use App\Modules\GameServers\Contracts\RemoteDockerControl;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ConsoleKind;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Support\RemoteDockerGuard;
use App\Modules\Hosts\Models\RemoteHost;

/** In-memory {@see RemoteDockerControl} for tests: records calls, answers from what the test sets. */
final class FakeRemoteDockerControl implements RemoteDockerControl
{
    /** @var list<array{host_id: int, container: string, action: string}> */
    public array $powered = [];

    /** @var list<array{host_id: int, container: string, console: string, command: string}> */
    public array $commands = [];

    /** @var list<array{host_id: int, container: string, lines: int}> */
    public array $logReads = [];

    public ?string $state = 'running';

    public bool $containerExists = true;

    public string $commandOutput = 'There are 0 of a max of 20 players online';

    public string $logOutput = "[Server] Done\n";

    public ?GameServerException $failWith = null;

    public function power(RemoteHost $host, string $container, PowerAction $action): void
    {
        $this->failIfConfigured();
        $this->powered[] = ['host_id' => $host->id, 'container' => RemoteDockerGuard::container($container), 'action' => $action->value];

        $this->state = match ($action) {
            PowerAction::Stop => 'exited',
            default => 'running',
        };
    }

    public function status(RemoteHost $host, string $container): ?string
    {
        return $this->failWith === null ? $this->state : null;
    }

    public function exists(RemoteHost $host, string $container): bool
    {
        $this->failIfConfigured();

        return $this->containerExists;
    }

    public function command(RemoteHost $host, string $container, ConsoleKind $console, string $command): string
    {
        $this->failIfConfigured();
        $this->commands[] = [
            'host_id' => $host->id,
            'container' => RemoteDockerGuard::container($container),
            'console' => $console->value,
            'command' => RemoteDockerGuard::command($command),
        ];

        return $this->commandOutput;
    }

    public function logs(RemoteHost $host, string $container, int $lines): string
    {
        $this->failIfConfigured();
        $this->logReads[] = ['host_id' => $host->id, 'container' => RemoteDockerGuard::container($container), 'lines' => RemoteDockerGuard::lines($lines)];

        return $this->logOutput;
    }

    private function failIfConfigured(): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}
