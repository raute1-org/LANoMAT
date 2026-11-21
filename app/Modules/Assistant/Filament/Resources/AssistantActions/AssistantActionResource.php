<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Filament\Resources\AssistantActions;

use App\Modules\Assistant\Enums\AssistantActionStatus;
use App\Modules\Assistant\Filament\Resources\AssistantActions\Pages\ListAssistantActions;
use App\Modules\Assistant\Models\AssistantAction;
use App\Providers\Filament\AdminNavigationGroup;
use App\Support\DisplayTimezone;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only audit list of everything the assistant proposed and what became of it. The card-entered
 * password column (`secret`) is never selected, so it can reach neither the list nor the page source.
 */
class AssistantActionResource extends Resource
{
    protected static ?string $model = AssistantAction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Nutzer;

    protected static ?int $navigationSort = 90;

    public static function getModelLabel(): string
    {
        return __('assistant.audit.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('assistant.audit.plural');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /** @return Builder<AssistantAction> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->select(['id', 'user_id', 'conversation_id', 'tool_call_id', 'tool', 'arguments', 'status', 'result_summary', 'error', 'created_at', 'updated_at'])
            ->with('user:id,name');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('assistant.audit.time'))
                    ->formatStateUsing(fn ($state): string => DisplayTimezone::local($state)->format('d.m.Y H:i'))
                    ->sortable(),
                TextColumn::make('user.name')->label(__('assistant.audit.user'))->searchable(),
                TextColumn::make('tool')->label(__('assistant.audit.tool'))->fontFamily('mono')->searchable(),
                TextColumn::make('status')->label(__('assistant.audit.status'))->badge()
                    ->formatStateUsing(fn (AssistantActionStatus $state): string => __('assistant.audit.statuses.'.$state->value))
                    ->color(fn (AssistantActionStatus $state): string => match ($state) {
                        AssistantActionStatus::Executed => 'success',
                        AssistantActionStatus::Failed => 'danger',
                        AssistantActionStatus::Approved, AssistantActionStatus::Proposed => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('arguments')->label(__('assistant.audit.arguments'))
                    ->formatStateUsing(fn ($state): string => mb_substr((string) json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 160))
                    ->fontFamily('mono')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('result_summary')->label(__('assistant.audit.result'))->limit(80)->tooltip(fn ($record): ?string => $record->error ?? $record->result_summary)
                    ->state(fn (AssistantAction $record): ?string => $record->error ?? $record->result_summary),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('assistant.audit.status'))
                    ->options(collect(AssistantActionStatus::cases())->mapWithKeys(fn (AssistantActionStatus $s): array => [$s->value => __('assistant.audit.statuses.'.$s->value)])->all()),
                SelectFilter::make('tool')->label(__('assistant.audit.tool'))
                    ->options(fn (): array => AssistantAction::query()->distinct()->orderBy('tool')->pluck('tool', 'tool')->all()),
            ])
            ->recordActions([])
            ->toolbarActions([])
            ->emptyStateHeading(__('assistant.audit.empty'));
    }

    public static function getPages(): array
    {
        return ['index' => ListAssistantActions::route('/')];
    }
}
