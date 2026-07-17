<?php

declare(strict_types=1);

namespace App\Modules\Voice\Filament\Resources\VoiceClientInstallers;

use App\Modules\Voice\Filament\Resources\VoiceClientInstallers\Pages\CreateVoiceClientInstaller;
use App\Modules\Voice\Filament\Resources\VoiceClientInstallers\Pages\EditVoiceClientInstaller;
use App\Modules\Voice\Filament\Resources\VoiceClientInstallers\Pages\ListVoiceClientInstallers;
use App\Modules\Voice\Filament\Resources\VoiceClientInstallers\Schemas\VoiceClientInstallerForm;
use App\Modules\Voice\Filament\Resources\VoiceClientInstallers\Tables\VoiceClientInstallersTable;
use App\Modules\Voice\Models\VoiceClientInstaller;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Orga-only registry of downloadable voice-client installers (roadmap 8.7),
 * stored on the private `local` disk (mirrors SharedFileResource/
 * RemoteHostResource's precedent). Uploads/replacements/marking-current all
 * go through this resource; the participant-facing "Voice einrichten" page
 * (VoiceSetupController) only ever reads the `is_current` row per platform.
 */
class VoiceClientInstallerResource extends Resource
{
    protected static ?string $model = VoiceClientInstaller::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMicrophone;

    // No dedicated "Infra"/"Voice" navigation group exists yet; mirrors
    // RemoteHostResource's and ServerLinkResource's precedent of filing
    // infra-adjacent resources under TurniereUndTeams rather than growing
    // the shared enum for a single resource.
    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::TurniereUndTeams;

    protected static ?int $navigationSort = 42;

    public static function getModelLabel(): string
    {
        return __('voice.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('voice.resource.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('voice.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return VoiceClientInstallerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VoiceClientInstallersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVoiceClientInstallers::route('/'),
            'create' => CreateVoiceClientInstaller::route('/create'),
            'edit' => EditVoiceClientInstaller::route('/{record}/edit'),
        ];
    }
}
