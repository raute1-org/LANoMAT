<?php

declare(strict_types=1);

namespace App\Modules\Games\Contracts;

/**
 * The Pelican eggs an orga can pick for a server preset, as the Games admin
 * form needs them. Games must not depend on the optional GameServers module
 * (architecture ratchet), so the form asks this contract; GameServers binds the
 * panel-backed implementation, everything else gets {@see NullEggCatalog}.
 */
interface EggCatalog
{
    /**
     * `uuid => name`, or null when the catalog is not available (module off,
     * panel unconfigured or unreachable): the form then falls back to a plain
     * text input for the uuid.
     *
     * @return array<string, string>|null
     */
    public function options(): ?array;
}
