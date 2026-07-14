<?php

namespace Database\Factories;

use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SeatAssignment> */
class SeatAssignmentFactory extends Factory
{
    protected $model = SeatAssignment::class;

    public function definition(): array
    {
        return [
            'seat_id' => Seat::factory(),
            'registration_id' => EventRegistration::factory(),
        ];
    }
}
