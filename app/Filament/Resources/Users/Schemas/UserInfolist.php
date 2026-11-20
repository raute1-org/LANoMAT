<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Role;
use App\Filament\Resources\Users\RelationManagers\LinkedAccountsRelationManager;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * Read-only summary of the account's own fields. Deliberately excludes
 * anything security-sensitive (password, two-factor secrets) and the free-
 * text `steam_url` — the authoritative Steam identity, if any, is the
 * verified one shown by {@see LinkedAccountsRelationManager}.
 */
class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name')
                    ->label(__('users.fields.name')),
                TextEntry::make('email')
                    ->label(__('users.fields.email')),
                TextEntry::make('role')
                    ->label(__('users.fields.role'))
                    ->badge()
                    ->formatStateUsing(fn (Role $state) => $state->label()),
                TextEntry::make('files_quota_mb')
                    ->label(__('users.fields.files_quota_mb'))
                    ->formatStateUsing(fn (?int $state): string => $state === null
                        ? __('users.files_quota.default', ['default' => intdiv((int) config('files.per_user_quota_mb'), 1024)])
                        : number_format($state / 1024, 0, ',', '.').' GB'),
                TextEntry::make('created_at')
                    ->label(__('users.fields.created_at'))
                    ->dateTime(),
            ]);
    }
}
