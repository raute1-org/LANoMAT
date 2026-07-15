<?php

namespace App\Modules\Discord\Interactions;

use App\Modules\Discord\Interactions\Commands\HelpCommand;
use App\Modules\Discord\Interactions\Commands\LfgCommand;
use App\Modules\Discord\Interactions\Commands\ScheduleCommand;
use App\Modules\Discord\Interactions\Commands\TournamentCommand;

class CommandRouter
{
    /**
     * Command name => handler class-string map.
     *
     * Handlers are invoked as `$handler->handle(array $payload): array` and
     * must return a Discord interaction response body.
     *
     * @return array<string, class-string>
     */
    private static function commandMap(): array
    {
        return [
            'tournament' => TournamentCommand::class,
            'help' => HelpCommand::class,
            'schedule' => ScheduleCommand::class,
            'lfg' => LfgCommand::class,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function dispatch(array $payload): array
    {
        $name = $payload['data']['name'] ?? null;
        $map = self::commandMap();

        if (! is_string($name) || ! array_key_exists($name, $map)) {
            return self::unknownCommandResponse();
        }

        $handlerClass = $map[$name];
        $handler = app($handlerClass);

        return $handler->handle($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private static function unknownCommandResponse(): array
    {
        return [
            'type' => InteractionResponseType::ChannelMessageWithSource->value,
            'data' => [
                'content' => __('discord.unknown_command'),
            ],
        ];
    }
}
