<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Lfg\Models\LfgPost;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('lfg_list')]
#[Description('Active "looking for group" posts of one event.')]
#[IsReadOnly]
class LfgListTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::Lfg);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_slug' => $schema->string()->description('Event slug.')->required(),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $event = $this->eventFromSlug((string) $request->get('event_slug'));

        $posts = LfgPost::query()
            ->where('event_id', $event->id)
            ->active()
            ->with('user:id,name')
            ->orderByDesc('id')
            ->get();

        return $this->structured([
            'posts' => $posts->map(static fn (LfgPost $post): array => [
                'id' => $post->id,
                'title' => $post->title,
                'body' => $post->body,
                'game' => $post->game,
                'slots_needed' => $post->slots_needed,
                'author' => $post->user?->name,
                'expires_at' => $post->expires_at->toIso8601String(),
            ])->all(),
        ]);
    }
}
