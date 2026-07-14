<?php

namespace App\Modules\Seating\Filament\Resources\Seats\Pages;

use App\Modules\Seating\Filament\Resources\Seats\SeatResource;
use App\Modules\Seating\Models\Seat;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSeat extends EditRecord
{
    protected static string $resource = SeatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                // Deletion is not blocked when the seat is occupied — the
                // cascade to seat_assignments is intentional (e.g. removing
                // a broken seat) — but the orga must be told up front that a
                // participant will be unseated, since it currently happens
                // silently.
                ->modalDescription(function (Seat $record): ?string {
                    $occupant = $record->assignment?->registration?->user?->name;

                    if ($occupant === null) {
                        return null;
                    }

                    // seating.delete.occupied_warning is a plain string translation
                    // (no pluralization), so this always resolves to a string; the
                    // cast satisfies modalDescription()'s string|Htmlable|null contract
                    // against trans()'s wider array|string|null return type.
                    return (string) trans('seating.delete.occupied_warning', ['name' => $occupant]);
                }),
        ];
    }
}
