<?php

declare(strict_types=1);

namespace App\Modules\RoomAudio\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The room's single settings row. `volume_ceiling` caps every volume command
 * (0..98); the row is created on first access with the default ceiling.
 *
 * @property int $id
 * @property int $volume_ceiling
 */
class RoomAudioSetting extends Model
{
    public const DEFAULT_VOLUME_CEILING = 60;

    private const SINGLETON_ID = 1;

    protected $table = 'room_audio_settings';

    /** @var list<string> */
    protected $fillable = ['volume_ceiling'];

    public static function current(): self
    {
        $setting = self::query()->find(self::SINGLETON_ID);

        if ($setting === null) {
            // `id` is not fillable, so the row is inserted explicitly with
            // insertOrIgnore: a concurrent first call cannot create a second row.
            self::query()->insertOrIgnore([
                'id' => self::SINGLETON_ID,
                'volume_ceiling' => self::DEFAULT_VOLUME_CEILING,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $setting = self::query()->findOrFail(self::SINGLETON_ID);
        }

        return $setting;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['volume_ceiling' => 'integer'];
    }
}
