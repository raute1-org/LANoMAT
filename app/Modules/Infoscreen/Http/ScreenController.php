<?php

namespace App\Modules\Infoscreen\Http;

use App\Http\Controllers\Controller;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Support\ScenePayload;
use Inertia\Inertia;
use Inertia\Response;

class ScreenController extends Controller
{
    /**
     * The public beamer screen for an event: the client rotates through the
     * enabled scenes on its own (see `useSceneRotation`) and listens for
     * `scene.override`/`scenes.updated` pushes on the `event.{id}` channel —
     * no polling, no auth.
     */
    public function show(Event $event): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $scenes = InfoscreenScene::query()
            ->where('event_id', $event->id)
            ->enabledOrdered()
            ->get()
            ->map(fn (InfoscreenScene $scene): array => ScenePayload::for($scene))
            ->all();

        return Inertia::render('Screen/Show', [
            'event' => ['id' => $event->id, 'name' => $event->name, 'slug' => $event->slug],
            'scenes' => $scenes,
            'labels' => trans('infoscreen.screen'),
        ]);
    }
}
