<?php

namespace App\Modules\Catering\Actions;

use App\Models\User;
use App\Modules\Catering\Domain\MenuImport;
use App\Modules\Catering\Domain\MenuImportReport;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Enums\FoodPaymentMethod;
use App\Modules\Catering\Enums\MenuImportMode;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Support\PaymentLink;
use App\Support\SafeText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Applies a structured menu (read from a PDF/web page/photo by an agent) to the
 * food rounds of an event: create a draft round, replace the menu of a round
 * nobody ordered from yet, or merge new dishes into an existing round.
 *
 * Authorization runs here (FoodOrderPolicy), so the dry run is gated like the
 * write. A dry run (`$apply = false`) shares the whole planning path and never
 * writes. The write is one transaction with the round row locked, so a
 * participant ordering at the same moment cannot slip between the check and the
 * write.
 */
class ImportFoodOrderMenu
{
    private const ROUND_FIELDS = ['title', 'opens_at', 'closes_at', 'payment_method', 'payment_link'];

    /**
     * @param  array<int|string, mixed>  $items
     * @param  array<string, mixed>  $round  optional title, opens_at, closes_at, payment_method, payment_link
     */
    public function handle(
        User $actor,
        Event $event,
        MenuImportMode $mode,
        ?FoodOrder $order,
        array $items,
        array $round = [],
        bool $apply = true,
    ): MenuImportReport {
        $gate = Gate::forUser($actor);

        if ($mode === MenuImportMode::Create) {
            $gate->authorize('create', FoodOrder::class);
        } elseif ($order !== null) {
            $gate->authorize('update', $order);
        } else {
            $gate->authorize('create', FoodOrder::class);
        }

        if ($order !== null && $order->event_id !== $event->id) {
            // A round of another event is "not found", never a hint that it exists.
            abort(404, 'Food order not found.');
        }

        $normalized = MenuImport::normalize($items);

        if (! $apply) {
            return $this->plan($event, $mode, $order, $normalized->menu, $round, $normalized->errors, $normalized->warnings);
        }

        if (! $normalized->isValid()) {
            throw ValidationException::withMessages(['items' => $normalized->errors]);
        }

        $report = DB::transaction(function () use ($event, $mode, $order, $normalized, $round): MenuImportReport {
            $locked = $order !== null
                ? FoodOrder::query()->where('event_id', $event->id)->whereKey($order->getKey())->lockForUpdate()->firstOrFail()
                : null;

            $plan = $this->plan($event, $mode, $locked, $normalized->menu, $round, [], $normalized->warnings);

            if (! $plan->isValid()) {
                throw CateringException::importRefused(implode(' ', array_slice($plan->errors, 0, 4)));
            }

            if ($plan->unchanged) {
                return $plan;
            }

            $target = $locked ?? new FoodOrder(['event_id' => $event->id]);
            $fields = $this->roundFields($round, []);

            $target->fill($fields['values']);
            $target->menu = $plan->menu;

            if (! $target->exists) {
                $target->status = FoodOrderStatus::Draft;
                $target->payment_method ??= FoodPaymentMethod::Cash;
            }

            $target->save();

            return new MenuImportReport(
                mode: $plan->mode,
                applied: true,
                created: ! $locked instanceof FoodOrder,
                unchanged: false,
                errors: [],
                warnings: $plan->warnings,
                menu: $plan->menu,
                changes: $plan->changes,
                order: ['id' => $target->id, 'title' => (string) $target->title, 'status' => $target->status->value],
            );
        });

        // Audit trail: who, which round, how many dishes. No dish text or prices.
        Log::info('catering.menu_import', [
            'user_id' => $actor->id,
            'event_id' => $event->id,
            'food_order_id' => $report->order['id'] ?? null,
            'mode' => $mode->value,
            'dish_count' => count($report->menu),
            'added' => $report->changes['added'],
            'created' => $report->created,
            'unchanged' => $report->unchanged,
        ]);

        return $report;
    }

    /**
     * @param  list<MenuOption>  $incoming
     * @param  array<string, mixed>  $round
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function plan(
        Event $event,
        MenuImportMode $mode,
        ?FoodOrder $order,
        array $incoming,
        array $round,
        array $errors,
        array $warnings,
    ): MenuImportReport {
        $roundCheck = $this->roundFields($round, $errors);
        $errors = $roundCheck['errors'];
        $warnings = [...$warnings, ...$roundCheck['warnings']];

        $changes = ['added' => 0, 'kept' => 0, 'removed' => 0, 'changed' => 0];
        $menu = $incoming;
        $unchanged = false;
        $orderInfo = null;

        if ($mode === MenuImportMode::Create) {
            if ($order !== null) {
                $errors[] = 'order_id: not allowed with mode=create (use replace or merge for an existing round).';
            }

            if (($roundCheck['values']['title'] ?? null) === null) {
                $errors[] = 'title: required with mode=create.';
            }

            $changes['added'] = count($incoming);

            if ($errors === []) {
                $twin = $this->identicalDraft($event, (string) $roundCheck['values']['title'], $incoming);

                if ($twin instanceof FoodOrder) {
                    $unchanged = true;
                    $changes = ['added' => 0, 'kept' => count($incoming), 'removed' => 0, 'changed' => 0];
                    $orderInfo = ['id' => $twin->id, 'title' => (string) $twin->title, 'status' => $twin->status->value];
                } else {
                    $orderInfo = ['id' => null, 'title' => (string) $roundCheck['values']['title'], 'status' => FoodOrderStatus::Draft->value];
                }
            }

            return new MenuImportReport($mode->value, false, false, $unchanged, $errors, $warnings, $menu, $changes, $orderInfo);
        }

        if ($order === null) {
            $errors[] = 'order_id: required with mode='.$mode->value.'.';

            return new MenuImportReport($mode->value, false, false, false, $errors, $warnings, $menu, $changes, null);
        }

        $orderInfo = ['id' => $order->id, 'title' => (string) $order->title, 'status' => $order->status->value];
        $existing = $order->menu;

        if ($order->status === FoodOrderStatus::Closed) {
            $errors[] = 'The round is closed; its menu can no longer be changed.';
        }

        $orders = $order->items()->count();

        if ($mode === MenuImportMode::Replace) {
            if ($orders > 0) {
                $errors[] = "The round already has {$orders} ordered items; replacing the menu would orphan them. Use mode=merge to add dishes.";
            }

            $byKey = [];
            foreach ($existing as $option) {
                $byKey[$option->key] = $option;
            }

            $menu = [];

            foreach ($incoming as $option) {
                $old = $byKey[$option->key] ?? null;

                // Photos are not part of an import: a dish that stays keeps its photo, and one
                // without a description in the import keeps the one it has.
                $description = $option->description ?? $old?->description;
                $menu[] = $option->with($description, $old?->image);

                if ($old === null) {
                    $changes['added']++;
                } elseif ($old->name === $option->name && $old->priceCents === $option->priceCents && $old->description === $description) {
                    $changes['kept']++;
                } else {
                    $changes['changed']++;
                }
            }

            $changes['removed'] = count(array_filter($existing, static fn (MenuOption $old): bool => ! in_array($old->key, array_map(static fn (MenuOption $o): string => $o->key, $incoming), true)));
            $unchanged = $errors === [] && $changes['added'] === 0 && $changes['changed'] === 0 && $changes['removed'] === 0 && $roundCheck['values'] === [];

            return new MenuImportReport($mode->value, false, false, $unchanged, $errors, $warnings, $menu, $changes, $orderInfo);
        }

        // merge: existing dishes (and their keys) are never touched.
        if ($roundCheck['values'] !== []) {
            $errors[] = 'Round settings (title, times, payment) can only be set with mode=create or mode=replace.';
        }

        $merged = $existing;
        $usedKeys = array_fill_keys(array_map(static fn (MenuOption $o): string => $o->key, $existing), true);
        $byName = [];
        foreach ($existing as $option) {
            $byName[mb_strtolower($option->name)] = $option;
        }

        foreach ($incoming as $option) {
            $match = null;
            foreach ($existing as $old) {
                if ($old->key === $option->key && mb_strtolower($old->name) === mb_strtolower($option->name)) {
                    $match = $old;
                    break;
                }
            }
            $match ??= $byName[mb_strtolower($option->name)] ?? null;

            if ($match !== null) {
                $changes['kept']++;

                if ($match->priceCents !== $option->priceCents) {
                    $warnings[] = 'Kept existing price for "'.SafeText::line($match->name, 80).'" ('.$match->priceCents.' cents); the import said '.$option->priceCents.'. Use mode=replace to change prices.';
                }

                if ($option->description !== null && $option->description !== $match->description) {
                    if ($match->description === null) {
                        // Filling an empty description is the one thing merge may do to an existing dish.
                        $merged = array_map(static fn (MenuOption $o): MenuOption => $o->key === $match->key ? $o->with($option->description, $o->image) : $o, $merged);
                        $changes['kept']--;
                        $changes['changed']++;
                    } else {
                        $warnings[] = 'Kept existing description for "'.SafeText::line($match->name, 80).'"; the import said something else. Use mode=replace to change descriptions.';
                    }
                }

                continue;
            }

            $key = isset($usedKeys[$option->key]) ? MenuImport::uniqueKey($option->name, $usedKeys) : $option->key;
            $usedKeys[$key] = true;
            $byName[mb_strtolower($option->name)] = $option;
            $merged[] = new MenuOption($key, $option->name, $option->priceCents, $option->description);
            $changes['added']++;
        }

        if (count($merged) > MenuImport::MAX_ITEMS) {
            $errors[] = 'The merged menu would exceed '.MenuImport::MAX_ITEMS.' dishes.';
        }

        $unchanged = $errors === [] && $changes['added'] === 0 && $changes['changed'] === 0;

        return new MenuImportReport($mode->value, false, false, $unchanged, $errors, $warnings, $merged, $changes, $orderInfo);
    }

    /**
     * Validates the optional round settings; only the fields the caller gave end up in `values`.
     *
     * @param  array<string, mixed>  $round
     * @param  list<string>  $errors
     * @return array{values: array<string, mixed>, errors: list<string>, warnings: list<string>}
     */
    private function roundFields(array $round, array $errors): array
    {
        $values = [];
        $warnings = [];
        $opens = null;
        $closes = null;

        foreach (self::ROUND_FIELDS as $field) {
            $value = $round[$field] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_string($value)) {
                $errors[] = "{$field}: must be text.";

                continue;
            }

            switch ($field) {
                case 'title':
                    $title = SafeText::line($value, 256);

                    if ($title === null || mb_strlen($title) > 255) {
                        $errors[] = 'title: must be 1 to 255 characters.';
                    } else {
                        $values['title'] = $title;
                    }
                    break;
                case 'opens_at':
                case 'closes_at':
                    try {
                        $date = CarbonImmutable::parse($value);
                        $values[$field] = $date;

                        if ($field === 'opens_at') {
                            $opens = $date;
                        } else {
                            $closes = $date;
                        }
                    } catch (Throwable) {
                        $errors[] = "{$field}: not a valid date-time (use ISO 8601, e.g. 2026-11-28T18:00:00+01:00).";
                    }
                    break;
                case 'payment_method':
                    $method = FoodPaymentMethod::tryFrom($value);

                    if ($method === null) {
                        $errors[] = 'payment_method: must be cash or paypal.';
                    } else {
                        $values['payment_method'] = $method;
                    }
                    break;
                case 'payment_link':
                    if (! PaymentLink::isValid($value)) {
                        $errors[] = 'payment_link: must be a valid https link (paypal.me needs a handle).';
                    } else {
                        $values['payment_link'] = $value;
                    }
                    break;
            }
        }

        if ($opens !== null && $closes !== null && $closes->lte($opens)) {
            $errors[] = 'closes_at: must be after opens_at.';
        }

        if (isset($values['payment_link']) && ($values['payment_method'] ?? null) !== FoodPaymentMethod::Paypal) {
            $warnings[] = 'payment_link is only used with payment_method=paypal.';
        }

        return ['values' => $values, 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Idempotency for mode=create: the same title with the same menu in a draft round of this
     * event is the same import, not a second round.
     *
     * @param  list<MenuOption>  $incoming
     */
    private function identicalDraft(Event $event, string $title, array $incoming): ?FoodOrder
    {
        $wanted = array_map(static fn (MenuOption $o): array => $o->toArray(), $incoming);

        return FoodOrder::query()
            ->where('event_id', $event->id)
            ->where('title', $title)
            ->where('status', FoodOrderStatus::Draft->value)
            ->get()
            ->first(static fn (FoodOrder $candidate): bool => array_map(static fn (MenuOption $o): array => $o->toArray(), $candidate->menu) === $wanted);
    }
}
