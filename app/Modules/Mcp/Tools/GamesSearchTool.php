<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Games\Models\Game;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('games_search')]
#[Description('Search the LANoMAT games catalogue (tournament games): name, slug and team size. Reference data every participant may read.')]
#[IsReadOnly]
class GamesSearchTool extends LanomatTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Name or slug fragment.'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $query = trim((string) $request->get('query', ''));

        $games = Game::query()
            ->when($query !== '', fn ($builder) => $builder->where(
                fn ($inner) => $inner->where('name', 'ilike', '%'.$query.'%')->orWhere('slug', 'ilike', '%'.$query.'%'),
            ))
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'slug', 'min_team_size', 'max_team_size']);

        return $this->structured([
            'games' => $games->map(static fn (Game $game): array => [
                'id' => $game->id,
                'name' => $game->name,
                'slug' => $game->slug,
                'min_team_size' => $game->min_team_size,
                'max_team_size' => $game->max_team_size,
            ])->all(),
        ]);
    }
}
