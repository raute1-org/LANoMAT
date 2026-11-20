<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\GameServers\Models\ServerLink;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('servers_list')]
#[Description('Game servers: the permanent LAN servers, or the servers of one event (join info, status, game).')]
#[IsReadOnly]
class ServersListTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::GameServers);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_slug' => $schema->string()->description('Optional event slug; omit for permanent servers.'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $slug = $request->get('event_slug');
        $event = is_string($slug) && $slug !== '' ? $this->eventFromSlug($slug) : null;

        $servers = ServerLink::query()
            ->with('game:id,name,slug')
            ->when(($eventId = $event?->getKey()) !== null, fn ($query) => $query->where('event_id', $eventId), fn ($query) => $query->permanent())
            ->orderBy('title')
            ->orderBy('id')
            ->get();

        return $this->structured([
            'event_slug' => $event?->slug,
            'servers' => $servers->map(static fn (ServerLink $server): array => [
                'id' => $server->id,
                'title' => $server->title,
                'game' => $server->game?->name,
                'status' => $server->status,
                'permanent' => (bool) $server->permanent,
                'manual' => (bool) $server->manual,
                'join_info' => $server->join_info,
            ])->all(),
        ]);
    }
}
