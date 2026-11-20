<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Lfg\Actions\CreateLfgPost;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('lfg_create')]
#[Description('Create a "looking for group" post for the caller in an event.')]
class LfgCreateTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::Lfg);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_slug' => $schema->string()->description('Event slug.')->required(),
            'title' => $schema->string()->description('Short headline.')->required(),
            'game' => $schema->string()->description('Game name.')->required(),
            'body' => $schema->string()->description('What you need and when.'),
            'slots_needed' => $schema->integer()->description('How many players you need.')->default(1),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $create = app(CreateLfgPost::class);
        $event = $this->eventFromSlug((string) $request->get('event_slug'));

        $post = $create->handle($event, $this->user(), [
            'title' => (string) $request->get('title'),
            'game' => (string) $request->get('game'),
            'body' => (string) $request->get('body', ''),
            'slots_needed' => (int) $request->get('slots_needed', 1),
        ]);

        return $this->structured([
            'post' => [
                'id' => $post->id,
                'title' => $post->title,
                'game' => $post->game,
                'slots_needed' => $post->slots_needed,
                'expires_at' => $post->expires_at->toIso8601String(),
            ],
        ]);
    }
}
