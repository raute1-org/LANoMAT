<?php

namespace App\Modules\Events\Models;

use App\Modules\Events\Enums\EventStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property EventStatus $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property array<string, mixed> $settings
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'status',
        'location',
        'starts_at',
        'ends_at',
        'max_participants',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_participants' => 'integer',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Event $event): void {
            if (blank($event->slug)) {
                $event->slug = self::uniqueSlugFrom($event->name);
            }
        });
    }

    private static function uniqueSlugFrom(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isPubliclyVisible(): bool
    {
        return $this->status !== EventStatus::Draft;
    }

    /**
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('status', '!=', EventStatus::Draft->value);
    }

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }
}
