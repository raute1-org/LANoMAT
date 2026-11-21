<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Models;

use App\Models\User;
use App\Modules\GameRecipes\Enums\InstallStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audit row for one install plan the agent requested. The plan itself is
 * stored so orga can see what a participant was told to run and reproduce a
 * failure, without depending on the agent's local logs.
 *
 * @property int $game_recipe_id
 * @property int|null $user_id
 * @property string|null $os
 * @property InstallStatus $status
 * @property array<string, mixed>|null $plan
 * @property string|null $notes
 * @property Carbon|null $created_at
 */
class GameRecipeInstall extends Model
{
    protected $fillable = [
        'game_recipe_id',
        'user_id',
        'os',
        'status',
        'plan',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstallStatus::class,
            'plan' => 'array',
        ];
    }

    /** @return BelongsTo<GameRecipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(GameRecipe::class, 'game_recipe_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
