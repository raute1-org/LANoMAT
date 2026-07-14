<?php

namespace App\Modules\Events\Models;

use App\Modules\Events\Enums\EventStatus;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @property EventStatus $status
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

    protected static function newFactory(): EventFactory
    {
        return EventFactory::new();
    }
}
