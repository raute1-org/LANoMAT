<?php

namespace App\Modules\Registration\Filament\RelationManagers;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Actions\CancelRegistration;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Models\TicketType;
use App\Modules\Registration\Support\ArrivalPlanView;
use App\Modules\Registration\Support\TicketLabel;
use App\Support\Money\Euro;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RegistrationsRelationManager extends RelationManager
{
    protected static string $relationship = 'registrations';

    // Eager-load the table: LAN-scale registration counts are small, and a
    // plain page load (no JS intersection-observer round trip) must show
    // the list immediately for orga staff.
    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('registration.admin.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('registration.admin.model_label'))
            ->pluralModelLabel(__('registration.admin.plural_model_label'))
            ->emptyStateHeading(__('registration.admin.empty'))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('registration.admin.participant'))
                    ->searchable(),
                TextColumn::make('ticket_type')
                    ->label(__('registration.admin.ticket'))
                    ->formatStateUsing(fn (string $state): string => TicketLabel::for($state)),
                TextColumn::make('arrival_day')
                    ->label(__('registration.admin.arrival_columns.arrival'))
                    ->state(fn (EventRegistration $record): string => ArrivalPlanView::label($record->arrival_day?->toDateString(), $record->arrival_window)
                        ?? __('registration.admin.arrival_columns.open')),
                TextColumn::make('status')
                    ->label(__('registration.admin.status'))
                    ->badge()
                    ->formatStateUsing(fn (RegistrationStatus $state) => $state->label()),
                TextColumn::make('ticket_price_cents')
                    ->label(__('registration.admin.price'))
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : Euro::format($state)),
                IconColumn::make('paid_at')
                    ->label(__('registration.admin.paid'))
                    ->boolean()
                    ->state(fn (EventRegistration $record) => $record->paid_at !== null),
                TextColumn::make('paid_by')
                    ->label(__('registration.admin.paid_by'))
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('checked_in_at')
                    ->label(__('registration.admin.checked_in'))
                    ->boolean()
                    ->state(fn (EventRegistration $record) => $record->checked_in_at !== null),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('registration.admin.status'))
                    ->options(collect(RegistrationStatus::cases())
                        ->mapWithKeys(fn (RegistrationStatus $status): array => [$status->value => $status->label()])
                        ->all()),
                Filter::make('payment_open')
                    ->label(__('registration.admin.payment_open'))
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNull('paid_at')
                        ->where('ticket_price_cents', '>', 0)
                        ->where('status', '!=', RegistrationStatus::Cancelled->value)),
            ])
            ->recordActions([
                Action::make('cancel_registration')
                    ->label(__('registration.admin.cancel'))
                    ->authorize(fn (EventRegistration $record): bool => Gate::allows('cancel', $record))
                    ->visible(fn (EventRegistration $record): bool => $record->status !== RegistrationStatus::Cancelled)
                    ->requiresConfirmation()
                    ->modalHeading(fn (EventRegistration $record): string => __('registration.admin.cancel_heading', ['name' => (string) data_get($record, 'user.name', '—')]))
                    ->modalDescription(fn (EventRegistration $record): string => __('registration.admin.cancel_description', ['name' => (string) data_get($record, 'user.name', '—')]))
                    ->color('danger')
                    ->action(fn (EventRegistration $record) => app(CancelRegistration::class)->handle($record)),
                Action::make('toggle_paid')
                    ->label(__('registration.admin.toggle_paid'))
                    ->authorize(fn (EventRegistration $record): bool => Gate::allows('update', $record))
                    ->modalHeading(__('registration.admin.toggle_paid'))
                    // Only when marking as paid: who paid / for whom (optional). Unmarking needs no input.
                    ->schema(fn (EventRegistration $record): array => $record->paid_at === null
                        ? [TextInput::make('paid_by')
                            ->label(__('registration.admin.paid_by'))
                            ->helperText(__('registration.admin.paid_by_help'))
                            ->maxLength(120)]
                        : [])
                    ->action(function (EventRegistration $record, array $data): void {
                        if ($record->paid_at === null) {
                            $paidBy = isset($data['paid_by']) && is_string($data['paid_by']) ? trim($data['paid_by']) : '';
                            $record->paid_at = Carbon::now();
                            $record->paid_by = $paidBy !== '' ? $paidBy : null;
                        } else {
                            $record->paid_at = null;
                            $record->paid_by = null;
                        }
                        $record->save();
                    }),
                Action::make('change_ticket')
                    ->label(__('registration.admin.change_ticket'))
                    ->authorize(fn (EventRegistration $record): bool => Gate::allows('update', $record))
                    ->fillForm(fn (EventRegistration $record): array => ['ticket_type' => $record->ticket_type])
                    ->schema(fn (EventRegistration $record): array => [
                        Select::make('ticket_type')
                            ->label(__('registration.admin.ticket'))
                            ->options(fn (): array => TicketType::query()
                                ->where('event_id', $record->event_id)
                                ->orderBy('sort')->orderBy('id')
                                ->get()
                                ->mapWithKeys(fn (TicketType $t): array => [$t->key => $t->name.' ('.Euro::format($t->price_cents).')'])
                                ->all())
                            ->required(),
                    ])
                    ->action(function (EventRegistration $record, array $data): void {
                        $type = TicketType::query()
                            ->where('event_id', $record->event_id)
                            ->where('key', (string) $data['ticket_type'])
                            ->first();

                        if ($type === null) {
                            return;
                        }

                        // Changing the ticket re-snapshots the price.
                        $record->ticket_type = $type->key;
                        $record->ticket_price_cents = $type->price_cents;
                        $record->save();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('rePrice')
                        ->label(__('registration.admin.reprice'))
                        ->authorize(fn (): bool => Gate::allows('viewAny', EventRegistration::class))
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (EloquentCollection $records): void {
                            $updated = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                if (! $record instanceof EventRegistration) {
                                    continue;
                                }

                                if ($record->paid_at !== null) {
                                    $skipped++;

                                    continue;
                                }

                                $price = TicketType::query()
                                    ->where('event_id', $record->event_id)
                                    ->where('key', $record->ticket_type)
                                    ->value('price_cents');
                                $record->ticket_price_cents = is_numeric($price) ? (int) $price : 0;
                                $record->save();
                                $updated++;
                            }

                            Notification::make()
                                ->title(__('registration.admin.reprice_done', ['updated' => $updated, 'skipped' => $skipped]))
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->headerActions([
                Action::make('export_csv')
                    ->label(__('registration.admin.export'))
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
            ]);
    }

    private function exportCsv(): StreamedResponse
    {
        $event = $this->getOwnerRecord();

        if (! $event instanceof Event) {
            throw new LogicException('RegistrationsRelationManager must be attached to an Event record.');
        }

        /** @var Collection<int, EventRegistration> $rows */
        $rows = $event->registrations()->with('user')->get();

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, ['name', 'ticket_type', 'ticket_price_eur', 'status', 'paid_at', 'paid_by', 'checked_in_at']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->user?->name,
                    $row->ticket_type,
                    $row->ticket_price_cents === null ? '' : number_format($row->ticket_price_cents / 100, 2, '.', ''),
                    $row->status->value,
                    $row->paid_at?->toIso8601String(),
                    $row->paid_by,
                    $row->checked_in_at?->toIso8601String(),
                ]);
            }

            fclose($out);
        }, 'registrations.csv');
    }
}
