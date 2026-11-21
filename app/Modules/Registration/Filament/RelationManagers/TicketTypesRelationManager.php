<?php

namespace App\Modules\Registration\Filament\RelationManagers;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\TicketType;
use App\Modules\Registration\Support\TicketTypeGuard;
use App\Support\Money\Euro;
use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TicketTypesRelationManager extends RelationManager
{
    protected static string $relationship = 'ticketTypes';

    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('registration.admin.ticket_types.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('registration.admin.ticket_types.name'))
                ->required()
                ->maxLength(80),
            Textarea::make('description')
                ->label(__('registration.admin.ticket_types.description'))
                ->maxLength(255)
                ->rows(2),
            TextInput::make('price_cents')
                ->label(__('registration.admin.ticket_types.price'))
                ->helperText(__('registration.admin.ticket_types.price_help'))
                ->required()
                ->formatStateUsing(fn (mixed $state): string => is_numeric($state) ? number_format(((int) $state) / 100, 2, ',', '') : '')
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (Euro::toCents(is_scalar($value) ? (string) $value : null) === null) {
                        $fail(__('registration.admin.ticket_types.price_invalid'));
                    }
                })
                ->dehydrateStateUsing(fn (mixed $state): int => Euro::toCents(is_scalar($state) ? (string) $state : null) ?? 0),
            TextInput::make('sort')
                ->label(__('registration.admin.ticket_types.sort'))
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(1000)
                ->default(0),
            Toggle::make('active')
                ->label(__('registration.admin.ticket_types.active'))
                ->default(true)
                ->rule(fn (?TicketType $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    // Server-side guard: the last active ticket type cannot be switched off.
                    if (! $value && $record !== null && ! TicketTypeGuard::canDeactivate($record)) {
                        $fail(__('registration.admin.ticket_types.last_active'));
                    }
                }),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('registration.admin.ticket_types.model_label'))
            ->pluralModelLabel(__('registration.admin.ticket_types.plural_model_label'))
            ->emptyStateHeading(__('registration.admin.ticket_types.empty'))
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label(__('registration.admin.ticket_types.name')),
                TextColumn::make('price_cents')
                    ->label(__('registration.admin.ticket_types.price'))
                    ->formatStateUsing(fn (int $state): string => Euro::format($state)),
                IconColumn::make('active')->label(__('registration.admin.ticket_types.active'))->boolean(),
                TextColumn::make('sort')->label(__('registration.admin.ticket_types.sort')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $event = $this->getOwnerRecord();
                        $data['key'] = $event instanceof Event
                            ? TicketType::uniqueKeyFor($event, (string) ($data['name'] ?? ''))
                            : 'ticket';
                        $data['sort'] = (int) ($data['sort'] ?? 0);

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(function (array $data): array {
                        $data['sort'] = (int) ($data['sort'] ?? 0);

                        return $data;
                    }),
                DeleteAction::make()
                    ->hidden(fn (TicketType $record): bool => ! TicketTypeGuard::canDelete($record))
                    ->before(function (DeleteAction $action, TicketType $record): void {
                        if (! TicketTypeGuard::canDelete($record)) {
                            Notification::make()
                                ->title(__('registration.admin.ticket_types.delete_blocked'))
                                ->danger()
                                ->send();
                            $action->cancel();
                        }
                    }),
            ]);
    }
}
