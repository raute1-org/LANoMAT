<?php

namespace App\Modules\Discord\Interactions\Commands;

use App\Modules\Discord\Interactions\InteractionResponse;

/**
 * `/help` — static help text listing the available slash commands. No
 * action/query to wrap; the copy lives in lang/de/discord.php.
 */
class HelpCommand
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        return InteractionResponse::message(__('discord.help.text'));
    }
}
