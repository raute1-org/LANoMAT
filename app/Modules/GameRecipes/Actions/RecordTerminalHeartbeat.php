<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Actions;

use App\Modules\GameRecipes\Enums\TerminalState;
use App\Modules\GameRecipes\Models\LauncherTerminal;
use App\Modules\Mcp\Models\McpToken;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;

/**
 * Upsert of one terminal from its heartbeat. The authenticated token is the
 * identity, so repeated heartbeats update the same row; a re-paired token
 * (revoked and minted again) creates a new one.
 *
 * The machine address and the timestamp are server facts, never client
 * claims: a launcher cannot make itself look online by sending a future
 * `last_seen_at`.
 */
class RecordTerminalHeartbeat
{
    /**
     * @param  array<string, mixed>  $attributes  Validated heartbeat fields.
     */
    public function handle(McpToken $token, array $attributes, ?string $ip): LauncherTerminal
    {
        $terminal = LauncherTerminal::query()
            ->where('mcp_token_id', $token->getKey())
            ->first();

        if ($terminal === null) {
            $terminal = new LauncherTerminal;
            $terminal->forceFill(['mcp_token_id' => $token->getKey()]);
        }

        $this->apply($terminal, $attributes, $ip);

        try {
            $terminal->save();
        } catch (UniqueConstraintViolationException) {
            // Two concurrent heartbeats for a freshly paired token raced past
            // the unique index; the loser re-reads and updates the winner.
            $terminal = LauncherTerminal::query()
                ->where('mcp_token_id', $token->getKey())
                ->firstOrFail();

            $this->apply($terminal, $attributes, $ip);
            $terminal->save();
        }

        return $terminal;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function apply(LauncherTerminal $terminal, array $attributes, ?string $ip): void
    {
        $terminal->fill(Arr::only($attributes, [
            'name',
            'os',
            'arch',
            'version',
            'current_game_slug',
            'installed',
            'gamepad',
        ]));

        $terminal->state = $this->state($attributes['state'] ?? null);
        $terminal->ip = $ip;
        $terminal->last_seen_at = now();
    }

    private function state(mixed $reported): TerminalState
    {
        if ($reported instanceof TerminalState) {
            return $reported;
        }

        if (is_string($reported) && ($state = TerminalState::tryFrom($reported)) instanceof TerminalState) {
            return $state;
        }

        // A heartbeat without a state claim means "alive and idle-ready".
        return TerminalState::Online;
    }
}
