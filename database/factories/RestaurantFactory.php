<?php

namespace Database\Factories;

use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Restaurant>
 */
class RestaurantFactory extends Factory
{
    protected $model = Restaurant::class;

    public function definition(): array
    {
        $name = 'Testimbiss '.Str::title($this->faker->unique()->lexify('????'));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numerify('###'),
            'menu' => [
                new MenuOption(key: 'pizza_margherita', name: 'Pizza: Margherita', priceCents: 850),
                new MenuOption(key: 'pizza_salami', name: 'Pizza: Salami', priceCents: 950),
                new MenuOption(key: 'salad', name: 'Salat', priceCents: 450),
            ],
        ];
    }

    /** @param  list<MenuOption>  $menu */
    public function withMenu(array $menu): static
    {
        return $this->state(['menu' => $menu]);
    }
}
