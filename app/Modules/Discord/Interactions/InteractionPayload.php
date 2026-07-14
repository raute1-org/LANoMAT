<?php

namespace App\Modules\Discord\Interactions;

use App\Models\User;

/**
 * Thin read helpers over a raw Discord interaction payload array — mapping
 * the invoking Discord user to a local {@see User}, and pulling subcommand
 * options out of the nested `data.options` shape Discord sends for slash
 * commands with subcommands.
 *
 * @see https://docs.discord.com/developers/interactions/application-commands#subcommands-and-subcommand-groups
 */
class InteractionPayload
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function discordUserId(array $payload): ?string
    {
        $id = $payload['member']['user']['id'] ?? $payload['user']['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function mappedUser(array $payload): ?User
    {
        $discordId = self::discordUserId($payload);

        if ($discordId === null) {
            return null;
        }

        return User::query()->where('discord_id', $discordId)->first();
    }

    /**
     * The name of the invoked subcommand, e.g. "list" for `/tournament list`.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function subcommand(array $payload): ?string
    {
        $options = $payload['data']['options'] ?? [];
        $first = $options[0] ?? null;

        return is_array($first) && is_string($first['name'] ?? null) ? $first['name'] : null;
    }

    /**
     * An option value nested under the invoked subcommand (type 1), e.g. the
     * `id` option of `/tournament checkin <id>`.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function subcommandOption(array $payload, string $name): mixed
    {
        $options = $payload['data']['options'] ?? [];
        $sub = $options[0] ?? null;

        if (! is_array($sub)) {
            return null;
        }

        foreach ($sub['options'] ?? [] as $option) {
            if (is_array($option) && ($option['name'] ?? null) === $name) {
                return $option['value'] ?? null;
            }
        }

        return null;
    }
}
