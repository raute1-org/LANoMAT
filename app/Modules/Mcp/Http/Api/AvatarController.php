<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Http\Api;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Modules\GameRecipes\Support\AvatarCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The global avatar library: every selectable avatar/content pack on this LAN. */
class AvatarController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function index(Request $request, AvatarCatalog $catalog): JsonResponse
    {
        $recipe = null;
        $slug = $request->string('recipe')->toString();

        if ($slug !== '') {
            $needle = mb_strtolower($slug);
            $recipe = GameRecipe::query()
                ->visibleTo($this->authUser($request))
                ->get()
                ->first(fn (GameRecipe $candidate): bool => $candidate->slug === $needle
                    || in_array($needle, $candidate->searchTerms(), true));
        }

        return response()->json([
            'data' => $catalog->forRecipe($recipe, $this->authUser($request), $request->string('q')->toString()),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }
}
