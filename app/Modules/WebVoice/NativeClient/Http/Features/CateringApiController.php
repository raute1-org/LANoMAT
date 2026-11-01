<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http\Features;

use App\Modules\Catering\Actions\CancelFoodOrderItem;
use App\Modules\Catering\Actions\PlaceFoodOrderItem;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Http\Requests\PlaceFoodOrderItemRequest;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Support\FoodOrderRegistrationRequirement;
use App\Modules\Catering\Support\ListedFoodOrders;
use App\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Native client: the catering page of an event. Orders are listed like the browser lists them
 * (open and closed, never drafts, only the caller's own items), and placing/cancelling goes through
 * PlaceFoodOrderItem / CancelFoodOrderItem, so windows, the registration requirement and the
 * per-user item limit are the browser's. All money is integer cents; prices come from the menu.
 */
class CateringApiController extends NativeFeatureController
{
    public function show(Request $request, Event $event): JsonResponse
    {
        $user = $this->user($request);
        $this->requireVisible($event);

        $orders = ListedFoodOrders::forEvent($event);
        $items = $this->itemsOf($orders, $user->id);
        $dtos = $orders->map(fn (FoodOrder $order): array => $this->orderDto($order, $items[$order->id] ?? []))->values()->all();

        $openOrderIds = $orders->where('status', FoodOrderStatus::Open)->modelKeys();

        return response()->json([
            'event_slug' => $event->slug,
            'registration_required' => ! FoodOrderRegistrationRequirement::satisfiedBy($user, $event),
            'limits' => [
                'items_per_user' => (int) config('lanomat.limits.food_items_per_user', 10),
                'my_open_items' => collect($items)->only($openOrderIds)->flatten()->count(),
            ],
            'orders' => $dtos,
        ]);
    }

    /** The caller's own items across the event's orders, with the status of the order they belong to. */
    public function mine(Request $request, Event $event): JsonResponse
    {
        $user = $this->user($request);
        $this->requireVisible($event);

        $orders = ListedFoodOrders::forEvent($event)->keyBy('id');
        $items = FoodOrderItem::query()
            ->whereIn('food_order_id', $orders->keys())
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get();

        $rows = $items->map(function (FoodOrderItem $item) use ($orders): array {
            /** @var FoodOrder $order */
            $order = $orders->get($item->food_order_id);

            return [
                ...$this->itemDto($item, $order),
                'order_id' => $order->id,
                'order_title' => $order->title,
                'order_status' => $order->status->value,
                'order_is_open' => $order->isOpenNow(),
                'can_cancel' => $order->isOpenNow(),
            ];
        })->values();

        return response()->json([
            'event_slug' => $event->slug,
            'items' => $rows->all(),
            'total_cents' => (int) $items->sum('price_cents'),
            'paid_cents' => (int) $items->filter(fn (FoodOrderItem $i): bool => $i->paid_at !== null)->sum('price_cents'),
        ]);
    }

    public function store(PlaceFoodOrderItemRequest $request, FoodOrder $foodOrder, PlaceFoodOrderItem $action): JsonResponse
    {
        $user = $this->user($request);
        $this->requireVisible($foodOrder->event()->first());

        $this->authorizeFor($user, 'create', FoodOrderItem::class);

        $data = $request->validated();

        try {
            $item = $action->handle($foodOrder, $user, $data['option_key'], $data['note'] ?? null);
        } catch (CateringException $exception) {
            return $this->domainError($exception->translationKey, $exception->parameters);
        }

        return response()->json($this->itemDto($item, $foodOrder), 201);
    }

    public function destroy(Request $request, FoodOrderItem $foodOrderItem, CancelFoodOrderItem $action): JsonResponse|Response
    {
        $user = $this->user($request);
        $order = $foodOrderItem->foodOrder()->firstOrFail();
        $this->requireVisible($order->event()->first());

        $this->authorizeFor($user, 'delete', $foodOrderItem);

        try {
            $action->handle($foodOrderItem);
        } catch (CateringException $exception) {
            return $this->domainError($exception->translationKey, $exception->parameters);
        }

        return response()->noContent();
    }

    /**
     * @param  EloquentCollection<int, FoodOrder>  $orders
     * @return array<int, list<FoodOrderItem>> the user's items keyed by order id
     */
    private function itemsOf(EloquentCollection $orders, int $userId): array
    {
        $ids = $orders->modelKeys();
        $grouped = [];

        foreach (FoodOrderItem::query()->whereIn('food_order_id', $ids)->where('user_id', $userId)->orderBy('id')->get() as $item) {
            $grouped[$item->food_order_id][] = $item;
        }

        return $grouped;
    }

    /**
     * @param  list<FoodOrderItem>  $myItems
     * @return array<string, mixed>
     */
    private function orderDto(FoodOrder $order, array $myItems): array
    {
        return [
            'id' => $order->id,
            'title' => $order->title,
            'status' => $order->status->value,
            'is_open' => $order->isOpenNow(),
            'opens_at' => $order->opens_at?->toIso8601String(),
            'closes_at' => $order->closes_at?->toIso8601String(),
            'menu' => collect($order->menu)->map(fn (MenuOption $option): array => [
                'key' => $option->key,
                'name' => $option->name,
                'price_cents' => $option->priceCents,
            ])->all(),
            'my_items' => array_map(fn (FoodOrderItem $item): array => $this->itemDto($item, $order), $myItems),
            'my_total_cents' => (int) array_sum(array_map(fn (FoodOrderItem $item): int => $item->price_cents, $myItems)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemDto(FoodOrderItem $item, FoodOrder $order): array
    {
        $key = $item->selection['option_key'] ?? null;
        $option = collect($order->menu)->first(fn (MenuOption $o): bool => $o->key === $key);

        return [
            'id' => $item->id,
            'option_key' => $key,
            'option_name' => $option?->name,
            'note' => $item->selection['note'] ?? null,
            'price_cents' => $item->price_cents,
            'paid' => $item->paid_at !== null,
        ];
    }
}
