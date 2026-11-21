<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Models;

use App\Modules\Lighting\Enums\LightingTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property LightingTrigger $trigger
 * @property int $lighting_scene_id
 * @property bool $active
 * @property int $cooldown_seconds
 * @property int|null $event_id
 * @property int|null $tournament_id
 * @property Carbon|null $last_fired_at
 */
class LightingRule extends Model
{
    /** @var list<string> */
    protected $fillable = ['trigger', 'lighting_scene_id', 'active', 'cooldown_seconds', 'event_id', 'tournament_id'];

    /** @return BelongsTo<LightingScene, $this> */
    public function scene(): BelongsTo
    {
        return $this->belongsTo(LightingScene::class, 'lighting_scene_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'trigger' => LightingTrigger::class,
            'active' => 'boolean',
            'cooldown_seconds' => 'integer',
            'event_id' => 'integer',
            'tournament_id' => 'integer',
            'last_fired_at' => 'datetime',
        ];
    }
}
