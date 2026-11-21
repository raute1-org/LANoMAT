<?php

declare(strict_types=1);

namespace App\Modules\Catering\Console;

use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Support\DishImageStore;
use App\Support\Images\InvalidImageException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

/**
 * Mass-assigns descriptions and photos to the dishes of one food round from a JSON list
 * (a scrape or export of the restaurant's own menu page) and a folder of images.
 *
 * Only `description` and `image` of existing menu entries are written; name, price and key are
 * never touched, so placed orders stay valid. Existing descriptions/images are kept unless
 * `--force` is given. Matching is by normalised name (lower case, no accents, no punctuation),
 * narrowed by the category through a fixed table; a name that is unique in the round also matches
 * when the category is unknown.
 */
class ImportDishMediaCommand extends Command
{
    /**
     * Website category -> prefix used in the round's dish names ("Hähnchen: Chicken Hawaii").
     * Keys and values are compared normalised, so spelling variants of the same word collapse.
     */
    private const CATEGORY_PREFIXES = [
        'Angebote' => 'Angebot',
        'Vorspeisen' => 'Vorspeise',
        'Hähnchen Gerichte' => 'Hähnchen',
        'Chef Spezialitäten' => 'Chefs Spezialität',
        'Enten Gerichte' => 'Ente',
        'Tandoori Gerichte' => 'Tandoori',
        'Lamm Gerichte' => 'Lamm',
        'Biryani Gerichte' => 'Biryani',
        'Meeresfrüchte' => 'Meeresfrüchte',
        'Reis Gerichte' => 'Reis',
        'Naan Brot' => 'Naan',
        'Salate' => 'Salat',
        'Suppen Hänchen' => 'Suppe',
        'Suppen Hähnchen' => 'Suppe',
        'Suppen' => 'Suppe',
        'Vegetarische Hauptgerichte' => 'Vegetarisch',
        'Extras' => 'Extra',
        'Balti Gerichte' => 'Balti',
        'Beilagen' => 'Beilage',
        'Tex Mex' => 'Tex Mex',
        'Burger' => 'Burger',
        'Pasta' => 'Pasta',
        'Schnitzel' => 'Schnitzel',
        'Aufläufe' => 'Auflauf',
        'Alkoholfreie Getränke' => 'Getränk',
    ];

    protected $signature = 'catering:import-dish-media
        {foodOrder : Id of the food round}
        {json : JSON file: list of {category, name, description, image_file}}
        {--images= : Folder the image_file paths are relative to (default: the folder of the JSON)}
        {--dry-run : Show what would be assigned and write nothing}
        {--force : Overwrite descriptions and photos that dishes already have}';

    protected $description = 'Assign descriptions and photos from a JSON list and an image folder to the dishes of a food round.';

    public function handle(DishImageStore $store): int
    {
        $id = $this->argument('foodOrder');
        $order = (is_int($id) || (is_string($id) && ctype_digit($id))) ? FoodOrder::query()->find((int) $id) : null;

        if (! $order instanceof FoodOrder) {
            $this->components->error('Food round not found.');

            return self::FAILURE;
        }

        $jsonPath = $this->argument('json');

        if (! is_string($jsonPath)) {
            $this->components->error('JSON file not found.');

            return self::FAILURE;
        }

        $entries = $this->readEntries($jsonPath);

        if ($entries === null) {
            return self::FAILURE;
        }

        $imagesOption = $this->option('images');
        $imagesDir = realpath(is_string($imagesOption) && $imagesOption !== '' ? $imagesOption : dirname($jsonPath));

        if ($imagesDir === false || ! is_dir($imagesDir)) {
            $this->components->error('Image folder not found.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $menu = $order->menu;
        $index = $this->indexMenu($menu);

        /** @var array<string, array{entry: array<string, mixed>, via: string}> $matches option key => source entry */
        $matches = [];
        $unmatched = [];
        $ambiguous = [];

        foreach ($entries as $position => $entry) {
            $name = is_string($entry['name'] ?? null) ? $entry['name'] : '';
            $category = is_string($entry['category'] ?? null) ? $entry['category'] : '';
            $label = $category === '' ? $name : "{$category} / {$name}";

            if (self::normalize($name) === '') {
                $unmatched[] = [$label, 'entry has no name'];

                continue;
            }

            $found = $this->resolve($index, $category, $name);

            if ($found['status'] === 'none') {
                $unmatched[] = [$label, 'no dish with this name'];
            } elseif ($found['status'] === 'many') {
                $ambiguous[] = [$label, 'matches '.implode(' | ', $found['keys'])];
            } elseif (isset($matches[$found['keys'][0]])) {
                $ambiguous[] = [$label, 'dish "'.$found['keys'][0].'" is already taken by another JSON entry'];
            } else {
                $matches[$found['keys'][0]] = ['entry' => $entry, 'via' => $found['via'], 'position' => $position];
            }
        }

        $byKey = [];
        foreach ($menu as $option) {
            $byKey[$option->key] = $option;
        }

        $rows = [];
        $updated = [];
        $encoded = [];
        $failed = 0;

        foreach ($matches as $key => $match) {
            $option = $byKey[$key];
            $entry = $match['entry'];
            $notes = [];

            $description = $option->description;
            $incoming = MenuOption::cleanDescription($entry['description'] ?? null);

            if ($incoming !== null) {
                if ($option->description === null || $force) {
                    $description = $incoming;
                } elseif ($option->description !== $incoming) {
                    $notes[] = 'description kept';
                }
            }

            $image = $option->image;
            $file = is_string($entry['image_file'] ?? null) ? trim($entry['image_file']) : '';

            if ($file !== '') {
                if ($option->image !== null && ! $force) {
                    $notes[] = 'photo kept';
                } else {
                    $path = $this->resolveImage($imagesDir, $file);

                    if ($path === null) {
                        $notes[] = 'photo file missing';
                        $failed++;
                    } else {
                        try {
                            $image = $encoded[$path] ??= $dry
                                ? $this->checkOnly($store, $path)
                                : $store->store($path);
                        } catch (InvalidImageException) {
                            $notes[] = 'photo unreadable';
                            $failed++;
                        }
                    }
                }
            }

            if ($description !== $option->description || $image !== $option->image) {
                $updated[$key] = $option->with($description, $image);
            }

            $rows[] = [
                $option->name,
                $match['via'],
                $description !== $option->description ? 'yes' : '-',
                $image !== $option->image ? 'yes' : '-',
                implode(', ', $notes),
            ];
        }

        $this->components->info(($dry ? 'Dry run: ' : '').'matched '.count($matches).' of '.count($entries).' JSON entries to the '.count($menu).' dishes of "'.$order->title.'".');

        $this->table(['Dish in round', 'Matched by', 'Description', 'Photo', 'Notes'], $rows);

        if ($unmatched !== []) {
            $this->components->warn('JSON entries without a dish ('.count($unmatched).'):');
            $this->table(['JSON entry', 'Reason'], $unmatched);
        }

        if ($ambiguous !== []) {
            $this->components->warn('Ambiguous JSON entries, left alone ('.count($ambiguous).'):');
            $this->table(['JSON entry', 'Reason'], $ambiguous);
        }

        $untouched = array_values(array_filter($menu, static fn (MenuOption $o): bool => ! isset($matches[$o->key])));

        if ($untouched !== []) {
            $this->components->warn('Dishes of the round without a JSON entry ('.count($untouched).'):');
            $this->table(['Dish in round'], array_map(static fn (MenuOption $o): array => [$o->name], $untouched));
        }

        if ($dry) {
            $this->components->info(count($updated).' dishes would change. Nothing was written.');

            return self::SUCCESS;
        }

        if ($updated === []) {
            $this->components->info('Nothing to change.');

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        }

        DB::transaction(function () use ($order, $updated): void {
            // Re-read under a lock so a concurrent menu edit is never overwritten with a stale copy.
            $locked = FoodOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $locked->menu = array_map(
                static fn (MenuOption $option): MenuOption => isset($updated[$option->key])
                    ? $option->with($updated[$option->key]->description, $updated[$option->key]->image)
                    : $option,
                $locked->menu,
            );
            $locked->save();
        });

        $this->components->info(count($updated).' dishes updated.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function readEntries(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error('JSON file not found.');

            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->components->error('JSON file is not valid JSON: '.$e->getMessage());

            return null;
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            $this->components->error('JSON must be a list of {category, name, description, image_file}.');

            return null;
        }

        return array_values(array_filter($decoded, is_array(...)));
    }

    /**
     * @param  list<MenuOption>  $menu
     * @return list<array{key: string, prefix: string, label: string}>
     */
    private function indexMenu(array $menu): array
    {
        $index = [];

        foreach ($menu as $option) {
            $at = strpos($option->name, ': ');
            $prefix = $at === false || $at <= 0 ? '' : substr($option->name, 0, $at);
            $label = $at === false || $at <= 0 ? $option->name : substr($option->name, $at + 2);

            $index[] = ['key' => $option->key, 'prefix' => self::normalize($prefix), 'label' => self::normalize($label)];
        }

        return $index;
    }

    /**
     * @param  list<array{key: string, prefix: string, label: string}>  $index
     * @return array{status: 'one'|'many'|'none', keys: list<string>, via: string}
     */
    private function resolve(array $index, string $category, string $name): array
    {
        $needle = self::normalize($name);
        $byName = array_values(array_filter($index, static fn (array $row): bool => $row['label'] === $needle));

        $prefix = $this->prefixFor($category);

        if ($prefix !== null) {
            $inCategory = array_values(array_filter($byName, static fn (array $row): bool => $row['prefix'] === $prefix));

            if ($inCategory !== []) {
                return $this->outcome($inCategory, 'category + name');
            }
        }

        return $this->outcome($byName, 'name only');
    }

    /**
     * @param  list<array{key: string, prefix: string, label: string}>  $rows
     * @return array{status: 'one'|'many'|'none', keys: list<string>, via: string}
     */
    private function outcome(array $rows, string $via): array
    {
        $keys = array_column($rows, 'key');

        return [
            'status' => count($keys) === 1 ? 'one' : ($keys === [] ? 'none' : 'many'),
            'keys' => $keys,
            'via' => $via,
        ];
    }

    private function prefixFor(string $category): ?string
    {
        $needle = self::normalize($category);

        foreach (self::CATEGORY_PREFIXES as $website => $prefix) {
            if (self::normalize($website) === $needle) {
                return self::normalize($prefix);
            }
        }

        return null;
    }

    /** Lower case, accents and punctuation removed, whitespace collapsed. */
    public static function normalize(string $value): string
    {
        $ascii = Str::lower(Str::ascii($value));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }

    /** The image file inside the folder, or null when missing or when it would leave the folder. */
    private function resolveImage(string $dir, string $file): ?string
    {
        $path = realpath($dir.DIRECTORY_SEPARATOR.$file);

        if ($path === false || ! is_file($path) || ! str_starts_with($path, $dir.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    /** A dry run proves the file decodes and returns a stand-in path without writing. */
    private function checkOnly(DishImageStore $store, string $path): string
    {
        $binary = $store->encode($path);

        return DishImageStore::DIRECTORY.'/'.substr(hash('sha256', $binary), 0, 16).'.webp';
    }
}
