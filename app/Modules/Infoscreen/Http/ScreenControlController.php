<?php

namespace App\Modules\Infoscreen\Http;

use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\ShowSceneNow;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The helper-reachable "remote" for the beamer screen: a plain Inertia page
 * (gated by `role:helper`, not the Filament panel — helpers have no
 * `/admin` access) listing an event's scenes with a "show now" button each,
 * funnelling through {@see ShowSceneNow} exactly like the Filament resource's
 * `show_now` row action so the two surfaces can never drift.
 */
class ScreenControlController extends Controller
{
    use AuthorizesRequests;

    public function index(Event $event): Response
    {
        $scenes = InfoscreenScene::query()
            ->where('event_id', $event->id)
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (InfoscreenScene $scene): array => [
                'id' => $scene->id,
                'type' => $scene->type->value,
                'typeLabel' => $scene->type->label(),
                'enabled' => $scene->enabled,
            ])
            ->all();

        return Inertia::render('Screen/Control', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'scenes' => $scenes,
            'labels' => trans('infoscreen.control'),
        ]);
    }

    public function show(Event $event, InfoscreenScene $scene): RedirectResponse
    {
        $this->authorize('showNow', $scene);

        app(ShowSceneNow::class)->handle($scene);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans('infoscreen.control.shown'),
        ]);

        return back();
    }
}
