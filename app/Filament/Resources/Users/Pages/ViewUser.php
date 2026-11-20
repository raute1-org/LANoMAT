<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Enums\Role;
use App\Filament\Resources\Users\RelationManagers\LinkedAccountsRelationManager;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Modules\Identity\Actions\ChangeUserRole;
use App\Modules\Identity\Exceptions\IdentityException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Gate;

/**
 * No edit/delete header actions: this is the read-only detail view an orga
 * uses to look up a user's linked accounts (see the
 * {@see LinkedAccountsRelationManager} tab). The one exception is the
 * admin-only file upload quota (an override of the configured default) and the
 * admin-only role change.
 */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeRole')
                ->label(__('users.change_role.action'))
                ->icon('heroicon-o-shield-check')
                ->visible(fn (User $record): bool => auth()->user() !== null && Gate::forUser(auth()->user())->allows('changeRole', $record))
                ->fillForm(fn (User $record): array => ['role' => $record->role->value])
                ->schema([
                    Select::make('role')
                        ->label(__('users.change_role.label'))
                        ->options(collect(Role::cases())->mapWithKeys(fn (Role $role): array => [$role->value => $role->label()])->all())
                        ->required()
                        ->native(false),
                ])
                ->action(function (User $record, array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor !== null && Gate::forUser($actor)->allows('changeRole', $record), 403);

                    try {
                        app(ChangeUserRole::class)->handle($actor, $record, Role::from((string) $data['role']));
                    } catch (IdentityException $e) {
                        Notification::make()->danger()->title(__('users.change_role.failed'))->body(__($e->translationKey))->send();

                        return;
                    }

                    $record->refresh();
                    Notification::make()->success()->title(__('users.change_role.success'))->send();
                }),
            Action::make('filesQuota')
                ->label(__('users.files_quota.action'))
                ->icon('heroicon-o-circle-stack')
                ->visible(fn (): bool => auth()->user()?->isAdmin() === true)
                ->fillForm(fn (User $record): array => [
                    'quota_gb' => $record->files_quota_mb === null ? null : intdiv($record->files_quota_mb, 1024),
                ])
                ->schema([
                    TextInput::make('quota_gb')
                        ->label(__('users.files_quota.label'))
                        ->helperText(__('users.files_quota.help', ['default' => intdiv((int) config('files.per_user_quota_mb'), 1024)]))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(100000)
                        ->suffix('GB'),
                ])
                ->action(function (User $record, array $data): void {
                    abort_unless(auth()->user()?->isAdmin() === true, 403);

                    $gigabytes = $data['quota_gb'] ?? null;
                    $record->forceFill(['files_quota_mb' => $gigabytes === null || $gigabytes === '' ? null : (int) $gigabytes * 1024])->save();
                }),
        ];
    }
}
