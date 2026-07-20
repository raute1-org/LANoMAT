<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Friends\Actions\RespondToFriendRequest;
use App\Modules\Friends\Actions\SendFriendRequest;
use App\Modules\Friends\Notifications\FriendRequestAccepted;
use App\Modules\Friends\Notifications\FriendRequestReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('notifies the addressee of a new request and the requester on acceptance', function () {
    Notification::fake();
    $a = User::factory()->create();
    $b = User::factory()->create();
    $req = app(SendFriendRequest::class)->handle($a, $b);
    Notification::assertSentTo($b, FriendRequestReceived::class);
    app(RespondToFriendRequest::class)->handle($b, $req, accept: true);
    Notification::assertSentTo($a, FriendRequestAccepted::class);
});

it('does not notify the addressee when a request auto-accepts, and notifies the original requester instead', function () {
    Notification::fake();
    $a = User::factory()->create();
    $b = User::factory()->create();

    app(SendFriendRequest::class)->handle($a, $b); // a -> b pending, notifies b
    Notification::assertSentTo($b, FriendRequestReceived::class);

    app(SendFriendRequest::class)->handle($b, $a); // auto-accepts a's request

    Notification::assertSentTo($a, FriendRequestAccepted::class);
    // b got exactly one FriendRequestReceived (from the first request), and the
    // auto-accept did NOT send a second one.
    Notification::assertSentToTimes($b, FriendRequestReceived::class, 1);
    Notification::assertNotSentTo($a, FriendRequestReceived::class);
});

it('does not notify anyone when a request is declined', function () {
    Notification::fake();
    $a = User::factory()->create();
    $b = User::factory()->create();

    $req = app(SendFriendRequest::class)->handle($a, $b);
    Notification::assertSentTo($b, FriendRequestReceived::class);

    app(RespondToFriendRequest::class)->handle($b, $req, accept: false);

    Notification::assertNotSentTo($a, FriendRequestAccepted::class);
    Notification::assertNotSentTo($b, FriendRequestAccepted::class);
});
