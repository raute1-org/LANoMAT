<?php

use App\Modules\Seating\Models\Seat;

it('casts pos and meta', function () {
    $seat = Seat::factory()->create(['pos_x' => 3, 'pos_y' => 4, 'meta' => ['switch_port' => 12]]);

    expect($seat->fresh())
        ->pos_x->toBe(3)
        ->pos_y->toBe(4)
        ->meta->toBe(['switch_port' => 12]);
});
