<?php

namespace Database\Factories;

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Models\TombolaDraw;
use App\Modules\Infoscreen\Models\TombolaPrize;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<TombolaDraw>
 */
class TombolaDrawFactory extends Factory
{
    protected $model = TombolaDraw::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'tombola_prize_id' => TombolaPrize::factory(),
            'registration_id' => EventRegistration::factory()->checkedIn(),
            'drawn_at' => Carbon::now(),
        ];
    }
}
