<?php

use App\Models\User;
use App\Modules\Assistant\Enums\AssistantActionStatus;
use App\Modules\Assistant\Filament\Resources\AssistantActions\AssistantActionResource;
use App\Modules\Assistant\Filament\Resources\AssistantActions\Pages\ListAssistantActions;
use App\Modules\Assistant\Models\AssistantAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists proposals and outcomes for orga and admin but never a stored secret', function (string $role) {
    $user = $role === 'admin' ? User::factory()->admin()->create() : User::factory()->orga()->create();
    $action = AssistantAction::factory()->create(['tool' => 'server_settings_update', 'result_summary' => 'Einstellungen gesetzt']);
    $action->forceFill(['status' => AssistantActionStatus::Executed, 'secret' => 'streng-geheim-42'])->saveQuietly();

    $html = $this->actingAs($user)->get('/admin/assistant-actions')->assertOk()->getContent();

    expect($html)->toContain('server_settings_update')->toContain('Einstellungen gesetzt')->not->toContain('streng-geheim-42');
})->with(['orga', 'admin']);

it('is closed to participants and helpers', function (string $role) {
    $user = $role === 'helper' ? User::factory()->helper()->create() : User::factory()->create();

    $this->actingAs($user)->get('/admin/assistant-actions')->assertForbidden();
})->with(['participant', 'helper']);

it('is read-only, even for an admin', function () {
    $this->actingAs(User::factory()->admin()->create());
    $action = AssistantAction::factory()->create();

    expect(AssistantActionResource::canCreate())->toBeFalse()
        ->and(AssistantActionResource::canEdit($action))->toBeFalse()
        ->and(AssistantActionResource::canDelete($action))->toBeFalse()
        ->and(array_keys(AssistantActionResource::getPages()))->toBe(['index']);
});

it('filters by status', function () {
    $this->actingAs(User::factory()->orga()->create());
    $ok = AssistantAction::factory()->create();
    $ok->forceFill(['status' => AssistantActionStatus::Executed])->save();
    $failed = AssistantAction::factory()->create();
    $failed->forceFill(['status' => AssistantActionStatus::Failed])->save();

    Livewire::test(ListAssistantActions::class)
        ->filterTable('status', 'failed')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$ok]);
});
