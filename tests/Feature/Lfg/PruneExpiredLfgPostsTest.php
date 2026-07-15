<?php

use App\Modules\Lfg\Models\LfgPost;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes only expired posts and keeps active ones', function () {
    $expired = LfgPost::factory()->expired()->create();
    $active = LfgPost::factory()->create(['expires_at' => now()->addHours(3)]);

    $this->artisan('lanomat:prune-lfg')->assertSuccessful();

    expect(LfgPost::query()->find($expired->id))->toBeNull()
        ->and(LfgPost::query()->find($active->id))->not->toBeNull();
});

it('deletes a post the instant it expires via time travel', function () {
    $post = LfgPost::factory()->create(['expires_at' => now()->addHour()]);

    $this->travelTo(now()->addHours(2), function () use ($post) {
        $this->artisan('lanomat:prune-lfg')->assertSuccessful();

        expect(LfgPost::query()->find($post->id))->toBeNull();
    });
});

it('reports the number of pruned posts', function () {
    LfgPost::factory()->count(2)->expired()->create();
    LfgPost::factory()->create(['expires_at' => now()->addHours(3)]);

    $this->artisan('lanomat:prune-lfg')
        ->assertSuccessful()
        ->expectsOutputToContain('2');
});
