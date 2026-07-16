<?php

namespace App\Modules\Infoscreen\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\ShowSceneNow;
use App\Modules\Infoscreen\Actions\TriggerCheckinOpen;
use App\Modules\Infoscreen\Actions\TriggerFoodReady;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The helper-reachable "remote" for the beamer screen: a plain Inertia page
 * (gated by `role:helper`, not the Filament panel — helpers have no
 * `/admin` access) listing an event's scenes with a "show now" button each,
 * funnelling through {@see ShowSceneNow} exactly like the Filament resource's
 * `show_now` row action so the two surfaces can never drift. Also hosts the
 * one-click orga/helper triggers ("Essen ist da", "Check-in öffnet") added in
 * Task 10 — the bell-notification counterparts to the beamer push.
 */
class ScreenControlController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

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

        $foodOrders = FoodOrder::query()
            ->where('event_id', $event->id)
            ->where('status', FoodOrderStatus::Open->value)
            ->orderBy('title')
            ->get()
            ->map(fn (FoodOrder $order): array => [
                'id' => $order->id,
                'title' => $order->title,
            ])
            ->all();

        return Inertia::render('Screen/Control', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'scenes' => $scenes,
            'foodOrders' => $foodOrders,
            'labels' => trans('infoscreen.control'),
            'triggerLabels' => trans('infoscreen.triggers'),
        ]);
    }

    public function show(Event $event, InfoscreenScene $scene): RedirectResponse
    {
        abort_unless($scene->event_id === $event->id, 404);

        $this->authorize('showNow', $scene);

        app(ShowSceneNow::class)->handle($scene);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans('infoscreen.control.shown'),
        ]);

        return back();
    }

    public function foodReady(Request $request, Event $event, FoodOrder $foodOrder, TriggerFoodReady $action): RedirectResponse
    {
        abort_unless($foodOrder->event_id === $event->id, 404);

        $action->handle($foodOrder, $this->authUser($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans('infoscreen.triggers.food_ready_sent'),
        ]);

        return back();
    }

    public function checkinOpen(Request $request, Event $event, TriggerCheckinOpen $action): RedirectResponse
    {
        $action->handle($event, $this->authUser($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans('infoscreen.triggers.checkin_open_sent'),
        ]);

        return back();
    }
}
