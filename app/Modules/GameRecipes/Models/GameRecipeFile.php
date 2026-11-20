<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Models;

use App\Modules\Files\Models\SharedFile;
use App\Modules\GameRecipes\Enums\RecipeFileRole;
use Database\Factories\GameRecipeFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file a recipe needs (ISO, patch, mod, tool) or offers (avatar, content).
 *
 * `match_patterns` are case-insensitive glob patterns matched against the
 * original filename of approved SharedFiles on the LAN file host. `metadata`
 * carries the human facts the installer shows for avatars (author, license,
 * preview) without a second table.
 *
 * @property RecipeFileRole $role
 * @property string $label
 * @property list<string>|null $match_patterns
 * @property int|null $size_bytes
 * @property string|null $sha256
 * @property string|null $url
 * @property bool $required
 * @property int $sort
 * @property array<string, mixed>|null $metadata
 * @property int|null $shared_file_id
 */
class GameRecipeFile extends Model
{
    /** @use HasFactory<GameRecipeFileFactory> */
    use HasFactory;

    protected $fillable = [
        'game_recipe_id',
        'role',
        'label',
        'match_patterns',
        'size_bytes',
        'sha256',
        'url',
        'required',
        'sort',
        'metadata',
        'shared_file_id',
    ];

    protected function casts(): array
    {
        return [
            'role' => RecipeFileRole::class,
            'match_patterns' => 'array',
            'size_bytes' => 'integer',
            'required' => 'boolean',
            'sort' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<GameRecipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(GameRecipe::class, 'game_recipe_id');
    }

    /** @return BelongsTo<SharedFile, $this> */
    public function sharedFile(): BelongsTo
    {
        return $this->belongsTo(SharedFile::class);
    }

    public function isAvatar(): bool
    {
        return $this->role->isSelectable();
    }

    /** @return list<string> */
    public function patterns(): array
    {
        return array_values(array_filter(array_map('strval', $this->match_patterns ?? [])));
    }

    protected static function newFactory(): GameRecipeFileFactory
    {
        return GameRecipeFileFactory::new();
    }
}
