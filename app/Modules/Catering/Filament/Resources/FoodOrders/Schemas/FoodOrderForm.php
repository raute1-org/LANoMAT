<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\Schemas;

use App\Modules\Catering\Domain\MenuOption;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class FoodOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('event_id')
                    ->label(__('catering.fields.event'))
                    ->relationship('event', 'name')
                    ->required()
                    ->searchable(),
                TextInput::make('title')
                    ->label(__('catering.fields.title'))
                    ->required()
                    ->maxLength(255)
                    ->rules([self::noNulBytes()]),
                DateTimePicker::make('opens_at')
                    ->label(__('catering.fields.opens_at')),
                DateTimePicker::make('closes_at')
                    ->label(__('catering.fields.closes_at')),
                // A typed Repeater, deliberately not a KeyValue field: each
                // row maps 1:1 onto the MenuCast/MenuOption array shape
                // {key,name,price_cents} (see roadmap insight #9). The price is
                // typed in EUR ("8,50") and converted to integer cents on the
                // way in and back for display, so the stored value stays an int.
                Repeater::make('menu')
                    ->label(__('catering.fields.menu'))
                    ->schema([
                        TextInput::make('key')
                            ->label(__('catering.fields.menu_key'))
                            ->required()
                            // Matches the option_key limit of the order request (max 64).
                            ->maxLength(64)
                            ->rules([self::noNulBytes()]),
                        TextInput::make('name')
                            ->label(__('catering.fields.menu_name'))
                            ->required()
                            ->maxLength(255)
                            ->rules([self::noNulBytes()]),
                        TextInput::make('price_cents')
                            ->label(__('catering.fields.menu_price'))
                            ->required()
                            ->inputMode('decimal')
                            ->placeholder('8,50')
                            ->suffix('€')
                            ->formatStateUsing(fn (mixed $state): ?string => is_numeric($state)
                                ? number_format((int) $state / 100, 2, ',', '')
                                : null)
                            ->dehydrateStateUsing(fn (?string $state): ?int => self::euroToCents($state))
                            ->rules([self::priceRule()]),
                    ])
                    ->columns(3)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->addActionLabel(__('catering.fields.menu_add'))
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->rules([self::uniqueMenuKeysRule()]),
            ]);
    }

    /**
     * Converts a EUR amount typed with a decimal comma or point ("8,50", "8.5",
     * "9") into integer cents, without float arithmetic. Returns null for
     * anything that is not a plain amount with at most two decimals.
     */
    public static function euroToCents(?string $value): ?int
    {
        $value = trim((string) $value);

        if (preg_match('/^(\d{1,4})(?:[.,](\d{1,2}))?$/', $value, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1]) * 100 + (int) str_pad($matches[2] ?? '0', 2, '0');
    }

    private static function priceRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $cents = self::euroToCents(is_string($value) ? $value : null);

            if ($cents === null) {
                $fail(__('catering.validation.price_format'));

                return;
            }

            if ($cents > MenuOption::MAX_PRICE_CENTS) {
                $fail(__('catering.validation.price_max', ['max' => '1.000,00']));
            }
        };
    }

    /**
     * Two options of one menu may not share a key: the first one used to win
     * silently and the counts merged (F-R2B-14).
     */
    private static function uniqueMenuKeysRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $keys = collect(is_array($value) ? $value : [])
                ->map(fn (mixed $row): mixed => is_array($row) ? ($row['key'] ?? null) : null)
                ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
                ->values();

            if ($keys->count() !== $keys->unique()->count()) {
                $fail(__('catering.validation.menu_key_duplicate'));
            }
        };
    }

    /**
     * PostgreSQL rejects NUL bytes in varchar and jsonb (F-R2B-12).
     */
    private static function noNulBytes(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && str_contains($value, "\0")) {
                $fail(__('catering.validation.no_nul_bytes'));
            }
        };
    }
}
