<?php

namespace App\Http\Middleware;

use App\Modules\Events\Support\CurrentEvent;
use App\Modules\Registration\Enums\RegistrationAvailability;
use App\Support\DisplayTimezone;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Http\Request;
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
            'displayTimezone' => DisplayTimezone::name(),
            'modules' => fn () => array_map(
                fn (Module $m) => $m->key(),
                app(Modules::class)->enabledModules(),
            ),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'notificationLabels' => trans('notifications.bell'),
            // Always included (closure, not Inertia::optional): an optional prop is
            // dropped from every redirect response, which emptied the bell and its
            // badge after any mutation although notifications were still unread.
            'unreadNotificationCount' => fn () => $request->user()?->unreadNotifications()->count() ?? 0,
            'unreadNotifications' => fn () => $request->user()
                ?->unreadNotifications()->take(15)->get()
                ->map(fn ($n) => [
                    'id' => $n->id,
                    'title' => $n->data['title'] ?? '',
                    'body' => $n->data['body'] ?? '',
                    'createdAt' => $n->created_at?->toIso8601String(),
                ])->values()->all() ?? [],
            'currentEvent' => fn () => $request->user() === null || ($event = app(CurrentEvent::class)->get()) === null
                ? null
                : [
                    'name' => $event->name,
                    'slug' => $event->slug,
                    'status' => $event->status->value,
                    'startsAt' => $event->starts_at !== null ? $event->starts_at->toIso8601String() : null,
                    'endsAt' => $event->ends_at !== null ? $event->ends_at->toIso8601String() : null,
                    'location' => $event->location,
                    ...RegistrationAvailability::summary($event),
                ],
        ];
    }
}
