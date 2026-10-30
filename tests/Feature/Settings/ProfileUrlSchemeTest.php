<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects non-https stream urls with a german message', function (string $url) {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Test User',
        'email' => $user->email,
        'stream_url' => $url,
    ]);

    $response->assertSessionHasErrors(['stream_url' => 'Die Stream-URL muss ein https://-Link sein.']);
    expect($user->fresh()->stream_url)->toBeNull();
})->with([
    'http' => 'http://twitch.tv/x',
    'ftp' => 'ftp://example.com/x',
    'data' => 'data://text/plain;base64,SGVsbG8=',
    'javascript' => 'javascript:alert(1)',
    'mixed case http' => 'HTTP://twitch.tv/x',
]);

it('rejects non-steam or non-https steam urls', function (string $url) {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Test User',
        'email' => $user->email,
        'steam_url' => $url,
    ])->assertSessionHasErrors('steam_url');

    expect($user->fresh()->steam_url)->toBeNull();
})->with([
    'http steam' => 'http://steamcommunity.com/id/x',
    'data' => 'data://text/plain;base64,SGVsbG8=',
    'other host' => 'https://evil.example/steamcommunity.com/',
    'lookalike host' => 'https://steamcommunity.com.evil.example/id/x',
]);

it('returns german validation messages for the profile form', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->patch(route('profile.update'), [
        'name' => '',
        'email' => 'kein-email',
    ]);

    $response->assertSessionHasErrors([
        'name' => 'Der Name ist erforderlich.',
        'email' => 'Die E-Mail-Adresse muss eine gültige E-Mail-Adresse sein.',
    ]);
});

it('ships the german auth, password and pagination translations', function () {
    expect(trans('auth.failed'))->toContain('Zugangsdaten')
        ->and(trans('passwords.reset'))->toContain('Passwort')
        ->and(trans('pagination.next'))->toContain('Weiter');
});
