<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Support;

/**
 * The native client's wire shape of a game server, built only from the participant web page's
 * own projection (`ServerListProjection::dto()`), never from the stored join info: whatever the
 * page would not show this user (a password outside the connect string, a RAM estimate, an
 * unlisted server) cannot reach the client through here.
 *
 * `joinUrl` is the `steam://` link the game offers, or null. `copyText` is what the client copies:
 * the connect string when it is plain text (it already carries the password where the server has
 * one), otherwise `address:port`. Both are only set while the server answers.
 */
final class NativeServerDto
{
    /**
     * @param  array<string, mixed>  $dto  a row of ServerListProjection
     * @return array<string, mixed>
     */
    public static function from(array $dto): array
    {
        $status = (string) ($dto['status'] ?? '');
        $address = self::string($dto['address'] ?? null);
        $port = isset($dto['port']) ? (int) $dto['port'] : null;
        $connectString = self::string($dto['connectString'] ?? null);
        $isLink = (bool) ($dto['connectIsLink'] ?? false);
        $query = is_array($dto['query'] ?? null) ? $dto['query'] : null;

        $ready = $status === 'ready';
        $joinable = $ready || $status === 'not_responding';

        $addressPort = $address === null ? null : ($port === null ? $address : $address.':'.$port);

        return [
            'id' => $dto['id'] ?? null,
            'title' => $dto['title'] ?? null,
            'game' => $dto['game'] ?? null,
            'matchLabel' => $dto['matchLabel'] ?? null,
            'description' => $dto['description'] ?? null,
            'status' => $status,
            'players' => $query === null ? null : (int) $query['players'],
            'maxPlayers' => $query === null ? null : (int) $query['maxPlayers'],
            'map' => $query === null ? null : (string) $query['map'],
            'checkedAt' => $query['checkedAt'] ?? null,
            'address' => $address,
            'port' => $port,
            'connectString' => $connectString,
            'connectIsLink' => $isLink,
            'installHint' => $dto['installHint'] ?? null,
            'joinUrl' => $ready && $isLink && $connectString !== null && str_starts_with($connectString, 'steam://') ? $connectString : null,
            'copyText' => $joinable ? ($connectString !== null && ! $isLink ? $connectString : $addressPort) : null,
        ];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
