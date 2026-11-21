<?php

namespace App\Modules\Catering\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Catering\Actions\CancelFoodOrderItem;
use App\Modules\Catering\Actions\PlaceFoodOrderItem;
use App\Modules\Catering\Domain\MenuChoices;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Http\Requests\PlaceFoodOrderItemRequest;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Support\DishImage;
use App\Modules\Catering\Support\FoodOrderRegistrationRequirement;
use App\Modules\Catering\Support\ItemPicks;
use App\Modules\Catering\Support\ListedFoodOrders;
use App\Modules\Catering\Support\MyFoodPayment;
use App\Modules\Catering\Support\RestaurantCard;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\EventPaymentSettings;
use App\Support\Money\Euro;
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

        $orders = ListedFoodOrders::forEvent($event);
        $settings = $request->user() === null ? null : EventPaymentSettings::forEvent($event);

        return Inertia::render('Catering/Show', [
            'event' => ['name' => $event->name, 'slug' => $event->slug],
            'orders' => $orders->map(fn (FoodOrder $order): array => $this->orderDto($order, $event, $request->user(), $settings))->all(),
            'labels' => trans('catering.page'),
            'registration' => [
                'required' => $request->user() !== null
                    && ! FoodOrderRegistrationRequirement::satisfiedBy($request->user(), $event),
                'url' => route('events.register', $event->slug),
            ],
        ]);
    }

    public function store(PlaceFoodOrderItemRequest $request, FoodOrder $foodOrder, PlaceFoodOrderItem $action): RedirectResponse
    {
        $this->authorize('create', FoodOrderItem::class);

        $data = $request->validated();

        try {
            $item = $action->handle($foodOrder, $this->authUser($request), $data['option_key'], $data['note'] ?? null, $data['choices'] ?? null);
        } catch (CateringException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($exception->translationKey, $exception->parameters)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('catering.page.toast_added', [
            'dish' => $this->dishName($foodOrder, $data['option_key'], $item->selection['name'] ?? null),
            'price' => Euro::format($item->price_cents),
        ])]);

        return back();
    }

    public function destroy(Request $request, FoodOrderItem $foodOrderItem, CancelFoodOrderItem $action): RedirectResponse
    {
        $this->authorize('delete', $foodOrderItem);

        $order = $foodOrderItem->foodOrder;
        $optionKey = (string) ($foodOrderItem->selection['option_key'] ?? '');
        $snapshot = $foodOrderItem->selection['name'] ?? null;

        try {
            $action->handle($foodOrderItem);
        } catch (CateringException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => trans($exception->translationKey, $exception->parameters)]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('catering.page.toast_removed', [
            'dish' => $this->dishName($order, $optionKey, $snapshot),
        ])]);

        return back();
    }

    /** The dish name without its "Category: " prefix, as the page shows it. */
    private function dishName(?FoodOrder $order, string $optionKey, ?string $snapshot = null): string
    {
        $option = collect($order?->menuOptions())->first(fn (MenuOption $menuOption): bool => $menuOption->key === $optionKey);
        $name = $snapshot !== null && $snapshot !== '' ? $snapshot : ($option === null ? $optionKey : $option->name);
        $index = strpos($name, ': ');

        if ($index === false || $index <= 0 || $index > 32) {
            return $name;
        }

        $label = trim(substr($name, $index + 2));

        return $label === '' ? $name : $label;
    }

    /**
     * @return array<string, mixed>
     */
    private function orderDto(FoodOrder $order, Event $event, ?User $viewer, ?EventPaymentSettings $settings): array
    {
        $userId = $viewer?->id;
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
            'restaurant' => RestaurantCard::for($order->restaurant),
            'menu' => collect($menuOptions = $order->menuOptions())->map(fn (MenuOption $option): array => [
                'key' => $option->key,
                'name' => $option->name,
                'priceCents' => $option->priceCents,
                'description' => $option->description,
                'imageUrl' => DishImage::url($order, $option),
                'choices' => MenuChoices::describe($option, $menuOptions),
            ])->all(),
            'myItems' => $myItems->map(fn (FoodOrderItem $item): array => $this->itemDto($item))->values()->all(),
            'myTotalCents' => $myItems->sum('price_cents'),
            'myPayment' => $viewer === null ? null : MyFoodPayment::for($order, $event, $viewer, $myItems, $settings),
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
            'name' => $item->selection['name'] ?? null,
            'note' => $item->selection['note'] ?? null,
            'choices' => ItemPicks::of($item),
            'priceCents' => $item->price_cents,
            'paid' => $item->paid_at !== null,
            'included' => $item->included,
        ];
    }
}
