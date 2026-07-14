<?php

use App\Modules\Registration\Actions\CancelRegistration;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Events\RegistrationCancelled;
use App\Modules\Registration\Models\EventRegistration;
use Illuminate\Support\Facades\Event;

it('cancels a registration', function () {
    $reg = EventRegistration::factory()->create();

    $result = app(CancelRegistration::class)->handle($reg);

    expect($result->status)->toBe(RegistrationStatus::Cancelled)
        ->and($reg->fresh()->status)->toBe(RegistrationStatus::Cancelled);
});

it('is idempotent on an already cancelled registration', function () {
    $reg = EventRegistration::factory()->cancelled()->create();

    expect(app(CancelRegistration::class)->handle($reg)->status)
        ->toBe(RegistrationStatus::Cancelled);
});

it('dispatches RegistrationCancelled when a registration is cancelled', function () {
    $reg = EventRegistration::factory()->create();

    Event::fake([RegistrationCancelled::class]);

    app(CancelRegistration::class)->handle($reg);

    Event::assertDispatched(
        RegistrationCancelled::class,
        fn (RegistrationCancelled $event): bool => $event->registration->is($reg),
    );
});

it('does not dispatch RegistrationCancelled again for an already cancelled registration', function () {
    $reg = EventRegistration::factory()->cancelled()->create();

    Event::fake([RegistrationCancelled::class]);

    app(CancelRegistration::class)->handle($reg);

    Event::assertNotDispatched(RegistrationCancelled::class);
});
