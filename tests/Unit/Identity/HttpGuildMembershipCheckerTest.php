<?php

use App\Modules\Identity\Connectors\HttpGuildMembershipChecker;
use App\Modules\Identity\Exceptions\GuildMembershipUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

const MEMBER_ENDPOINT = 'https://discord.com/api/v10/users/@me/guilds/111/member';

it('reports membership when Discord returns the guild member object', function () {
    Http::fake([MEMBER_ENDPOINT => Http::response(['user' => ['id' => '1']], 200)]);

    expect((new HttpGuildMembershipChecker)->isMember('user-token', '111'))->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === MEMBER_ENDPOINT
        && $request->hasHeader('Authorization', 'Bearer user-token'));
});

it('reports non-membership for 404 and 403 without retrying', function (int $status) {
    Http::fake([MEMBER_ENDPOINT => Http::response(['message' => 'nope'], $status)]);

    expect((new HttpGuildMembershipChecker)->isMember('user-token', '111'))->toBeFalse();
    Http::assertSentCount(1);
})->with([404, 403]);

it('fails closed on server errors and rate limits', function (int $status) {
    Http::fake([MEMBER_ENDPOINT => Http::response([], $status)]);

    (new HttpGuildMembershipChecker)->isMember('user-token', '111');
})->with([500, 429])->throws(GuildMembershipUnavailable::class);

it('fails closed on connection errors and timeouts', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    (new HttpGuildMembershipChecker)->isMember('user-token', '111');
})->throws(GuildMembershipUnavailable::class);
