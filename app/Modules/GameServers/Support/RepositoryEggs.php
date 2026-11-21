<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * The Pelican eggs LANoMAT ships in `resources/pelican-eggs/` (YAML PLCN_v3, or JSON exports).
 * The panel matches an imported egg by `uuid`, so that is the identity presets
 * and the import command use; numeric egg ids differ per panel.
 */
final class RepositoryEggs
{
    /** uuid of the LANoMAT "Garrys Mod" fork, referenced by the seeded presets. */
    public const string GMOD_UUID = '7b079b4f-ab83-40ef-98fc-059b4a270688';

    /** uuid of the community "Mordhau" egg (pelican-eggs/games-steamcmd), referenced by the seeded presets. */
    public const string MORDHAU_UUID = 'bc531988-8d28-45fb-9220-19b7ae2a1988';

    /**
     * @return list<array{file: string, uuid: string, name: string, content: string}>
     */
    public static function all(?string $directory = null): array
    {
        $directory ??= resource_path('pelican-eggs');
        $eggs = [];

        foreach (glob($directory.'/*.{yaml,yml,json}', GLOB_BRACE) ?: [] as $path) {
            $content = (string) file_get_contents($path);
            // A `.json` egg (the PTDL_v2 exports) is read as JSON: its scripts carry escapes the YAML flow parser may reject.
            $parsed = str_ends_with($path, '.json') ? json_decode($content, true) : Yaml::parse($content);

            if (! is_array($parsed) || ! is_string($parsed['uuid'] ?? null) || ! is_string($parsed['name'] ?? null)) {
                throw new RuntimeException('Egg file '.basename($path).' has no uuid or name.');
            }

            $eggs[] = ['file' => basename($path), 'uuid' => $parsed['uuid'], 'name' => $parsed['name'], 'content' => $content];
        }

        usort($eggs, static fn (array $a, array $b): int => $a['file'] <=> $b['file']);

        return $eggs;
    }
}
