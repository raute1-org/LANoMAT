<?php

namespace App\Modules\Games\Models;

use App\Modules\Games\Casts\ServerConfigCast;
use App\Modules\Games\Casts\ServerPresetsCast;
use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Games\Domain\ServerPreset;
use App\Modules\Tournaments\Models\Tournament;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * @property string $name
 * @property string $slug
 * @property string|null $icon_path
 * @property int $min_team_size
 * @property int $max_team_size
 * @property string|null $pelican_egg_id
 * @property ServerConfig $default_server_config
 * @property list<ServerPreset> $server_presets
 */
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    // default_server_config and server_presets deliberately NOT fillable:
    // both are structured data that must go through their typed casts rather
    // than a mass-assigned raw array (mirrors InfoscreenScene::$config and
    // FoodOrder::$menu — see roadmap insight #9 on Filament's KeyValue
    // mangling jsonb types).
    protected $fillable = [
        'name',
        'slug',
        'icon_path',
        'min_team_size',
        'max_team_size',
        'pelican_egg_id',
    ];

    protected function casts(): array
    {
        return [
            'min_team_size' => 'integer',
            'max_team_size' => 'integer',
            'default_server_config' => ServerConfigCast::class,
            'server_presets' => ServerPresetsCast::class,
        ];
    }

    /** @return HasMany<Tournament, $this> */
    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (self $game) {
            if ($game->icon_path !== null) {
                Storage::disk('public')->delete($game->icon_path);
            }
        });
    }

    /**
     * Looks up a preset by key, used by
     * `App\Modules\GameServers\Support\EffectiveConfig::resolve()`
     * (referenced by name only, not imported, to avoid a Games ->
     * GameServers module dependency; see CLAUDE.md's modular-monolith
     * rule).
     */
    public function findPreset(string $key): ?ServerPreset
    {
        foreach ($this->server_presets as $preset) {
            if ($preset->key === $key) {
                return $preset;
            }
        }

        return null;
    }

    protected static function newFactory(): GameFactory
    {
        return GameFactory::new();
    }
}
