<?php

namespace App\Modules\Discord\Interactions\Commands;

use App\Modules\Discord\Interactions\CommandDefinitions;
use App\Modules\Discord\Interactions\InteractionResponse;

/**
 * `/help` — static help text listing the available slash commands. No
 * action/query to wrap; the copy lives in lang/de/discord.php.
 *
 * `/ask` is registered guild-only by the gateway sidecar (it needs the
 * discord.js reply path and pi/MCP), not in {@see CommandDefinitions}.
 * It is only advertised while the sidecar's agent is enabled.
 */
class HelpCommand
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        $text = __('discord.help.text');

        if ((bool) config('services.discord.agent_enabled')) {
            $text .= "\n".__('discord.help.agent');
        }

        return InteractionResponse::message($text);
    }
}
