<?php

namespace App\Modules\Infoscreen\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Actions\SetStatusSignal;
use App\Modules\Infoscreen\Enums\StatusLevel;
use Database\Factories\StatusSignalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single operations-status report for one component ('internet',
 * 'servers', 'voice') of an event. Deliberately append-only rather than a
 * single mutable per-component row: every {@see SetStatusSignal}
 * call inserts a new row, and "the current signal" for a component is simply
 * its latest row (highest `id`) — see `scopeCurrentPerComponent`. This keeps
 * a full history of outages/recoveries for free and needs no unique
 * constraint or upsert-race handling.
 *
 * @property int $event_id
 * @property string $component
 * @property StatusLevel $level
 * @property string|null $message
 */
class StatusSignal extends Model
{
    /** @use HasFactory<StatusSignalFactory> */
    use HasFactory;

    public const array COMPONENTS = ['internet', 'servers', 'voice'];

    protected $fillable = [
        'event_id',
        'component',
        'level',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'level' => StatusLevel::class,
        ];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The latest row per (event, component) — the current signal. Restricted
     * to a single event's rows by the caller (see `SetStatusSignal`/
     * `ScenePayload`), then grouped down to one row per component here.
     *
     * @param  Builder<StatusSignal>  $query
     * @return Builder<StatusSignal>
     */
    public function scopeCurrentPerComponent(Builder $query): Builder
    {
        return $query
            ->whereIn('id', function ($sub): void {
                $sub->selectRaw('MAX(id)')
                    ->from('status_signals as latest')
                    ->whereColumn('latest.event_id', 'status_signals.event_id')
                    ->whereColumn('latest.component', 'status_signals.component')
                    ->groupBy('latest.event_id', 'latest.component');
            });
    }

    protected static function newFactory(): StatusSignalFactory
    {
        return StatusSignalFactory::new();
    }
}
