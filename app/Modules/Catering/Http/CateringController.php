<?php

namespace App\Modules\Catering\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Catering\Actions\CancelFoodOrderItem;
use App\Modules\Catering\Actions\PlaceFoodOrderItem;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Http\Requests\PlaceFoodOrderItemRequest;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Events\Models\Event;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CateringController extends Controller
{
    use AuthorizesRequests;
    use ResolvesAuthenticatedUser;

    /**
     * The participant catering page: every non-draft FoodOrder for the
     * event with its typed menu, the requesting user's own items (never
     * other users' items — see CostSplit's per-user breakdown, which is
     * deliberately NOT surfaced here for privacy), and each order's live
     * open/closed window state.
     */
    public function show(Request $request, Event $event): Response
    {
        abort_unless($event->isPubliclyVisible(), 404);

        $userId = $request->user()?->id;

        $orders = FoodOrder::query()
            ->where('event_id', $event->id)
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Catering/Show', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'orders' => $orders->map(fn (FoodOrder $order): array => $this->orderDto($order, $userId))->all(),
            'labels' => trans('catering.page'),
        ]);
    }

    public function store(PlaceFoodOrderItemRequest $request, FoodOrder $foodOrder, PlaceFoodOrderItem $action): RedirectResponse
    {
        $this->authorize('create', FoodOrderItem::class);

        $data = $request->validated();

        try {
            $action->handle($foodOrder, $this->authUser($request), $data['option_key'], $data['note'] ?? null);
        } catch (CateringException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($exception->translationKey)]);

            return back();
        }

        return back();
    }

    public function destroy(Request $request, FoodOrderItem $foodOrderItem, CancelFoodOrderItem $action): RedirectResponse
    {
        $this->authorize('delete', $foodOrderItem);

        try {
            $action->handle($foodOrderItem);
        } catch (CateringException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($exception->translationKey)]);

            return back();
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function orderDto(FoodOrder $order, ?int $userId): array
    {
        $items = $order->items()->with('user')->get();

        $myItems = $userId === null
            ? collect()
            : $items->where('user_id', $userId);

        return [
            'id' => $order->id,
            'title' => $order->title,
            'status' => $order->status->value,
            'statusLabel' => $order->status->label(),
            'isOpen' => $order->isOpenNow(),
            'opensAt' => $order->opens_at?->toIso8601String(),
            'closesAt' => $order->closes_at?->toIso8601String(),
            'menu' => collect($order->menu)->map(fn (MenuOption $option): array => [
                'key' => $option->key,
                'name' => $option->name,
                'priceCents' => $option->priceCents,
            ])->all(),
            'myItems' => $myItems->map(fn (FoodOrderItem $item): array => $this->itemDto($item))->values()->all(),
            'myTotalCents' => $myItems->sum('price_cents'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemDto(FoodOrderItem $item): array
    {
        return [
            'id' => $item->id,
            'optionKey' => $item->selection['option_key'] ?? null,
            'note' => $item->selection['note'] ?? null,
            'priceCents' => $item->price_cents,
            'paid' => $item->paid_at !== null,
        ];
    }
}
