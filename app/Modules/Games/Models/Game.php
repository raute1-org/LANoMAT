<?php

namespace App\Modules\Games\Models;

use App\Modules\Games\Casts\ServerConfigCast;
use App\Modules\Games\Domain\ServerConfig;
use App\Modules\Tournaments\Models\Tournament;
use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $name
 * @property string $slug
 * @property string|null $icon_path
 * @property int $min_team_size
 * @property int $max_team_size
 * @property string|null $pelican_egg_id
 * @property ServerConfig $default_server_config
 */
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    // default_server_config deliberately NOT fillable: it is structured data
    // that must go through the typed ServerConfigCast rather than a
    // mass-assigned raw array (mirrors InfoscreenScene::$config and
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
        ];
    }

    /** @return HasMany<Tournament, $this> */
    public function tournaments(): HasMany
    {
        return $this->hasMany(Tournament::class);
    }

    protected static function newFactory(): GameFactory
    {
        return GameFactory::new();
    }
}
