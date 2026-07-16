<?php

namespace Database\Factories;

use App\Modules\Events\Models\Event;
use App\Modules\Infoscreen\Domain\SceneConfig;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InfoscreenScene>
 */
class InfoscreenSceneFactory extends Factory
{
    protected $model = InfoscreenScene::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'type' => SceneType::Announcement,
            'config' => new SceneConfig(
                headline: 'Willkommen',
                body: 'Viel Spaß auf der LAN!',
            ),
            'duration_sec' => 15,
            'sort' => 0,
            'enabled' => true,
        ];
    }

    public function announcement(): static
    {
        return $this->state(fn (): array => [
            'type' => SceneType::Announcement,
            'config' => new SceneConfig(
                headline: 'Willkommen',
                body: 'Viel Spaß auf der LAN!',
            ),
        ]);
    }

    public function bracket(int $tournamentId): static
    {
        return $this->state(fn (): array => [
            'type' => SceneType::Bracket,
            'config' => new SceneConfig(tournamentId: $tournamentId),
        ]);
    }

    public function schedule(): static
    {
        return $this->state(fn (): array => [
            'type' => SceneType::Schedule,
            'config' => new SceneConfig,
        ]);
    }

    public function seatmap(): static
    {
        return $this->state(fn (): array => [
            'type' => SceneType::Seatmap,
            'config' => new SceneConfig,
        ]);
    }

    /**
     * @param  list<string>  $paths
     */
    public function sponsors(array $paths): static
    {
        return $this->state(fn (): array => [
            'type' => SceneType::Sponsors,
            'config' => new SceneConfig(sponsorLogoPaths: $paths),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }

    public function sort(int $n): static
    {
        return $this->state(['sort' => $n]);
    }
}
