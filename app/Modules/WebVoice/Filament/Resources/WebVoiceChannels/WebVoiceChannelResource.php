<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Filament\Resources\WebVoiceChannels;

use App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\Pages\CreateWebVoiceChannel;
use App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\Pages\EditWebVoiceChannel;
use App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\Pages\ListWebVoiceChannels;
use App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\Schemas\WebVoiceChannelForm;
use App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\Tables\WebVoiceChannelsTable;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use UnitEnum;

/**
 * Admin-only management of the web voice channels: create, rename and archive the general
 * channels, and see which rooms exist and who is in them. Match rooms are listed read-only, they
 * are made and closed by the tournament lifecycle.
 */
class WebVoiceChannelResource extends Resource
{
    protected static ?string $model = WebVoiceChannel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::TurniereUndTeams;

    protected static ?int $navigationSort = 43;

    public static function getModelLabel(): string
    {
        return __('web_voice.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('web_voice.resource.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('web_voice.resource.plural_label');
    }

    // The policy's viewAny is true for every user (it backs the participant page), so the panel
    // resource asks `viewLive` (orga and admin: the list shows who is in which room) and keeps
    // create/edit/archive on the admin-only `manage` ability.
    public static function canViewAny(): bool
    {
        return Gate::allows('viewLive', WebVoiceChannel::class);
    }

    public static function canCreate(): bool
    {
        return Gate::allows('manage', WebVoiceChannel::class);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof WebVoiceChannel && $record->isGeneral() && Gate::allows('manage', WebVoiceChannel::class);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return WebVoiceChannelForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WebVoiceChannelsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebVoiceChannels::route('/'),
            'create' => CreateWebVoiceChannel::route('/create'),
            'edit' => EditWebVoiceChannel::route('/{record}/edit'),
        ];
    }
}
