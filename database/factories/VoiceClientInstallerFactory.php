<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Voice\Domain\VoiceClientPlatform;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Models\VoiceClientInstaller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VoiceClientInstaller>
 */
class VoiceClientInstallerFactory extends Factory
{
    protected $model = VoiceClientInstaller::class;

    public function definition(): array
    {
        $version = $this->faker->numerify('#.#.#');
        $originalName = 'installer-'.$version.'.exe';

        return [
            'provider' => VoiceProvider::Mumble->value,
            'platform' => VoiceClientPlatform::Windows->value,
            'version' => $version,
            'path' => 'voice-installers/'.$this->faker->uuid().'-'.$originalName,
            'original_name' => $originalName,
            'is_current' => false,
        ];
    }

    public function current(): static
    {
        return $this->state(['is_current' => true]);
    }
}
