<?php

namespace App\Modules\Discord\Interactions;

class CommandRouter
{
    /**
     * Command name => handler class-string map.
     *
     * Handlers are invoked as `$handler->handle(array $payload): array` and
     * must return a Discord interaction response body. Empty for now — filled
     * in by a later task once the first slash commands are implemented.
     *
     * @return array<string, class-string>
     */
    private static function commandMap(): array
    {
        return [];
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
