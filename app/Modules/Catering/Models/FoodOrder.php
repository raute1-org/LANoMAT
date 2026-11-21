<?php

namespace App\Modules\Catering\Models;

use App\Modules\Catering\Casts\MenuCast;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Enums\FoodPaymentMethod;
use App\Modules\Events\Models\Event;
use Database\Factories\FoodOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property list<MenuOption> $menu LEGACY column; read menus through {@see self::menuOptions()}
 * @property int|null $restaurant_id
 * @property FoodOrderStatus $status
 * @property FoodPaymentMethod $payment_method
 * @property string|null $payment_link
 * @property list<string> $included_ticket_types
 * @property Carbon|null $opens_at
 * @property Carbon|null $closes_at
 */
class FoodOrder extends Model
{
    /** @use HasFactory<FoodOrderFactory> */
    use HasFactory;

    // status/menu deliberately NOT fillable: status is a lifecycle field
    // driven by Actions, and menu is structured data that must go through
    // the typed MenuCast rather than a mass-assigned raw array/string
    // (see roadmap insight #9 on Filament's KeyValue mangling jsonb types).
    protected $fillable = [
        'event_id',
        'restaurant_id',
        'title',
        'opens_at',
        'closes_at',
        'payment_method',
        'payment_link',
        'included_ticket_types',
    ];

    protected function casts(): array
    {
        return [
            'menu' => MenuCast::class,
            'status' => FoodOrderStatus::class,
            'payment_method' => FoodPaymentMethod::class,
            'included_ticket_types' => 'array',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
        ];
    }

    public function isOpenNow(): bool
    {
        if ($this->status !== FoodOrderStatus::Open) {
            return false;
        }

        $now = now();

        if ($this->opens_at !== null && $now->lt($this->opens_at)) {
            return false;
        }

        if ($this->closes_at !== null && $now->gt($this->closes_at)) {
            return false;
        }

        return true;
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** @return BelongsTo<Restaurant, $this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * THE menu of this round: the restaurant's when the round has one, otherwise the legacy
     * `menu` column of rounds that predate restaurants. Every reader goes through here.
     *
     * @return list<MenuOption>
     */
    public function menuOptions(): array
    {
        if ($this->restaurant_id === null) {
            return $this->menu;
        }

        $restaurant = $this->restaurant;

        return $restaurant instanceof Restaurant ? $restaurant->menu : [];
    }

    /** @return HasMany<FoodOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(FoodOrderItem::class);
    }

    protected static function newFactory(): FoodOrderFactory
    {
        return FoodOrderFactory::new();
    }
}
