<?php

namespace App\Modules\Discord\Console;

use App\Modules\Discord\Interactions\CommandDefinitions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class RegisterCommandsCommand extends Command
{
    protected $signature = 'discord:register-commands';

    protected $description = 'Bulk-overwrite the global Discord slash commands from the command definition list.';

    public function handle(): int
    {
        $applicationId = config('services.discord.application_id');
        $botToken = config('services.discord.bot_token');

        if (blank($applicationId) || blank($botToken)) {
            $this->components->error('DISCORD_APPLICATION_ID and DISCORD_BOT_TOKEN must be configured.');

            return self::FAILURE;
        }

        $commands = CommandDefinitions::all();

        // Discord expects the request body to be a JSON array (a numeric
        // list of command objects), not a JSON object — Http::put()'s typed
        // $data union has no shape for that, so the JSON is built explicitly
        // and sent as a raw body instead.
        Http::withHeaders(['Authorization' => "Bot {$botToken}"])
            ->withBody(json_encode($commands, JSON_THROW_ON_ERROR), 'application/json')
            ->acceptJson()
            ->put("https://discord.com/api/v10/applications/{$applicationId}/commands")
            ->throw();

        $this->components->info(sprintf('Registered %d Discord slash command(s).', count($commands)));

        return self::SUCCESS;
    }
}
