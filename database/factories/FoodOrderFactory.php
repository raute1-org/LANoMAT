<?php

namespace Database\Factories;

use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FoodOrder>
 */
class FoodOrderFactory extends Factory
{
    protected $model = FoodOrder::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'title' => 'Pizza-Bestellung',
            'menu' => $this->defaultMenu(),
            'opens_at' => null,
            'closes_at' => null,
            'status' => FoodOrderStatus::Draft,
        ];
    }

    public function open(): static
    {
        return $this->state([
            'status' => FoodOrderStatus::Open,
            'opens_at' => now()->subHour(),
            'closes_at' => now()->addHours(2),
        ]);
    }

    public function closed(): static
    {
        return $this->state([
            'status' => FoodOrderStatus::Closed,
            'opens_at' => now()->subHours(3),
            'closes_at' => now()->subHour(),
        ]);
    }

    /**
     * @return list<MenuOption>
     */
    private function defaultMenu(): array
    {
        $options = [
            new MenuOption(key: 'pizza_margherita', name: 'Pizza Margherita', priceCents: 850),
            new MenuOption(key: 'pizza_salami', name: 'Pizza Salami', priceCents: 950),
            new MenuOption(key: 'salad', name: 'Salat', priceCents: 450),
        ];

        return $this->faker->boolean(50)
            ? array_slice($options, 0, 2)
            : $options;
    }
}
