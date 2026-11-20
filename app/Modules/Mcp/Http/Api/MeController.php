<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Http\Api;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Mcp\Support\McpConfig;
use App\Support\Modules\Modules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->authUser($request);

        return response()->json([
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
                'mcp_url' => url('/mcp'),
                'api_version' => McpConfig::API_VERSION,
            ],
            'agent' => [
                'default_model' => (string) config('mcp_agent.default_model'),
            ],
            'modules' => array_map(
                static fn ($module): string => $module->key(),
                app(Modules::class)->enabledModules(),
            ),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }
}
