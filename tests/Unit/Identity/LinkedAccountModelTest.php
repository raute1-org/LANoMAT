<?php

declare(strict_types=1);

use App\Modules\Identity\Models\LinkedAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('encrypts tokens at rest and never exposes them fillable', function () {
    $account = LinkedAccount::factory()->create();
    $account->forceFill(['access_token' => 'secret-abc'])->save();

    $raw = DB::table('linked_accounts')->where('id', $account->id)->value('access_token');
    expect($raw)->not->toBe('secret-abc');            // stored ciphertext
    expect($account->fresh()->access_token)->toBe('secret-abc'); // decrypted on read

    // mass-assignment cannot set a token
    $account->fill(['access_token' => 'injected'])->save();
    expect($account->fresh()->access_token)->toBe('secret-abc');
});
