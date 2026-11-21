<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Support;

/**
 * The port slots of a server config: the main port first, then one slot per extra egg variable.
 * Extra variables are the config's `extra_ports` plus every `fixed_ports` key besides `main`
 * (a fixed port for a variable needs no separate `extra_ports` entry). Shared by the allocation
 * planner and {@see ServerSpecBuilder} so both agree on which allocation feeds which variable.
 */
final class PortSlots
{
    public const string MAIN = 'main';

    /**
     * @param  array<string, mixed>  $config
     * @return list<string> egg variable names, in allocation order after the main port
     */
    public static function variables(array $config): array
    {
        $variables = [];

        foreach (is_array($config['extra_ports'] ?? null) ? $config['extra_ports'] : [] as $variable) {
            $variables[] = (string) $variable;
        }

        foreach (array_keys(self::fixed($config)) as $slot) {
            if ($slot !== self::MAIN) {
                $variables[] = $slot;
            }
        }

        return array_values(array_unique($variables));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, int> slot (`main` or variable name) => fixed port
     */
    public static function fixed(array $config): array
    {
        $fixed = [];

        foreach (is_array($config['fixed_ports'] ?? null) ? $config['fixed_ports'] : [] as $slot => $port) {
            if (is_numeric($port)) {
                $fixed[(string) $slot] = (int) $port;
            }
        }

        return $fixed;
    }
}
