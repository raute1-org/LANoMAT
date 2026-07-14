<?php

namespace App\Modules\Tournaments\Filament\Resources\Tournaments\Pages;

use App\Modules\Tournaments\Actions\OverrideMatchResult;
use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * A cross-tournament queue of `Disputed` matches, with an orga-only override
 * action. Deliberately not a resource page (disputes span every tournament,
 * not one), so it lives as a standalone panel page implementing `HasTable`
 * directly instead of via `TournamentResource::getPages()`.
 */
class ManageDisputes extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $slug = 'tournaments/disputes';

    public function getTitle(): string
    {
        return __('tournaments.admin.disputes.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('tournaments.admin.disputes.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isOrga() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => GameMatch::query()->where('status', MatchStatus::Disputed->value))
            ->columns([
                TextColumn::make('tournament.name')
                    ->label(__('tournaments.admin.disputes.tournament')),
                TextColumn::make('round')
                    ->label(__('tournaments.admin.disputes.round')),
                TextColumn::make('entry1.display_name')
                    ->label(__('tournaments.admin.disputes.entry1')),
                TextColumn::make('entry2.display_name')
                    ->label(__('tournaments.admin.disputes.entry2')),
                TextColumn::make('score1')
                    ->label(__('tournaments.admin.disputes.score1')),
                TextColumn::make('score2')
                    ->label(__('tournaments.admin.disputes.score2')),
            ])
            ->recordActions([
                Action::make('override')
                    ->label(__('tournaments.admin.disputes.override'))
                    ->authorize(fn (GameMatch $record): bool => auth()->user()?->can('manage', $record->tournament) ?? false)
                    ->requiresConfirmation()
                    ->schema([
                        TextInput::make('score1')
                            ->label(__('tournaments.admin.disputes.score1'))
                            ->required()
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('score2')
                            ->label(__('tournaments.admin.disputes.score2'))
                            ->required()
                            ->numeric()
                            ->minValue(0),
                    ])
                    ->action(function (GameMatch $record, array $data): void {
                        try {
                            app(OverrideMatchResult::class)->handle($record, (int) $data['score1'], (int) $data['score2']);
                        } catch (TournamentException $exception) {
                            Notification::make()
                                ->title(__($exception->translationKey))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }
}
