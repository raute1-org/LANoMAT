<?php

namespace App\Modules\Infoscreen\Actions;

use App\Models\User;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Notifications\FoodOrderReady;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Events\SceneOverride;
use App\Modules\Infoscreen\Listeners\BroadcastWinnerMoment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * The "Essen ist da" one-click trigger: notifies every distinct buyer of the
 * given food order (bell is the source of truth, Discord DM mirrors per the
 * `catering` preference — see {@see FoodOrderReady}), then pushes a synthetic
 * announcement scene onto the beamer via {@see SceneOverride}, mirroring
 * {@see BroadcastWinnerMoment}'s
 * synthetic-scene pattern rather than requiring a pre-configured
 * `InfoscreenScene` row to exist.
 *
 * Idempotency is deliberately not required: an orga/helper may want to
 * re-announce (e.g. a second batch of food arriving).
 */
class TriggerFoodReady
{
    private const DURATION_SEC = 15;

    public function handle(FoodOrder $order, User $actor): void
    {
        Gate::forUser($actor)->authorize('trigger', $order);

        $buyers = $this->buyersFor($order);

        if ($buyers->isNotEmpty()) {
            Notification::send($buyers, new FoodOrderReady($order));
        }

        SceneOverride::dispatch($order->event_id, [
            'type' => SceneType::Announcement->value,
            'durationSec' => self::DURATION_SEC,
            'config' => [
                'headline' => __('catering.notifications.food_ready.title'),
                'body' => __('catering.notifications.food_ready.body', ['order' => $order->title]),
            ],
            'data' => [],
        ]);
    }

    /**
     * Distinct users with at least one item in this order.
     *
     * @return Collection<int, User>
     */
    private function buyersFor(FoodOrder $order): Collection
    {
        $userIds = $order->items()->distinct()->pluck('user_id');

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::query()->whereIn('id', $userIds)->get();
    }
}
