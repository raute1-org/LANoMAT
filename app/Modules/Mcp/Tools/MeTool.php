<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('lanomat_me')]
#[Description('Who the current LANoMAT token belongs to, which modules are enabled, and the agent default model. Call this first to confirm the connection.')]
#[IsReadOnly]
class MeTool extends LanomatTool
{
    protected function run(Request $request): Response|ResponseFactory
    {
        $user = $this->user();

        return $this->structured([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role->value,
                'is_orga' => $user->isOrga(),
            ],
            'server' => [
                'name' => (string) config('app.name'),
                'url' => url('/'),
                'version' => (string) config('app.version', 'dev'),
                'mcp_version' => '1.0',
            ],
            'modules' => array_map(
                static fn ($module): string => $module->key(),
                $this->modules()->enabledModules(),
            ),
            'agent' => [
                'default_model' => (string) config('mcp_agent.default_model'),
            ],
        ]);
    }
}
