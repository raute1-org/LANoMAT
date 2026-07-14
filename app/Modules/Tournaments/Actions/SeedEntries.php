<?php

namespace App\Modules\Tournaments\Actions;

use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Support\Collection;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Orders a tournament's participating entries into seed order (index 0 =
 * seed 1). Entries with a manually assigned `seed` are placed first, in
 * ascending seed order; the remaining entries are appended in a
 * deterministic (but not manually predictable) shuffle so that repeated runs
 * against the same tournament/entry set produce the same order — this keeps
 * tests reproducible without making the tournament's actual seeding
 * predictable to participants ahead of time.
 */
class SeedEntries
{
    /**
     * @param  Collection<int, TournamentEntry>  $entries
     * @return array<int, int> entry ids in seed order
     */
    public function handle(Tournament $tournament, Collection $entries): array
    {
        $manuallySeeded = $entries
            ->filter(fn (TournamentEntry $entry): bool => $entry->seed !== null)
            ->sortBy('seed')
            ->values();

        $unseeded = $entries
            ->reject(fn (TournamentEntry $entry): bool => $entry->seed !== null)
            ->values();

        $randomizer = new Randomizer(new Mt19937($this->seedFor($tournament)));

        /** @var array<int, TournamentEntry> $shuffled */
        $shuffled = $randomizer->shuffleArray($unseeded->all());

        return $manuallySeeded->merge($shuffled)
            ->map(fn (TournamentEntry $entry): int => $entry->id)
            ->all();
    }

    /**
     * Derives a deterministic seed for the RNG from the tournament's primary
     * key, so the same tournament always shuffles its unseeded entries the
     * same way (reproducible in tests) while different tournaments diverge.
     */
    private function seedFor(Tournament $tournament): int
    {
        return crc32('tournament-seed-'.$tournament->getKey());
    }
}
