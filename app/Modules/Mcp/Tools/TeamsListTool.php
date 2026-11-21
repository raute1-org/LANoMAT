<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Models\TeamMember;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('teams_list')]
#[Description('List cross-event LANoMAT teams with member count and the caller\'s own role in them.')]
#[IsReadOnly]
class TeamsListTool extends LanomatTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Optional name/tag filter.'),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $query = trim((string) $request->get('query', ''));

        $teams = Team::query()
            ->withCount('members')
            ->when($query !== '', fn ($builder) => $builder->where(
                fn ($inner) => $inner->where('name', 'ilike', '%'.$query.'%')->orWhere('tag', 'ilike', '%'.$query.'%'),
            ))
            ->orderBy('name')
            ->limit(100)
            ->get();

        $mine = TeamMember::query()
            ->where('user_id', $this->user()->id)
            ->whereIn('team_id', $teams->pluck('id'))
            ->get()
            ->keyBy('team_id');

        return $this->structured([
            'teams' => $teams->map(fn (Team $team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'tag' => $team->tag,
                'members_count' => $team->members_count,
                'my_membership' => $mine->has($team->id) ? 'member' : null,
            ])->all(),
        ]);
    }
}
