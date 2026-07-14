<?php

namespace App\Modules\Seating\Models;

use App\Modules\Registration\Models\EventRegistration;
use Database\Factories\SeatAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeatAssignment extends Model
{
    /** @use HasFactory<SeatAssignmentFactory> */
    use HasFactory;

    protected $fillable = ['seat_id', 'registration_id'];

    /** @return BelongsTo<Seat, $this> */
    public function seat(): BelongsTo
    {
        return $this->belongsTo(Seat::class);
    }

    /** @return BelongsTo<EventRegistration, $this> */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    protected static function newFactory(): SeatAssignmentFactory
    {
        return SeatAssignmentFactory::new();
    }
}
