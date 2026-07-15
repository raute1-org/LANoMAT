<?php

namespace App\Modules\Catering\Models;

use App\Models\User;
use Database\Factories\FoodOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property array{option_key: string, note?: string} $selection
 * @property int $price_cents
 * @property Carbon|null $paid_at
 */
class FoodOrderItem extends Model
{
    /** @use HasFactory<FoodOrderItemFactory> */
    use HasFactory;

    // paid_at deliberately NOT fillable — a privilege/state field set only
    // via an Action (payment confirmation), never client-supplied.
    protected $fillable = [
        'food_order_id',
        'user_id',
        'selection',
        'price_cents',
    ];

    protected function casts(): array
    {
        return [
            'selection' => 'array',
            'price_cents' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FoodOrder, $this> */
    public function foodOrder(): BelongsTo
    {
        return $this->belongsTo(FoodOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): FoodOrderItemFactory
    {
        return FoodOrderItemFactory::new();
    }
}
