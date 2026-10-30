<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Console;

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Domain\ServerLimits;
use App\Modules\GameServers\Domain\ServerSpec;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Exceptions\GameServerException;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Live contract smoke test against a real Pelican panel — NOT part of CI.
 * Reads URL and tokens from the process environment (PELICAN_PANEL_URL,
 * PELICAN_APPLICATION_TOKEN, PELICAN_CLIENT_TOKEN, PELICAN_DEFAULT_NODE_ID /
 * PELICAN_NODE_ID, PELICAN_OWNER_USER_ID) through the normal
 * `services.pelican` config, runs the read-only calls and prints PASS/FAIL per
 * call. Tokens are never printed. See docs/gameservers-pelican.md.
 *
 * `--lifecycle` additionally creates ONE small throwaway server, starts and
 * stops it, deletes it and verifies the panel is clean again.
 */
class PelicanCheckCommand extends Command
{
    protected $signature = 'gameservers:pelican-check
        {--lifecycle : Also create, start, stop and delete one throwaway server (destructive on the panel)}
        {--egg= : Egg id for --lifecycle (a small Minecraft egg is a good choice)}
        {--node= : Node id for --lifecycle (default: services.pelican.default_node_id)}
        {--owner= : Panel user id owning the throwaway server (default: services.pelican.owner_user_id)}
        {--start-on-completion : With --lifecycle: create with start_on_completion and do not send a start signal}
        {--wait=180 : Seconds to wait for install/start/stop transitions}';

    protected $description = 'Live smoke test of the Pelican API contract (read-only; --lifecycle also creates and deletes one server).';

    private int $failures = 0;

    public function handle(PelicanClient $client): int
    {
        $nodeId = $this->option('node') ?: config('services.pelican.default_node_id');

        $nodes = $this->step('nodes (application)', function () use ($client, &$nodeId): string {
            $nodes = $client->listNodes();
            $nodeId ??= $nodes[0]->id ?? null;

            return count($nodes).' node(s)';
        });

        if ($nodeId !== null && $nodes) {
            $this->step("allocations of node {$nodeId} (application)", function () use ($client, $nodeId): string {
                $all = $client->listAllocations((string) $nodeId);
                $free = count(array_filter($all, fn ($a): bool => ! $a->assigned));

                return count($all)." allocation(s), {$free} free";
            });
        }

        $this->step('eggs (application)', fn (): string => count($client->listEggs()).' egg(s)');

        $servers = null;
        $this->step('servers (application)', function () use ($client, &$servers): string {
            $servers = $client->listServers();

            return count($servers).' server(s)';
        });

        $this->step('client key valid (client)', fn (): string => count($client->listClientServerIdentifiers()).' server(s) visible to the client key');

        $first = $servers[0] ?? null;

        if ($first !== null && $first->identifier !== null) {
            $this->step("resources of server {$first->id} (client)", fn (): string => $client->getResources($first->identifier)->state->value);
            $this->step("server {$first->id} details with allocation + state", function () use ($client, $first): string {
                $s = $client->getServer($first->id);

                return "state={$s->state->value} address=".($s->address ?? '?').':'.($s->port ?? '?');
            });
        } else {
            $this->line('SKIP resources / details (no server on the panel)');
        }

        if ($this->option('lifecycle')) {
            $this->lifecycle($client, $nodeId !== null ? (string) $nodeId : null);
        }

        $this->line($this->failures === 0 ? 'ALL PASS' : "{$this->failures} FAILURE(S)");

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function lifecycle(PelicanClient $client, ?string $nodeId): void
    {
        $eggId = (string) $this->option('egg');
        $owner = (string) ($this->option('owner') ?: config('services.pelican.owner_user_id'));
        $wait = (int) $this->option('wait');
        $autoStart = (bool) $this->option('start-on-completion');

        if ($eggId === '' || $owner === '' || $nodeId === null) {
            $this->failures++;
            $this->error('FAIL lifecycle: --egg, an owner (--owner or PELICAN_OWNER_USER_ID) and a node are required.');

            return;
        }

        $server = null;

        $this->step('create throwaway server (application)', function () use ($client, $eggId, $owner, $nodeId, $autoStart, &$server): string {
            $spec = new ServerSpec(
                name: 'lanomat-contract-check',
                ownerUserId: $owner,
                eggId: $eggId,
                environment: $client->getEgg($eggId)->variables,
                limits: new ServerLimits(memory: 1024, disk: 2048),
                nodeId: $nodeId,
                startOnCompletion: $autoStart,
            );
            $server = $client->createServer($spec);

            return "id={$server->id} identifier={$server->identifier} address=".($server->address ?? '?').':'.($server->port ?? '?');
        });

        if ($server === null) {
            return;
        }

        try {
            $this->step('install finishes', fn (): string => $this->waitFor($wait, function () use ($client, $server): ?string {
                $state = $client->getServer($server->id)->state;

                return in_array($state, [ServerState::Installing, ServerState::Provisioning], true) ? null : $state->value;
            }, 'install did not finish'));

            if ($server->identifier === null) {
                return;
            }

            $identifier = $server->identifier;

            if (! $autoStart) {
                $this->step('start (client power)', function () use ($client, $identifier): string {
                    $client->powerAction($identifier, PowerAction::Start);

                    return 'signal accepted';
                });
            }

            $this->step('resources report the start transition', function () use ($client, $identifier, $wait): string {
                // Poll fast and record every distinct state: a server that cannot
                // finish booting (e.g. an unaccepted EULA) goes starting -> offline
                // within seconds, so waiting only for `running` would miss it.
                $seen = [];
                $deadline = time() + min($wait, 60);

                do {
                    $state = $client->getResources($identifier)->state;

                    if ($seen === [] || end($seen) !== $state->value) {
                        $seen[] = $state->value;
                    }

                    if ($state === ServerState::Running) {
                        break;
                    }

                    sleep(1);
                } while (time() < $deadline);

                if (! array_intersect($seen, ['starting', 'running'])) {
                    throw new RuntimeException('never left offline after start: '.implode(' -> ', $seen));
                }

                return implode(' -> ', $seen);
            });
            $this->step('stop (client power)', function () use ($client, $identifier): string {
                $client->powerAction($identifier, PowerAction::Kill);

                return 'kill signal accepted';
            });
            $this->step('resources report offline after stop', fn (): string => $this->waitFor($wait, fn (): ?string => $client->getResources($identifier)->state === ServerState::Stopped ? 'offline' : null, 'server did not go offline'));
        } finally {
            $this->step('delete throwaway server (application)', function () use ($client, $server): string {
                $client->deleteServer($server->id);

                return 'deleted';
            });
            $this->step('panel is clean again', function () use ($client, $server): string {
                foreach ($client->listServers() as $remaining) {
                    if ($remaining->id === $server->id) {
                        throw new RuntimeException('Throwaway server still listed.');
                    }
                }

                return 'server no longer listed';
            });
        }
    }

    /**
     * @param  callable(): ?string  $probe  null = not yet
     */
    private function waitFor(int $seconds, callable $probe, string $timeoutMessage): string
    {
        $deadline = time() + $seconds;

        do {
            $result = $probe();

            if ($result !== null) {
                return $result;
            }

            sleep(3);
        } while (time() < $deadline);

        throw new RuntimeException($timeoutMessage);
    }

    /**
     * @param  callable(): string  $call
     * @return bool|null true when the step passed (null when it failed)
     */
    private function step(string $label, callable $call): ?bool
    {
        try {
            $detail = $call();
            $this->line("PASS {$label}: {$detail}");

            return true;
        } catch (GameServerException $e) {
            $this->failures++;
            $status = $e->httpStatus !== null ? " (HTTP {$e->httpStatus})" : '';
            $this->line("FAIL {$label}{$status}: ".$e->getMessage());
        } catch (Throwable $e) {
            $this->failures++;
            $this->line("FAIL {$label}: ".$e->getMessage());
        }

        return null;
    }
}
