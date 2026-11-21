<?php

use App\Modules\Assistant\Support\SecretScrubber;

it('hides the value after a secret key and keeps the key', function () {
    $out = SecretScrubber::scrub('rcon_password "abc"; sv_password x');

    expect($out)->toContain('rcon_password')->toContain('sv_password')
        ->not->toContain('abc')
        ->not->toContain(' x');
});

it('handles key=value, key: value, mixed case and bearer tokens', function () {
    $out = SecretScrubber::scrub("SERVER_PASSWORD=hunter2\nPassword: letmein\nAuthorization: Bearer abc.def-ghi\napi_key=zzz9");

    expect($out)->not->toContain('hunter2')->not->toContain('letmein')->not->toContain('abc.def-ghi')->not->toContain('zzz9')
        ->and($out)->toContain('SERVER_PASSWORD=***');
});

it('leaves ordinary log lines alone', function () {
    expect(SecretScrubber::scrub('[Server] Done, loaded map de_dust2'))->toBe('[Server] Done, loaded map de_dust2');
});
