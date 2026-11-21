<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A lighting preset. `states` maps "all" or a fixture id (1..4) to a LightState
 * array; `pattern` is {a, b, brightness}; `blackout` switches everything off.
 *
 * @property int $id
 * @property string|null $key
 * @property string $name
 * @property string|null $description
 * @property array<array-key, mixed>|null $states
 * @property array<array-key, mixed>|null $pattern
 * @property bool $blackout
 * @property bool $is_system
 */
class LightingScene extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'description', 'states', 'pattern', 'blackout'];

    /** @return HasMany<LightingRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(LightingRule::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'states' => 'array',
            'pattern' => 'array',
            'blackout' => 'boolean',
            'is_system' => 'boolean',
        ];
    }
}
