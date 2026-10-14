<?php

namespace App\Support\Modules;

final class Modules
{
    /** @var array<string, true> */
    private array $enabled = [];

    /**
     * @param  array<string, bool>  $overrides  keyed by Module::key()
     * @param  bool  $exposeAllRoutes  true only while `wayfinder:generate` runs, see routable()
     */
    public function __construct(
        string $profile,
        array $overrides = [],
        private readonly bool $exposeAllRoutes = false,
    ) {
        $problems = [];

        if (! in_array($profile, ['core', 'full'], true)) {
            $problems[] = "Unknown MODULES_PROFILE \"{$profile}\" (expected core or full).";
        }

        foreach (Module::cases() as $module) {
            $on = $profile === 'full' || $module->isCore();

            if (array_key_exists($module->key(), $overrides)) {
                $wanted = $overrides[$module->key()];

                if ($module->isCore() && $wanted === false) {
                    $problems[] = "Core module \"{$module->key()}\" cannot be disabled.";
                } else {
                    $on = $wanted;
                }
            }

            if ($on) {
                $this->enabled[$module->key()] = true;
            }
        }

        foreach (Module::cases() as $module) {
            if (! $this->enabled($module)) {
                continue;
            }

            foreach ($module->requires() as $required) {
                if (! $this->enabled($required)) {
                    $problems[] = "Module \"{$module->key()}\" requires \"{$required->key()}\", which is disabled.";
                }
            }
        }

        if ($problems !== []) {
            throw new InvalidModuleConfiguration($problems);
        }
    }

    public function enabled(Module $module): bool
    {
        return isset($this->enabled[$module->key()]);
    }

    /**
     * Whether the module's routes are registered. Identical to enabled(),
     * except while Wayfinder generates the TypeScript route helpers: the
     * frontend bundle imports helpers for every module and must compile under
     * every profile; hiding happens at runtime via the `modules` Inertia prop.
     */
    public function routable(Module $module): bool
    {
        return $this->exposeAllRoutes || $this->enabled($module);
    }

    /** @return list<Module> */
    public function enabledModules(): array
    {
        return array_values(array_filter(Module::cases(), fn (Module $m) => $this->enabled($m)));
    }
}
