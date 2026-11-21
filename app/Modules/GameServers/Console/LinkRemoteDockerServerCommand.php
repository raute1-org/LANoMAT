<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Console;

use App\Modules\GameServers\Actions\LinkRemoteDockerServer;
use App\Modules\GameServers\Enums\ConsoleKind;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Hosts\Models\RemoteHost;
use Illuminate\Console\Command;

/**
 * One-off: adopts an external permanent server that runs as a Docker container on a managed
 * remote host into LANoMAT's control (power, console, logs), see {@see LinkRemoteDockerServer}.
 * Prints ids and names only, never a key or a credential.
 */
class LinkRemoteDockerServerCommand extends Command
{
    protected $signature = 'gameservers:link-remote-docker
        {server : Id or exact title of the permanent server entry}
        {--host= : Name or id of the registered remote host}
        {--container= : Docker container name on that host}
        {--console=rcon-cli : Console tool inside the container (rcon-cli)}';

    protected $description = 'Link a registered external permanent server to its Docker container on a managed remote host.';

    public function handle(LinkRemoteDockerServer $action): int
    {
        $ref = $this->stringArgument('server');

        $links = ServerLink::query()->permanent()
            ->where(fn ($q) => ctype_digit($ref) ? $q->whereKey((int) $ref) : $q->where('title', $ref))
            ->get();

        if ($links->count() !== 1) {
            $this->error($links->isEmpty() ? 'No permanent server matches that id or title.' : 'The title is ambiguous, use the id.');

            return self::FAILURE;
        }

        $hostRef = $this->stringOption('host');
        $hosts = RemoteHost::query()
            ->where(fn ($q) => ctype_digit($hostRef) ? $q->whereKey((int) $hostRef) : $q->where('name', $hostRef))
            ->get();

        if ($hostRef === '' || $hosts->count() !== 1) {
            $this->error($hostRef === '' ? 'Give the remote host with --host=<name or id>.' : ($hosts->isEmpty() ? 'No remote host matches that name or id.' : 'The host name is ambiguous, use the id.'));

            return self::FAILURE;
        }

        $console = ConsoleKind::tryFrom($this->stringOption('console'));

        if ($console === null) {
            $this->error('Unknown console tool. Allowed: '.implode(', ', array_map(fn (ConsoleKind $c): string => $c->value, ConsoleKind::cases())).'.');

            return self::FAILURE;
        }

        $link = $links->firstOrFail();
        $host = $hosts->firstOrFail();
        $container = $this->stringOption('container');

        try {
            $action->handle($link, $host, $container, $console);
        } catch (GameServerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Linked server {$link->id} to container {$container} on host {$host->id} ({$console->value}).");

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}
