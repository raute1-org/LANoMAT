<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('regenerates the session id on login (no session fixation)', function () {
    $user = User::factory()->create(['password' => Hash::make('s3cret-pass-phrase')]);

    $this->get('/login');
    $before = session()->getId();

    $this->post('/login', ['email' => $user->email, 'password' => 's3cret-pass-phrase'])->assertRedirect();

    expect(Auth::check())->toBeTrue()
        ->and(session()->getId())->not->toBe($before);
});

it('invalidates the session and rotates the token on logout', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->get('/dashboard');
    $beforeId = session()->getId();
    $beforeToken = session()->token();

    $this->post('/logout')->assertRedirect();

    expect(Auth::check())->toBeFalse()
        ->and(session()->getId())->not->toBe($beforeId)
        ->and(session()->token())->not->toBe($beforeToken);
    $this->get('/dashboard')->assertRedirect('/login');
});

it('does not let a Discord-only account (no local password) log in or set a password', function () {
    $user = User::factory()->create(['password' => null]);

    $this->post('/login', ['email' => $user->email, 'password' => ''])->assertSessionHasErrors();
    $this->post('/login', ['email' => $user->email, 'password' => 'anything-at-all'])->assertSessionHasErrors();
    expect(Auth::check())->toBeFalse();

    $this->actingAs($user)
        ->put('/settings/password', ['current_password' => 'x', 'password' => 'New-Passw0rd!2026', 'password_confirmation' => 'New-Passw0rd!2026'])
        ->assertSessionHasErrors('current_password');
    expect($user->fresh()->password)->toBeNull();
});

it('throttles password login attempts per account and address', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $_) {
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(429);
});
