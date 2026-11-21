<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Console;

use App\Modules\GameServers\Actions\LinkPelicanServer;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Console\Command;

/**
 * One-off: adopts an external permanent server into Pelican management without
 * re-creating it (see {@see LinkPelicanServer}).
 */
class LinkPelicanServerCommand extends Command
{
    protected $signature = 'gameservers:link-pelican
        {server : Id or exact title of the permanent server entry}
        {pelican : The panel server numeric id, short identifier or uuid}
        {--gungame : Mark the server as a GunGame server (shows the plugin settings)}';

    protected $description = 'Link a registered external permanent server to its Pelican panel server.';

    public function handle(LinkPelicanServer $action, PelicanClient $client): int
    {
        $ref = $this->stringArgument('server');

        $links = ServerLink::query()->permanent()
            ->where(fn ($q) => ctype_digit($ref) ? $q->whereKey((int) $ref) : $q->where('title', $ref))
            ->get();

        if ($links->count() !== 1) {
            $this->error($links->isEmpty() ? 'No permanent server matches that id or title.' : 'The title is ambiguous, use the id.');

            return self::FAILURE;
        }

        try {
            $server = $action->handle($links->firstOrFail(), $this->stringArgument('pelican'), (bool) $this->option('gungame'));
        } catch (GameServerException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Linked to Pelican server {$server->id} ({$server->identifier}).");

        // The Client API key's user must be able to see the server, or power, console and files fail with 404.
        try {
            if (! in_array($server->identifier, $client->listClientServerIdentifiers(), true)) {
                $this->warn('The Client API key cannot see this server. Make its user owner or subuser of the server, otherwise power, console and files will fail.');
            }
        } catch (GameServerException $e) {
            $this->warn('Could not check Client API access: '.$e->getMessage());
        }

        return self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) ? $value : '';
    }
}
