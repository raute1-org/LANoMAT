<?php

declare(strict_types=1);

namespace App\Modules\Files\Models;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Files\Enums\UploadReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Quota held for a resumable upload that is not a SharedFile yet. Every
 * attribute is set by trusted code (ticket issuing, tusd hooks) via
 * forceFill(); nothing is mass-assignable.
 *
 * @property int $id
 * @property int $event_id
 * @property int $user_id
 * @property string $original_name
 * @property int $size_bytes
 * @property string|null $upload_id
 * @property string|null $stored_path
 * @property string|null $ticket_hash
 * @property Carbon|null $ticket_expires_at
 * @property UploadReservationStatus $status
 * @property Carbon $expires_at
 * @property int|null $shared_file_id
 */
class UploadReservation extends Model
{
    protected $table = 'file_upload_reservations';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'status' => UploadReservationStatus::class,
            'ticket_expires_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Reservations that currently hold quota.
     *
     * @param  Builder<UploadReservation>  $query
     * @return Builder<UploadReservation>
     */
    public function scopeHoldingQuota(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [UploadReservationStatus::Ticketed->value, UploadReservationStatus::Uploading->value])
            ->where('expires_at', '>', now());
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
