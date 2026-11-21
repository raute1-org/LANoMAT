<?php

use App\Modules\Assistant\Models\AssistantAction;
use App\Support\Modules\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('is optional and off in the core profile', function () {
    expect(Module::Assistant->isCore())->toBeFalse()
        ->and(Module::Assistant->requires())->toBe([]);
});

it('encrypts and hides the secret', function () {
    $action = AssistantAction::factory()->create();
    $action->forceFill(['secret' => 'lan2026'])->save();
    $raw = DB::table('assistant_actions')->where('id', $action->id)->value('secret');

    expect($raw)->not->toContain('lan2026')
        ->and($action->fresh()->secret)->toBe('lan2026')
        ->and($action->fresh()->toArray())->not->toHaveKey('secret');
});

it('does not mass-assign status or secret', function () {
    $action = AssistantAction::factory()->create();
    $action->fill(['status' => 'executed', 'secret' => 'x']);

    expect($action->status->value)->toBe('proposed')->and($action->secret)->toBeNull();
});

it('keeps the audit row when the conversation row is gone', function () {
    $action = AssistantAction::factory()->create();

    expect(AssistantAction::query()->whereKey($action->id)->exists())->toBeTrue();
});
