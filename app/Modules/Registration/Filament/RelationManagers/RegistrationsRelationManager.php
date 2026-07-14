<?php

namespace App\Modules\Registration\Filament\RelationManagers;

use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Models\EventRegistration;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('registration.admin.participant'))
                    ->searchable(),
                TextColumn::make('ticket_type')->label(__('registration.admin.ticket')),
                TextColumn::make('status')
                    ->label(__('registration.admin.status'))
                    ->badge()
                    ->formatStateUsing(fn (RegistrationStatus $state) => $state->label()),
                IconColumn::make('paid_at')
                    ->label(__('registration.admin.paid'))
                    ->boolean()
                    ->state(fn (EventRegistration $record) => $record->paid_at !== null),
                IconColumn::make('checked_in_at')
                    ->label(__('registration.admin.checked_in'))
                    ->boolean()
                    ->state(fn (EventRegistration $record) => $record->checked_in_at !== null),
            ])
            ->recordActions([
                Action::make('toggle_paid')
                    ->label(__('registration.admin.toggle_paid'))
                    ->action(function (EventRegistration $record): void {
                        $record->paid_at = $record->paid_at === null ? Carbon::now() : null;
                        $record->save();
                    }),
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

            fputcsv($out, ['name', 'ticket_type', 'status', 'paid_at', 'checked_in_at']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->user?->name,
                    $row->ticket_type,
                    $row->status->value,
                    $row->paid_at?->toIso8601String(),
                    $row->checked_in_at?->toIso8601String(),
                ]);
            }

            fclose($out);
        }, 'registrations.csv');
    }
}
