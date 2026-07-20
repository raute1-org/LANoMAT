<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Modules\Identity\Enums\LinkedAccountProvider;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only listing of a user's linked third-party accounts (Steam, Twitch,
 * ...). Linking/unlinking is user-self-service only, via the Connections
 * settings page — so this manager declares no header/record/bulk actions at
 * all (no create, edit, or delete from the admin panel).
 *
 * `access_token` and `refresh_token` are deliberately never listed as
 * columns here — see LinkedAccount::casts() for why they are encrypted at
 * rest in the first place.
 */
class LinkedAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'linkedAccounts';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('identity.admin.linked_accounts_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->label(__('identity.admin.provider'))
                    ->formatStateUsing(fn (LinkedAccountProvider $state) => $state->label()),
                TextColumn::make('nickname')
                    ->label(__('identity.admin.nickname'))
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label(__('identity.admin.linked_at'))
                    ->dateTime(),
            ]);
    }
}
