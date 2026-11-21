<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Http\Api;

use App\Http\Controllers\Controller;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Support\ServerListProjection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * The launcher's aggregated server browser: the event servers of every active
 * event plus the most recently finished ones, and the permanent servers. It is
 * the same wire shape the participant web page uses
 * ({@see ServerListProjection}) with no per-viewer narrowing and no player
 * names, so the answer carries no user data.
 */
class ServerController extends Controller
{
    /** How long a finished event still counts as "recent" for its servers. */
    private const RECENT_DAYS = 30;

    public function __invoke(): JsonResponse
    {
        $events = $this->events();

        $servers = [];
        $seen = [];

        foreach ($events as $event) {
            foreach (ServerListProjection::forEvent($event) as $dto) {
                $id = (int) $dto['id'];

                if (isset($seen[$id])) {
                    continue;
                }

                $seen[$id] = true;
                $servers[] = $dto + [
                    'source' => null,
                    'permanent' => false,
                    'event' => [
                        'slug' => $event->slug,
                        'name' => $event->name,
                        'status' => $event->status->value,
                    ],
                ];
            }
        }

        foreach (ServerListProjection::forPermanent() as $dto) {
            $id = (int) $dto['id'];

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $servers[] = $dto + [
                'permanent' => true,
                'event' => null,
            ];
        }

        return response()->json([
            'events' => $events->map(static fn (Event $event): array => [
                'slug' => $event->slug,
                'name' => $event->name,
                'status' => $event->status->value,
            ])->values()->all(),
            'servers' => $servers,
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    /**
     * Active (live/registration/announced) events, live first, followed by a
     * finished event whose parties lie within the recent window. Drafts are
     * invisible, and an archived event is deliberately "put away".
     *
     * @return Collection<int, Event>
     */
    private function events(): Collection
    {
        $active = Event::query()
            ->publiclyVisible()
            ->whereIn('status', [
                EventStatus::Live->value,
                EventStatus::Registration->value,
                EventStatus::Announced->value,
            ])
            ->orderByRaw(
                'case status when ? then 0 when ? then 1 else 2 end',
                [EventStatus::Live->value, EventStatus::Registration->value],
            )
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        $recent = Event::query()
            ->where('status', EventStatus::Finished->value)
            ->where(function ($query): void {
                $since = now()->subDays(self::RECENT_DAYS);

                $query->where('ends_at', '>=', $since)
                    ->orWhere(fn ($q) => $q->whereNull('ends_at')->where('starts_at', '>=', $since));
            })
            ->orderByDesc('ends_at')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        return $active->concat($recent->all());
    }
}
