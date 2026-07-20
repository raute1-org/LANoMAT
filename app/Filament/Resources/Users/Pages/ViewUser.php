<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\RelationManagers\LinkedAccountsRelationManager;
use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * No edit/delete header actions: this is the read-only detail view an orga
 * uses to look up a user's linked accounts (see the
 * {@see LinkedAccountsRelationManager} tab).
 */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;
}
