<?php

namespace App\Modules\Catering\Models;

use App\Modules\Catering\Casts\MenuCast;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Enums\FoodPaymentMethod;
use Database\Factories\RestaurantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A restaurant or delivery service, spanning events. It owns the menu (dish keys are stable
 * and referenced by ordered items); a FoodOrder is one ordering round that points at it.
 *
 * The menu is stored as jsonb of MenuOption shapes. Each dish is an object so optional
 * fields (for example a future `choices` list for set menus) can be added without a migration.
 *
 * @property list<MenuOption> $menu
 * @property FoodPaymentMethod|null $default_payment_method
 * @property string|null $default_payment_link
 * @property string|null $logo
 */
class Restaurant extends Model
{
    /** @use HasFactory<RestaurantFactory> */
    use HasFactory;

    /** Pattern of a stored logo path: the same content-addressed WebP files as dish photos. */
    public const LOGO_PATH_PATTERN = MenuOption::IMAGE_PATH_PATTERN;

    // menu and logo are NOT fillable: the menu goes through the menu-write action (ordered-dish
    // protection) and the logo through the image store.
    protected $fillable = [
        'name',
        'slug',
        'description',
        'phone',
        'address',
        'website',
        'order_url',
        'default_payment_method',
        'default_payment_link',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'menu' => MenuCast::class,
            'default_payment_method' => FoodPaymentMethod::class,
        ];
    }

    /** @return HasMany<FoodOrder, $this> */
    public function foodOrders(): HasMany
    {
        return $this->hasMany(FoodOrder::class);
    }

    /**
     * Participants may see a restaurant (and its images) when a published round of a publicly
     * visible event uses it. Drafts never count.
     */
    public function isShownToParticipants(): bool
    {
        return $this->foodOrders()
            ->where('status', '!=', FoodOrderStatus::Draft->value)
            ->whereHas('event', fn (Builder $events) => $events->publiclyVisible())
            ->exists();
    }

    public function setLogoAttribute(?string $value): void
    {
        if ($value !== null && preg_match(self::LOGO_PATH_PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException('Restaurant logo is not an image store path.');
        }

        $this->attributes['logo'] = $value;
    }

    /**
     * Ordered positions per dish key over all rounds of this restaurant.
     *
     * @return array<string, int>
     */
    public function orderedCounts(): array
    {
        if (! $this->exists) {
            return [];
        }

        /** @var array<string, int> $counts */
        $counts = FoodOrderItem::query()
            ->join('food_orders', 'food_orders.id', '=', 'food_order_items.food_order_id')
            ->where('food_orders.restaurant_id', $this->getKey())
            ->selectRaw("food_order_items.selection->>'option_key' as option_key, count(*) as total")
            ->groupBy(DB::raw("food_order_items.selection->>'option_key'"))
            ->pluck('total', 'option_key')
            ->map(fn (mixed $n): int => (int) $n)
            ->all();

        return $counts;
    }

    /**
     * Open (unpaid, not included) positions per dish key: the ones a price change can reach.
     *
     * @return array<string, int>
     */
    public function openCounts(): array
    {
        if (! $this->exists) {
            return [];
        }

        /** @var array<string, int> $counts */
        $counts = FoodOrderItem::query()
            ->join('food_orders', 'food_orders.id', '=', 'food_order_items.food_order_id')
            ->where('food_orders.restaurant_id', $this->getKey())
            ->whereNull('food_order_items.paid_at')
            ->where('food_order_items.included', false)
            ->selectRaw("food_order_items.selection->>'option_key' as option_key, count(*) as total")
            ->groupBy(DB::raw("food_order_items.selection->>'option_key'"))
            ->pluck('total', 'option_key')
            ->map(fn (mixed $n): int => (int) $n)
            ->all();

        return $counts;
    }

    protected static function newFactory(): RestaurantFactory
    {
        return RestaurantFactory::new();
    }
}
