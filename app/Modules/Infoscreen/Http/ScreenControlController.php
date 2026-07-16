<?php

namespace App\Modules\Infoscreen\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\DrawTombola;
use App\Modules\Infoscreen\Actions\SetStatusSignal;
use App\Modules\Infoscreen\Actions\ShowSceneNow;
use App\Modules\Infoscreen\Actions\TriggerCheckinOpen;
use App\Modules\Infoscreen\Actions\TriggerFoodReady;
use App\Modules\Infoscreen\Enums\StatusLevel;
use App\Modules\Infoscreen\Exceptions\InfoscreenException;
use App\Modules\Infoscreen\Http\Requests\SetStatusSignalRequest;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Models\StatusSignal;
use App\Modules\Infoscreen\Models\TombolaPrize;
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

        $tombolaPrizes = TombolaPrize::query()
            ->where('event_id', $event->id)
            ->whereDoesntHave('draw')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (TombolaPrize $prize): array => [
                'id' => $prize->id,
                'title' => $prize->title,
            ])
            ->all();

        $statusSignals = StatusSignal::query()
            ->where('event_id', $event->id)
            ->currentPerComponent()
            ->get()
            ->map(fn (StatusSignal $signal): array => [
                'component' => $signal->component,
                'level' => $signal->level->value,
                'message' => $signal->message,
            ])
            ->keyBy('component')
            ->all();

        return Inertia::render('Screen/Control', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'scenes' => $scenes,
            'foodOrders' => $foodOrders,
            'tombolaPrizes' => $tombolaPrizes,
            'statusComponents' => StatusSignal::COMPONENTS,
            'statusSignals' => $statusSignals,
            'statusLevels' => array_map(
                fn (StatusLevel $level): array => ['value' => $level->value, 'label' => $level->label()],
                StatusLevel::cases(),
            ),
            'labels' => trans('infoscreen.control'),
            'triggerLabels' => trans('infoscreen.triggers'),
            'statusLabels' => trans('infoscreen.status'),
            'statusComponentLabels' => trans('infoscreen.status_component'),
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

    /**
     * The tombola "draw next prize" trigger: authorized like `show()` above
     * (class-based, mirroring InfoscreenScenePolicy::showNow — there is no
     * InfoscreenScene instance for a draw), then funnels through
     * {@see DrawTombola} which does the actual eligible-pool pick and beamer
     * push.
     */
    public function tombolaDraw(Event $event, TombolaPrize $tombolaPrize, DrawTombola $action): RedirectResponse
    {
        abort_unless($tombolaPrize->event_id === $event->id, 404);

        $this->authorize('drawTombola', InfoscreenScene::class);

        try {
            $action->handle($event, $tombolaPrize);
        } catch (InfoscreenException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($e->translationKey)]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans('infoscreen.triggers.tombola_draw_sent'),
        ]);

        return back();
    }

    /**
     * The operations status tile's "set status" control (Task 12): a helper
     * flags one component's level, which either pops an outage reassurance
     * onto the beamer or clears it — see {@see SetStatusSignal}.
     */
    public function setStatus(SetStatusSignalRequest $request, Event $event, SetStatusSignal $action): RedirectResponse
    {
        $this->authorize('setStatus', InfoscreenScene::class);

        $action->handle(
            $event,
            $request->string('component')->value(),
            StatusLevel::from($request->string('level')->value()),
            $request->string('message')->value() ?: null,
            $this->authUser($request),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => trans('infoscreen.status.saved'),
        ]);

        return back();
    }
}
