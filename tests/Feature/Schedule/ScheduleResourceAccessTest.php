<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Schedule\Models\ScheduleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forbids participants from the schedule items resource', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/schedule-items')
        ->assertForbidden();
});

it('allows orga into the schedule items resource and renders the list', function () {
    $event = Event::factory()->create();
    ScheduleItem::factory()->for($event)->create(['title' => 'Opening Ceremony']);

    $this->actingAs(User::factory()->orga()->create())
        ->get('/admin/schedule-items')
        ->assertOk()
        ->assertSee('Opening Ceremony');
});
