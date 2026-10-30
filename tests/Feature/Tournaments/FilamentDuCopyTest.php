<?php

// F-S4-13: the admin panel speaks "du" like the rest of the app.
it('overrides the formal Filament confirmation and alert copy with du', function (string $key) {
    app()->setLocale('de');

    $text = __($key, ['seconds' => 5]);

    expect($text)->not->toBe($key)
        ->and($text)->not->toMatch('/\b(Sie|Ihr\w*|Sind Sie)\b/u');
})->with([
    'filament-actions::modal.confirmation',
    'filament-actions::notifications.throttled.body',
    'filament-panels::unsaved-changes-alert.body',
    'filament-panels::error-notifications.body',
    'filament-forms::components.select.placeholder',
    'filament-forms::components.select.search_prompt',
    'filament-notifications::database.modal.empty.description',
]);

it('keeps the du confirmation text', function () {
    app()->setLocale('de');

    expect(__('filament-actions::modal.confirmation'))->toBe('Bist du sicher, dass du das tun möchtest?');
});
