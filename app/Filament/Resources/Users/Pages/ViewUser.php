<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\RelationManagers\LinkedAccountsRelationManager;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

/**
 * No edit/delete header actions: this is the read-only detail view an orga
 * uses to look up a user's linked accounts (see the
 * {@see LinkedAccountsRelationManager} tab). The one exception is the
 * admin-only file upload quota, an override of the configured default.
 */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
