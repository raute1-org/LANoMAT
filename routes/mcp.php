<?php

use App\Modules\Mcp\Http\Api\AvatarController;
use App\Modules\Mcp\Http\Api\FileController;
use App\Modules\Mcp\Http\Api\MeController;
use App\Modules\Mcp\Http\Api\RecipeController;
use App\Modules\Mcp\Http\AuthenticateMcpToken;
use App\Modules\Mcp\Http\DeviceFlowController;
use App\Modules\Mcp\Http\ForceJsonAccept;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
| LANoMAT agent API. Loaded from bootstrap/app.php without the `web`
| middleware group: bearer tokens, no session, no cookies, no CSRF.
| Docs: docs-site (agent + API reference), README "MCP".
*/

// Device authorization flow: unauthenticated by design, strictly rate limited.
Route::post('api/mcp/device', [DeviceFlowController::class, 'device'])
    ->middleware('throttle:mcp-device')
    ->name('mcp.device');
Route::post('api/mcp/token', [DeviceFlowController::class, 'token'])
    ->middleware('throttle:mcp-token')
    ->name('mcp.token');

$modules = app(Modules::class);

Route::prefix('api/v1')
    ->middleware([ForceJsonAccept::class, AuthenticateMcpToken::class, 'throttle:mcp-api', SubstituteBindings::class])
    ->name('mcp.api.')
    ->group(function () use ($modules): void {
        Route::get('me', MeController::class)->name('me');

        Route::get('avatars', [AvatarController::class, 'index'])->name('avatars.index');

        if ($modules->enabled(Module::GameRecipes)) {
            Route::get('recipes', [RecipeController::class, 'index'])->name('recipes.index');
            Route::post('recipes', [RecipeController::class, 'contribute'])->name('recipes.store');
            Route::get('recipes/{slug}', [RecipeController::class, 'show'])->name('recipes.show');
            Route::get('recipes/{slug}/skill', [RecipeController::class, 'skill'])->name('recipes.skill');
            Route::post('recipes/{slug}/plan', [RecipeController::class, 'plan'])->name('recipes.plan');
            Route::get('recipes/{slug}/files', [RecipeController::class, 'files'])->name('recipes.files');
            Route::get('recipes/{slug}/avatars', [RecipeController::class, 'avatars'])->name('recipes.avatars');
        }

        if ($modules->enabled(Module::Files)) {
            Route::get('files', [FileController::class, 'index'])->name('files.index');
        }
    });

// The plan builder hands out absolute URLs with this name; keep it stable even
// when the recipe block above is moved.
Route::get('api/v1/files/{sharedFile}/download', [FileController::class, 'download'])
    ->middleware([ForceJsonAccept::class, AuthenticateMcpToken::class, 'throttle:mcp-api', SubstituteBindings::class, 'throttle:60,1'])
    ->name('mcp.files.download');
