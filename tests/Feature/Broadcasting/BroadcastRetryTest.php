<?php

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\File;

// F-S6-1: a transient Reverb outage must not lose a broadcast (queue --tries=1 used to drop it).
it('gives every broadcast event three tries with a backoff', function () {
    $classes = collect(File::allFiles(app_path()))
        ->map(function ($file): ?string {
            $source = file_get_contents($file->getPathname());

            if (! preg_match('/^namespace (.+);/m', $source, $ns) || ! preg_match('/^class (\w+)/m', $source, $cls)) {
                return null;
            }

            return $ns[1].'\\'.$cls[1];
        })
        ->filter(fn (?string $class): bool => $class !== null && class_exists($class) && is_subclass_of($class, ShouldBroadcast::class))
        ->values();

    expect($classes)->not->toBeEmpty();

    foreach ($classes as $class) {
        $ref = new ReflectionClass($class);
        $defaults = $ref->getDefaultProperties();

        expect($defaults['tries'] ?? null)->toBe(3, "{$class} needs \$tries = 3")
            ->and($defaults['backoff'] ?? null)->toBeArray("{$class} needs a \$backoff");
    }
});
