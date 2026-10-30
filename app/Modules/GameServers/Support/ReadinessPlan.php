<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use App\Modules\Games\Domain\ServerConfig;
use App\Modules\GameServers\Models\ServerLink;

/**
 * The preset settings the poll and readiness jobs need after provisioning,
 * frozen onto the {@see ServerLink} at creation
 * so a later preset edit cannot change the checks of a server that is already
 * starting.
 *
 * @phpstan-type Snapshot array{
 *     type: string|null,
 *     game: string|null,
 *     folder: string|null,
 *     timeout: int,
 *     install_marker: string|null,
 *     connect_template: string|null,
 *     install_verified: bool
 * }
 */
final class ReadinessPlan
{
    /**
     * @return Snapshot
     */
    public static function snapshot(ServerConfig $config): array
    {
        return [
            'type' => $config->usesA2sReadiness() ? 'a2s' : null,
            'game' => $config->readinessGame,
            'folder' => $config->readinessFolder,
            'timeout' => $config->readinessTimeout ?? (int) config('services.pelican.readiness_timeout', 180),
            'install_marker' => $config->installMarker,
            'connect_template' => $config->connectTemplate,
            'install_verified' => false,
        ];
    }

    /**
     * Fills `{ip}`, `{port}` and `{password}` into a connect template. A template
     * that needs a value that is not known renders as null (the caller then falls
     * back to the default link).
     */
    public static function renderConnect(?string $template, ?string $address, ?int $port, ?string $password = null): ?string
    {
        if ($template === null || $template === '' || $address === null || $port === null) {
            return null;
        }

        if (str_contains($template, '{password}') && ($password === null || $password === '')) {
            return null;
        }

        return str_replace(['{ip}', '{port}', '{password}'], [$address, (string) $port, (string) $password], $template);
    }
}
