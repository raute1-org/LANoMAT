<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Console;

use App\Modules\Lighting\Models\LightingScene;
use App\Modules\Lighting\Support\ScenePlayer;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Console\Command;

class LightingSceneCommand extends Command
{
    protected $signature = 'lighting:scene {name : Scene name or key}';

    protected $description = 'Play a lighting scene (for testing; operator command, no rate limit).';

    public function handle(ScenePlayer $player, Modules $modules): int
    {
        if (! $modules->enabled(Module::Lighting)) {
            $this->error(__('lighting.console.module_off'));

            return self::FAILURE;
        }

        $arg = $this->argument('name');
        $name = is_string($arg) ? $arg : '';
        $scene = LightingScene::query()->where('key', $name)->orWhereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

        if ($scene === null) {
            $this->error("Scene not found: {$name}");

            return self::FAILURE;
        }

        $result = $player->play($scene);

        if (! $result->ok) {
            $this->warn($result->reachable ? __('lighting.errors.rejected') : __('lighting.errors.unreachable'));

            return self::FAILURE;
        }

        $this->info("Played: {$scene->name}");

        return self::SUCCESS;
    }
}
