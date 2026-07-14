<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Database\QueryException;

it('generates a unique qr_token on creation', function () {
    $reg = EventRegistration::factory()->create();

    expect($reg->qr_token)->toBeString()->and(strlen($reg->qr_token))->toBeGreaterThanOrEqual(32);
});

it('casts status, paid_at and checked_in_at', function () {
    $reg = EventRegistration::factory()->create([
        'status' => RegistrationStatus::Confirmed,
    ]);

    expect($reg->fresh()->status)->toBe(RegistrationStatus::Confirmed)
        ->and($reg->paid_at)->toBeNull()
        ->and($reg->checked_in_at)->toBeNull();
});

it('exposes event and user relations', function () {
    $reg = EventRegistration::factory()->create();

    expect($reg->event)->toBeInstanceOf(Event::class)
        ->and($reg->user)->toBeInstanceOf(User::class);
});

it('forbids a second registration of the same user for the same event', function () {
    $event = Event::factory()->registration()->create();
    $user = User::factory()->create();
    EventRegistration::factory()->for($event)->for($user)->create();

    expect(fn () => EventRegistration::factory()->for($event)->for($user)->create())
        ->toThrow(QueryException::class);
});
