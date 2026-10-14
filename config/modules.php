<?php

use App\Support\Modules\Module;

/*
| Deploy-time module toggles. MODULES_PROFILE: `core` (default — only the core
| modules) or `full` (everything, = pre-toggle behaviour). Individual modules
| can be forced with MODULE_<KEY>=true|false (e.g. MODULE_JUKEBOX=true,
| MODULE_CUSTOM_SERVERS=false). See docs/superpowers/specs/2026-10-14-module-toggles-design.md.
*/
return [
    'profile' => env('MODULES_PROFILE', 'core'),

    'overrides' => collect(Module::cases())
        ->mapWithKeys(fn (Module $m) => [$m->key() => env('MODULE_'.strtoupper($m->key()))])
        ->filter(fn ($value) => $value !== null)
        ->map(fn ($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN))
        ->all(),
];
