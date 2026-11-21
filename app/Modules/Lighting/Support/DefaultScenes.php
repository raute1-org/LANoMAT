<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Support;

use App\Modules\Lighting\Models\LightingScene;

/**
 * The scenes that ship with the module. Idempotent: matched by `key`, and an
 * existing row is left alone so orga edits survive a re-run.
 */
final class DefaultScenes
{
    /**
     * @return array<string, array{name: string, description: string, states: array<string, array<string, mixed>>|null, pattern: array<string, mixed>|null, blackout: bool}>
     */
    public static function definitions(): array
    {
        return [
            'party' => [
                'name' => 'Party',
                'description' => 'Bunt: Magenta und Cyan im Wechsel.',
                'states' => null,
                'pattern' => ['a' => '#ff00cc', 'b' => '#00ccff', 'brightness' => 1],
                'blackout' => false,
            ],
            'calm' => [
                'name' => 'Ruhig',
                'description' => 'Warmweiß, gedimmt.',
                'states' => ['all' => ['hex' => '#ff9a3c', 'brightness' => 0.35, 'temperature' => 0, 'strobe' => 0]],
                'pattern' => null,
                'blackout' => false,
            ],
            'red_alert' => [
                'name' => 'Rot-Alarm',
                'description' => 'Alle Strahler in kräftigem Rot, ohne Blitz.',
                'states' => ['all' => ['hex' => '#ff0000', 'brightness' => 1, 'strobe' => 0]],
                'pattern' => null,
                'blackout' => false,
            ],
            'tournament_final' => [
                'name' => 'Turnierfinale',
                'description' => 'Teamfarben-Muster: Blau gegen Rot.',
                'states' => null,
                'pattern' => ['a' => '#1e50ff', 'b' => '#ff2020', 'brightness' => 1],
                'blackout' => false,
            ],
            'off' => [
                'name' => 'Aus',
                'description' => 'Alles aus.',
                'states' => null,
                'pattern' => null,
                'blackout' => true,
            ],
        ];
    }

    public static function seed(): void
    {
        foreach (self::definitions() as $key => $def) {
            if (LightingScene::query()->where('key', $key)->exists()) {
                continue;
            }

            $scene = new LightingScene($def);
            $scene->key = $key;
            $scene->is_system = true;
            $scene->save();
        }
    }
}
