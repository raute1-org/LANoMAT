<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools\Write\Concerns;

use App\Modules\GameServers\Enums\ControlDriver;
use App\Modules\GameServers\Models\ServerLink;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/** Resolving the server a write tool acts on, and describing it on a card. */
trait TargetsServer
{
    /** @return array<string, mixed> */
    protected function serverIdSchema(JsonSchema $schema): array
    {
        return ['server_id' => $schema->integer()->description('Server-ID aus servers_list.')->required()];
    }

    /** @param  array<string, mixed>  $arguments */
    protected function findLink(array $arguments): ?ServerLink
    {
        return ServerLink::query()->find((int) ($arguments['server_id'] ?? 0));
    }

    /** @param  array<string, mixed>  $arguments */
    protected function linkOrFail(array $arguments): ServerLink
    {
        return ServerLink::query()->findOrFail((int) ($arguments['server_id'] ?? 0));
    }

    protected function describeLink(ServerLink $link): string
    {
        return ($link->title ?? 'Server').' (#'.$link->id.')';
    }

    protected function isRemoteDocker(ServerLink $link): bool
    {
        return $link->permanent && $link->controlDriver() === ControlDriver::RemoteDocker;
    }
}
