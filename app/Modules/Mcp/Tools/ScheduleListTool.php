<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Schedule\Models\ScheduleItem;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('schedule_list')]
#[Description('The schedule of one event, in order, with type, time, location and description.')]
#[IsReadOnly]
class ScheduleListTool extends LanomatTool
{
    public function shouldRegister(Request $request): bool
    {
        return $this->modules()->enabled(Module::Schedule);
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

        $items = ScheduleItem::query()
            ->where('event_id', $event->id)
            ->orderBy('starts_at')
            ->orderBy('sort')
            ->get();

        return $this->structured([
            'event_slug' => $event->slug,
            'items' => $items->map(static fn (ScheduleItem $item): array => [
                'id' => $item->id,
                'type' => $item->type->value,
                'title' => $item->title,
                'description' => $item->description,
                'starts_at' => $item->starts_at->toIso8601String(),
                'ends_at' => $item->ends_at?->toIso8601String(),
                'location' => $item->location,
            ])->all(),
        ]);
    }
}
