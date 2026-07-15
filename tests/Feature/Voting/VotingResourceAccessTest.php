<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Voting\Models\Poll;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forbids participants from the polls resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/polls')
        ->assertForbidden();
});

it('allows orga into the polls resource and renders the list', function () {
    $event = Event::factory()->create();
    Poll::factory()->for($event)->create(['question' => 'Welches Spiel als nächstes?']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/polls')
        ->assertOk()
        ->assertSee('Welches Spiel als nächstes?');
});
