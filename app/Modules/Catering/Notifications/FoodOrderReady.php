<?php

namespace App\Modules\Catering\Notifications;

use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Discord\Channels\DiscordChannel;
use App\Modules\Infoscreen\Actions\TriggerFoodReady;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Notifies a food order's buyers that the food has arrived — the "Essen ist
 * da" one-click trigger (see {@see TriggerFoodReady}).
 * Bell is the source of truth (`database` always lands); the Discord DM
 * mirrors only per the `catering` category preference.
 */
class FoodOrderReady extends Notification
{
    use Queueable;

    public readonly string $category;

    public function __construct(
        public readonly FoodOrder $order,
    ) {
        $this->category = 'catering';
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', DiscordChannel::class];
    }

    /**
     * @return array<string, string>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'title' => __('catering.notifications.food_ready.title'),
            'body' => __('catering.notifications.food_ready.body', ['order' => $this->order->title]),
        ];
    }

    public function toDiscord(object $notifiable): string
    {
        return __('catering.notifications.food_ready.body', ['order' => $this->order->title]);
    }
}
