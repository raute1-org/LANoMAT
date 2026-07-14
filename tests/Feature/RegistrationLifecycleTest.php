<?php

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CheckInRegistration;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Exceptions\CheckInException;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Seating\Actions\ClaimSeat;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Models\SeatAssignment;

/**
 * End-to-end guard for the full registration/seating lifecycle seam chain —
 * this is the literal M2 roadmap acceptance criterion: register, seat, check
 * in, cancel (seat auto-released), re-register (clean slate), check in again
 * with a fresh token, and a replayed old/second scan is rejected.
 */
it('carries a participant through the full register-seat-checkin-cancel-reregister lifecycle', function () {
    $event = Event::factory()->registration()->create(['settings' => ['tickets' => ['standard']]]);
    $user = User::factory()->create();
    $seatA = Seat::factory()->for($event)->create(['label' => 'A1']);
    $seatB = Seat::factory()->for($event)->create(['label' => 'A2']);

    // 1. Register.
    $this->actingAs($user)
        ->post("/events/{$event->slug}/register", ['ticket_type' => 'standard'])
        ->assertRedirect();

    $registration = EventRegistration::query()->where('event_id', $event->id)->where('user_id', $user->id)->firstOrFail();
    expect($registration->status)->toBe(RegistrationStatus::Confirmed);
    $firstToken = $registration->qr_token;

    // 2. Claim a seat, then switch seats — the old assignment must be released.
    $this->actingAs($user)
        ->post("/events/{$event->slug}/seating/{$seatA->id}")
        ->assertRedirect();
    expect(SeatAssignment::where('registration_id', $registration->id)->where('seat_id', $seatA->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->post("/events/{$event->slug}/seating/{$seatB->id}")
        ->assertRedirect();
    expect(SeatAssignment::where('seat_id', $seatA->id)->exists())->toBeFalse();
    expect(SeatAssignment::where('registration_id', $registration->id)->where('seat_id', $seatB->id)->exists())->toBeTrue();

    // 3. Check in with the first token.
    app(CheckInRegistration::class)->handle($event, $firstToken);
    expect($registration->fresh()->checked_in_at)->not->toBeNull();

    // 4. Cancel — the seat assignment must be auto-released (seat freed).
    $this->actingAs($user)
        ->delete("/events/{$event->slug}/register")
        ->assertRedirect();

    $registration->refresh();
    expect($registration->status)->toBe(RegistrationStatus::Cancelled);
    expect(SeatAssignment::where('registration_id', $registration->id)->exists())->toBeFalse();

    // 5. Re-register — reactivated in place, but with a FRESH token and
    // reset paid/checked-in state (refunds/re-pay are an orga decision, not
    // an automatic carry-over from the previous registration cycle).
    $this->actingAs($user)
        ->post("/events/{$event->slug}/register", ['ticket_type' => 'standard'])
        ->assertRedirect();

    $registration->refresh();
    expect($registration->status)->toBe(RegistrationStatus::Confirmed)
        ->and($registration->qr_token)->not->toBe($firstToken)
        ->and($registration->checked_in_at)->toBeNull()
        ->and($registration->paid_at)->toBeNull();

    $secondToken = $registration->qr_token;

    // 6. Check in with the NEW token succeeds.
    app(CheckInRegistration::class)->handle($event, $secondToken);
    expect($registration->fresh()->checked_in_at)->not->toBeNull();

    // 7. A second scan (whether replaying the old token or reusing the new
    // one) must be rejected as already checked in.
    expect(fn () => app(CheckInRegistration::class)->handle($event, $secondToken))
        ->toThrow(CheckInException::class);

    expect(fn () => app(CheckInRegistration::class)->handle($event, $firstToken))
        ->toThrow(CheckInException::class);

    // Sanity: claiming a seat with the freshly-reactivated registration still
    // works (registration id is stable across the cancel/reactivate cycle).
    app(ClaimSeat::class)->handle($seatA, $registration->fresh());
    expect(SeatAssignment::where('registration_id', $registration->id)->where('seat_id', $seatA->id)->exists())->toBeTrue();
});
