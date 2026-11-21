<?php

namespace App\Modules\Catering\Actions;

use App\Models\User;
use App\Modules\Catering\Domain\MenuImport;
use App\Modules\Catering\Domain\MenuImportReport;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\MenuImportMode;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Exceptions\MenuProtectedException;
use App\Modules\Catering\Models\Restaurant;
use App\Support\SafeText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Applies a structured menu (read from a PDF/web page/photo by an agent) to a restaurant:
 * create a new restaurant with the menu, replace its menu, or merge new dishes into it.
 *
 * Authorization runs here (RestaurantPolicy), so the dry run is gated like the write. A dry run
 * (`$apply = false`) shares the whole planning path and never writes. Dishes that an order
 * references must survive a replace with their keys (UpdateRestaurantMenu enforces it again
 * under the restaurant row lock); merge never touches existing dishes.
 */
class ImportRestaurantMenu
{
    /**
     * @param  array<int|string, mixed>  $items
     * @param  string|null  $name  required for mode=create
     */
    public function handle(
        User $actor,
        MenuImportMode $mode,
        ?Restaurant $restaurant,
        array $items,
        ?string $name = null,
        bool $apply = true,
    ): MenuImportReport {
        $gate = Gate::forUser($actor);

        if ($mode === MenuImportMode::Create) {
            $gate->authorize('create', Restaurant::class);
        } elseif ($restaurant !== null) {
            $gate->authorize('update', $restaurant);
        } else {
            $gate->authorize('create', Restaurant::class);
        }

        $normalized = MenuImport::normalize($items);

        if (! $apply) {
            return $this->plan($mode, $restaurant, $name, $normalized->menu, $normalized->errors, $normalized->warnings);
        }

        if (! $normalized->isValid()) {
            throw ValidationException::withMessages(['items' => $normalized->errors]);
        }

        $report = DB::transaction(function () use ($mode, $restaurant, $name, $normalized): MenuImportReport {
            $locked = $restaurant !== null
                ? Restaurant::query()->whereKey($restaurant->getKey())->lockForUpdate()->firstOrFail()
                : null;

            $plan = $this->plan($mode, $locked, $name, $normalized->menu, [], $normalized->warnings);

            if (! $plan->isValid()) {
                throw CateringException::importRefused(implode(' ', array_slice($plan->errors, 0, 4)));
            }

            if ($plan->unchanged) {
                return $plan;
            }

            if ($locked === null) {
                $newName = (string) ($plan->restaurant['name'] ?? '');
                $target = new Restaurant(['name' => $newName, 'slug' => $this->uniqueSlug($newName)]);
                $target->menu = $plan->menu;
                $target->save();
            } else {
                $target = $locked;

                try {
                    app(UpdateRestaurantMenu::class)->handle($target, $plan->menu);
                } catch (MenuProtectedException $e) {
                    throw CateringException::importRefused($e->getMessage());
                }
            }

            return new MenuImportReport(
                mode: $plan->mode,
                applied: true,
                created: $locked === null,
                unchanged: false,
                errors: [],
                warnings: $plan->warnings,
                menu: $plan->menu,
                changes: $plan->changes,
                restaurant: ['id' => $target->id, 'slug' => $target->slug, 'name' => $target->name],
            );
        });

        // Audit trail: who, which restaurant, how many dishes. No dish text or prices.
        Log::info('catering.menu_import', [
            'user_id' => $actor->id,
            'restaurant_id' => $report->restaurant['id'] ?? null,
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
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function plan(
        MenuImportMode $mode,
        ?Restaurant $restaurant,
        ?string $name,
        array $incoming,
        array $errors,
        array $warnings,
    ): MenuImportReport {
        $changes = ['added' => 0, 'kept' => 0, 'removed' => 0, 'changed' => 0];
        $menu = $incoming;
        $unchanged = false;

        if ($mode === MenuImportMode::Create) {
            if ($restaurant !== null) {
                $errors[] = 'restaurant: not allowed with mode=create (use replace or merge for an existing restaurant).';
            }

            $clean = $name === null ? null : SafeText::line($name, 256);

            if ($clean === null || mb_strlen($clean) > 255) {
                $errors[] = 'name: required with mode=create (1 to 255 characters).';
            }

            $changes['added'] = count($incoming);
            $info = ['id' => null, 'slug' => null, 'name' => (string) $clean];

            if ($errors === []) {
                $twin = Restaurant::query()->where('name', $clean)->first();

                if ($twin instanceof Restaurant) {
                    if ($this->sameMenu($twin->menu, $incoming)) {
                        $unchanged = true;
                        $changes = ['added' => 0, 'kept' => count($incoming), 'removed' => 0, 'changed' => 0];
                        $info = ['id' => $twin->id, 'slug' => $twin->slug, 'name' => $twin->name];
                    } else {
                        $errors[] = 'A restaurant named "'.$clean.'" already exists (slug '.$twin->slug.'); use mode=replace or merge on it.';
                    }
                }
            }

            return new MenuImportReport($mode->value, false, false, $unchanged, $errors, $warnings, $menu, $changes, $info);
        }

        if ($restaurant === null) {
            $errors[] = 'restaurant: required with mode='.$mode->value.' (slug from restaurants_list, or event_slug with order_id).';

            return new MenuImportReport($mode->value, false, false, false, $errors, $warnings, $menu, $changes, null);
        }

        $info = ['id' => $restaurant->id, 'slug' => $restaurant->slug, 'name' => $restaurant->name];
        $existing = $restaurant->menu;

        if ($mode === MenuImportMode::Replace) {
            $byKey = [];
            foreach ($existing as $option) {
                $byKey[$option->key] = $option;
            }

            $incomingKeys = array_fill_keys(array_map(static fn (MenuOption $o): string => $o->key, $incoming), true);
            $lost = array_filter($restaurant->orderedCounts(), static fn (int $n, string $key): bool => ! isset($incomingKeys[$key]), ARRAY_FILTER_USE_BOTH);

            if ($lost !== []) {
                $errors[] = 'Dishes that were already ordered must stay in the menu with the same key, but these are missing: '
                    .implode(', ', array_map(static fn (string $key, int $n): string => "{$key} ({$n} ordered)", array_keys($lost), array_values($lost)))
                    .'. Take the keys from catering_menu_get, or use mode=merge to only add dishes.';
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

            $changes['removed'] = count(array_filter($existing, static fn (MenuOption $old): bool => ! isset($incomingKeys[$old->key])));
            $unchanged = $errors === [] && $changes['added'] === 0 && $changes['changed'] === 0 && $changes['removed'] === 0;

            return new MenuImportReport($mode->value, false, false, $unchanged, $errors, $warnings, $menu, $changes, $info);
        }

        // merge: existing dishes (and their keys) are never touched.
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

        return new MenuImportReport($mode->value, false, false, $unchanged, $errors, $warnings, $merged, $changes, $info);
    }

    /**
     * @param  list<MenuOption>  $a
     * @param  list<MenuOption>  $b
     */
    private function sameMenu(array $a, array $b): bool
    {
        // Photos are not part of an import, so they do not make two menus differ.
        $shape = static fn (array $menu): array => array_map(static fn (MenuOption $o): array => $o->with($o->description, null)->toArray(), $menu);

        return $shape($a) === $shape($b);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug(Str::limit($name, 120, '')) ?: 'restaurant';
        $slug = $base;

        for ($n = 2; Restaurant::query()->where('slug', $slug)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
