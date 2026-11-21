<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The single settings row. `strobe_allowed` null follows the deployment default
 * (`lighting.allow_strobe`).
 *
 * @property int $id
 * @property bool|null $strobe_allowed
 */
class LightingSetting extends Model
{
    private const SINGLETON_ID = 1;

    protected $table = 'lighting_settings';

    /** @var list<string> */
    protected $fillable = ['strobe_allowed'];

    public static function current(): self
    {
        $setting = self::query()->find(self::SINGLETON_ID);

        if ($setting === null) {
            self::query()->insertOrIgnore(['id' => self::SINGLETON_ID, 'created_at' => now(), 'updated_at' => now()]);
            $setting = self::query()->findOrFail(self::SINGLETON_ID);
        }

        return $setting;
    }

    public static function strobeAllowed(): bool
    {
        $override = self::current()->strobe_allowed;

        return $override ?? (bool) config('lighting.allow_strobe');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['strobe_allowed' => 'boolean'];
    }
}
