<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\Schemas;

use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodPaymentMethod;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Models\TicketType;
use App\Modules\Registration\Support\PaymentLink;
use App\Support\Money\Euro;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class FoodOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('catering.sections.round'))
                    ->description(__('catering.sections.round_help'))
                    ->columns(2)
                    ->schema([
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
                    ]),
                Section::make(__('catering.sections.window'))
                    ->description(__('catering.sections.window_help', ['timezone' => (string) config('lanomat.timezone', 'Europe/Berlin')]))
                    ->columns(2)
                    ->schema([
                        DateTimePicker::make('opens_at')
                            ->label(__('catering.fields.opens_at'))
                            ->timezone((string) config('lanomat.timezone', 'Europe/Berlin'))
                            ->seconds(false),
                        DateTimePicker::make('closes_at')
                            ->label(__('catering.fields.closes_at'))
                            ->timezone((string) config('lanomat.timezone', 'Europe/Berlin'))
                            ->seconds(false)
                            ->after('opens_at'),
                    ]),
                Section::make(__('catering.sections.payment'))
                    ->description(__('catering.sections.payment_help'))
                    ->columns(2)
                    ->schema([
                        Select::make('payment_method')
                            ->label(__('catering.fields.payment_method'))
                            ->options([
                                FoodPaymentMethod::Cash->value => __('catering.payment_method.cash'),
                                FoodPaymentMethod::Paypal->value => __('catering.payment_method.paypal'),
                            ])
                            ->default(FoodPaymentMethod::Cash->value)
                            ->required()
                            ->live(),
                        TextInput::make('payment_link')
                            ->label(__('catering.fields.payment_link'))
                            ->url()
                            ->maxLength(255)
                            ->placeholder('https://paypal.me/example-orga')
                            ->live(onBlur: true)
                            ->visible(fn (Get $get): bool => $get('payment_method') === FoodPaymentMethod::Paypal->value)
                            ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                if ($value !== null && $value !== '' && ! PaymentLink::isValid(is_string($value) ? $value : null)) {
                                    $fail(__('catering.validation.payment_link'));
                                }
                            }]),
                        Text::make(fn (Get $get): string => self::paymentPreview($get('payment_link')))
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => $get('payment_method') === FoodPaymentMethod::Paypal->value),
                        Select::make('included_ticket_types')
                            ->label(__('catering.fields.included_ticket_types'))
                            ->helperText(__('catering.fields.included_ticket_types_help'))
                            ->multiple()
                            ->columnSpanFull()
                            ->options(fn (Get $get): array => self::ticketOptions($get('event_id')))
                            ->default([])
                            ->hintActions([
                                Action::make('include_all')
                                    ->label(__('catering.fields.include_all'))
                                    ->action(fn (Get $get, Set $set) => $set('included_ticket_types', array_map(
                                        'strval',
                                        array_keys(self::ticketOptions($get('event_id'))),
                                    ))),
                                Action::make('include_none')
                                    ->label(__('catering.fields.include_none'))
                                    ->action(fn (Set $set) => $set('included_ticket_types', [])),
                            ]),
                    ]),
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
     * What a participant would be sent to for an example amount, so the orga
     * sees whether the link works before opening the round.
     */
    public static function paymentPreview(mixed $link): string
    {
        $link = is_string($link) ? trim($link) : '';

        if ($link === '' || ! PaymentLink::isValid($link)) {
            return __('catering.payment_preview.none');
        }

        $example = 1250;
        $url = PaymentLink::for($link, $example);

        return PaymentLink::appendsAmount($link)
            ? __('catering.payment_preview.with_amount', ['amount' => Euro::format($example), 'url' => (string) $url])
            : __('catering.payment_preview.open_amount', ['url' => (string) $url]);
    }

    /**
     * ALL of the event's ticket types (inactive ones marked), keyed by their stable key — a round
     * may still include a since-deactivated key and must stay saveable.
     *
     * @return array<string, string>
     */
    public static function ticketOptions(mixed $eventId): array
    {
        if (! is_numeric($eventId)) {
            return [];
        }

        $event = Event::query()->find((int) $eventId);

        if ($event === null) {
            return [];
        }

        return TicketType::query()
            ->where('event_id', $event->id)
            ->orderBy('sort')->orderBy('id')
            ->get()
            ->mapWithKeys(fn (TicketType $type): array => [
                $type->key => $type->active ? $type->name : $type->name.' '.__('registration.admin.ticket_types.inactive_suffix'),
            ])
            ->all();
    }

    /**
     * Converts a EUR amount typed with a decimal comma or point ("8,50", "8.5",
     * "9") into integer cents, without float arithmetic. Returns null for
     * anything that is not a plain amount with at most two decimals.
     */
    public static function euroToCents(?string $value): ?int
    {
        return Euro::toCents($value);
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
