<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FoodOrderItem>
 */
class FoodOrderItemFactory extends Factory
{
    protected $model = FoodOrderItem::class;

    public function definition(): array
    {
        return [
            'food_order_id' => FoodOrder::factory(),
            'user_id' => User::factory(),
            'selection' => ['option_key' => 'pizza_margherita'],
            'price_cents' => 850,
        ];
    }

    public function paid(): static
    {
        return $this->state(['paid_at' => now()]);
    }
}
