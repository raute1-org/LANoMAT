<?php

namespace App\Http\Middleware;

use App\Modules\Events\Support\CurrentEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            // Reverb connection data for the browser's Echo client, resolved
            // from server config at request time. Delivered as a runtime prop
            // (not Vite's build-time VITE_REVERB_*), so a real deploy .env
            // controls where the frontend connects without a frontend rebuild.
            'reverb' => [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('broadcasting.connections.reverb.options.host'),
                'port' => config('broadcasting.connections.reverb.options.port'),
                'scheme' => config('broadcasting.connections.reverb.options.scheme'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'notificationLabels' => trans('notifications.bell'),
            'unreadNotifications' => Inertia::optional(fn () => $request->user()
                ?->unreadNotifications()->take(15)->get()
                ->map(fn ($n) => [
                    'id' => $n->id,
                    'title' => $n->data['title'] ?? '',
                    'body' => $n->data['body'] ?? '',
                    'createdAt' => $n->created_at?->toIso8601String(),
                ])->values()->all() ?? []),
            'currentEvent' => fn () => ($event = app(CurrentEvent::class)->get()) === null
                ? null
                : [
                    'name' => $event->name,
                    'slug' => $event->slug,
                    'status' => $event->status->value,
                    'startsAt' => $event->starts_at !== null ? $event->starts_at->toIso8601String() : null,
                    'endsAt' => $event->ends_at !== null ? $event->ends_at->toIso8601String() : null,
                    'location' => $event->location,
                ],
        ];
    }
}
