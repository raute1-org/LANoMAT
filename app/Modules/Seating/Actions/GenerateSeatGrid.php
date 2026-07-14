<?php

namespace App\Modules\Seating\Actions;

use App\Modules\Events\Models\Event;
use App\Modules\Seating\Models\Seat;
use Illuminate\Support\Facades\DB;

class GenerateSeatGrid
{
    public function handle(Event $event, int $rows, int $cols, string $labelPrefix): int
    {
        // Wrapped in a transaction so a bulk grid generation is atomic: a
        // failure partway through (e.g. a constraint violation) rolls back
        // the whole batch instead of leaving a half-generated grid behind.
        return DB::transaction(function () use ($event, $rows, $cols, $labelPrefix): int {
            $existing = Seat::query()
                ->where('event_id', $event->id)
                ->pluck('label')
                ->flip();

            $created = 0;

            for ($row = 1; $row <= $rows; $row++) {
                for ($col = 1; $col <= $cols; $col++) {
                    $label = "{$labelPrefix}{$row}-{$col}";
                    if ($existing->has($label)) {
                        continue;
                    }
                    Seat::create([
                        'event_id' => $event->id,
                        'label' => $label,
                        'pos_x' => $col,
                        'pos_y' => $row,
                        'meta' => [],
                    ]);
                    $created++;
                }
            }

            return $created;
        });
    }
}
