<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The Pelican eggs LANoMAT ships in `resources/pelican-eggs/` (YAML, PLCN_v3).
 * The panel matches an imported egg by `uuid`, so that is the identity presets
 * and the import command use; numeric egg ids differ per panel.
 */
final class RepositoryEggs
{
    /** uuid of the LANoMAT "Garrys Mod" fork, referenced by the seeded presets. */
    public const string GMOD_UUID = '7b079b4f-ab83-40ef-98fc-059b4a270688';

    /**
     * @return list<array{file: string, uuid: string, name: string, content: string}>
     */
    public static function all(?string $directory = null): array
    {
        $directory ??= resource_path('pelican-eggs');
        $eggs = [];

        foreach (glob($directory.'/*.{yaml,yml}', GLOB_BRACE) ?: [] as $path) {
            $content = (string) file_get_contents($path);
            $parsed = Yaml::parse($content);

            if (! is_array($parsed) || ! is_string($parsed['uuid'] ?? null) || ! is_string($parsed['name'] ?? null)) {
                throw new RuntimeException('Egg file '.basename($path).' has no uuid or name.');
            }

            $eggs[] = ['file' => basename($path), 'uuid' => $parsed['uuid'], 'name' => $parsed['name'], 'content' => $content];
        }

        usort($eggs, static fn (array $a, array $b): int => $a['file'] <=> $b['file']);

        return $eggs;
    }
}
