<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\GameRecipes\Enums\TerminalState;
use App\Modules\GameRecipes\Models\LauncherTerminal;
use App\Modules\Mcp\Models\McpToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LauncherTerminal>
 */
class LauncherTerminalFactory extends Factory
{
    protected $model = LauncherTerminal::class;

    public function definition(): array
    {
        return [
            'mcp_token_id' => null,
            'name' => 'Kabinett '.fake()->unique()->numberBetween(1, 99),
            'os' => 'windows',
            'arch' => 'x86_64',
            'version' => '1.0.0',
            'state' => TerminalState::Idle,
            'current_game_slug' => null,
            'installed' => [],
            'gamepad' => false,
            'last_seen_at' => now(),
            'ip' => fake()->localIpv4(),
        ];
    }

    /** Pair the row with the token the launcher authenticates with. */
    public function pairedTo(McpToken $token): static
    {
        return $this->state(['mcp_token_id' => $token->getKey()]);
    }

    public function online(): static
    {
        return $this->state([
            'state' => TerminalState::Online,
            'last_seen_at' => now()->subSeconds(5),
        ]);
    }

    public function idle(): static
    {
        return $this->state([
            'state' => TerminalState::Idle,
            'current_game_slug' => null,
            'last_seen_at' => now()->subSeconds(10),
        ]);
    }

    public function playing(string $slug = 'unreal-tournament-2004'): static
    {
        return $this->state([
            'state' => TerminalState::Playing,
            'current_game_slug' => $slug,
            'last_seen_at' => now()->subSeconds(5),
        ]);
    }

    /** Heartbeat too old: derived state is offline regardless of what was reported. */
    public function stale(): static
    {
        return $this->state([
            'last_seen_at' => now()->subMinutes(5),
        ]);
    }

    /** @param list<string> $slugs */
    public function withInstalled(array $slugs): static
    {
        return $this->state(['installed' => $slugs]);
    }

    public function withGamepad(): static
    {
        return $this->state(['gamepad' => true]);
    }
}
