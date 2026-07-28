# Double Elimination for Any Field Size + Visible Byes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let double-elimination tournaments run at any field size from 2 to 64 (including odd counts), and show the resulting byes as "Freilos" in the bracket.

**Architecture:** A new pure domain class `LbIntakeOrdering` computes the losers-bracket intake permutations by an exhaustive lookahead search over four canonical orderings, replacing the hand-maintained per-size table in `BracketGenerator`. `StartTournament`'s exact-count allowlist becomes a `2..64` range check. Byes need no schema change — they are derived as an `isBye` flag on the existing `BracketMatchProjection` chokepoint and rendered in `BracketMatchCard.vue`.

**Tech Stack:** PHP 8.4 · Laravel 13 · Pest 4 · Inertia v2 + Vue 3 + Tailwind v4

**Spec:** `docs/superpowers/specs/2026-07-27-double-elimination-any-size-and-visible-byes-design.md`

## Global Constraints

- Code, comments, commits and docs in **English**; UI copy in **German** via `lang/de/`.
- Conventional Commits (`feat(scope): …`). TDD: the failing test comes first.
- `composer check` (pint, phpstan level 8, pest) must be green after every task.
- Frontend gate is all four: `npm run types:check`, `npm run lint:check`, `npm run format:check`, `npm run build`.
- PHP: no `mixed` returns in own code; enums over magic strings; Larastan level 8.
- Vue: `<script setup lang="ts">`, no `<style>` blocks, Tailwind + shadcn-vue only.
- Design system is binding (`docs/design.md`): use semantic role utilities (`text-muted-foreground`, `font-mono`), never raw hex. The signal-amber accent stays reserved for live state — a bye is informational and must render in the calm register.
- Bracket domain code (`app/Modules/Tournaments/Domain/`) is **pure**: no IO, no persistence, no framework dependencies.

## File Structure

| File | Responsibility |
| --- | --- |
| `app/Modules/Tournaments/Domain/LbIntakeOrdering.php` (create) | Pure: given a bracket size, compute the intake permutation per LB intake round. Knows nothing about match ids — works in positions only. |
| `app/Modules/Tournaments/Domain/BracketGenerator.php` (modify) | Loses `lbIntakeOrder()`'s table + size exception; consumes `LbIntakeOrdering` instead. |
| `app/Modules/Tournaments/Actions/StartTournament.php` (modify) | Allowlist -> `2..64` range check. |
| `app/Modules/Tournaments/Exceptions/TournamentException.php` (modify) | Reword `unsupportedDoubleEliminationSize` for the new range. |
| `app/Modules/Tournaments/Support/BracketMatchProjection.php` (modify) | Derive and expose `isBye`. |
| `resources/js/types/tournaments.ts` (modify) | Add `isBye: boolean` to `BracketMatchDto`. |
| `resources/js/components/bracket/BracketMatchCard.vue` (modify) | Render "Freilos" in the empty slot of a bye match. |
| `lang/de/tournaments.php` (modify) | `bracket.bye` label + reworded error string. |
| `tests/Unit/Tournaments/Domain/LbIntakeOrderingTest.php` (create) | Table reproduction, minimum overlap, determinism. |
| `tests/Unit/Tournaments/Domain/DoubleEliminationTest.php` (modify) | Extend datasets to the new sizes. |
| `tests/Unit/Tournaments/Domain/BracketPropertyTest.php` (modify) | Convergence + the fairness property at all sizes. |
| `tests/Feature/Tournaments/StartTournamentTest.php` (modify) | Previously-rejected counts now start; out-of-range still throws. |
| `tests/Feature/Tournaments/BracketByeProjectionTest.php` (create) | `isBye` true only for real byes. |

Tasks 1–2 are pure domain and carry the risk; 3–4 lift the caps; 5–6 are the bye UI.

---

### Task 1: `LbIntakeOrdering` — computed intake permutations

The load-bearing task. Everything else is plumbing.

**Files:**
- Create: `app/Modules/Tournaments/Domain/LbIntakeOrdering.php`
- Test: `tests/Unit/Tournaments/Domain/LbIntakeOrderingTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `LbIntakeOrdering::forSize(int $size): array<int, list<int>>` — maps a WB round number `k` (2-indexed; WB round 1's losers are always paired naturally and never appear here) to a permutation of positions `0..m-1`, where `m` is the number of WB round-`k` matches. Position `i` of the returned list names the WB-loser index that faces LB survivor `i`. Rounds with `m <= 1` (the LB final) map to `[0]`.

**Background the implementer needs.** The losers bracket alternates two kinds of round: *intake* rounds, which absorb a fresh batch of WB losers, and *minor* rounds, which halve the LB survivors internally. Before zipping a batch of WB losers against the survivors, the batch is permuted so a dropped entrant does not immediately re-meet someone it already played in the WB.

Reason in terms of **lineage**: the set of WB round-1 match indices that can reach a given LB slot. A WB round-`k` match `j` spans round-1 indices `[j·2^(k-1), (j+1)·2^(k-1))`, and every WB opponent a dropped entrant could have faced lives inside its own span. A pairing is **clean** when span and lineage are disjoint.

Two facts, both verified before this plan was written — do not re-litigate them:

1. **Lookahead is mandatory.** Greedily taking the first clean ordering is wrong: at size 16, `reverse` at `k=2` is locally clean but saturates the lineages and leaves `k=3` with no clean option at all. The search must score whole sequences.
2. **Zero overlap is impossible above size 16.** At size 32, each survivor entering the `k=4` intake covers at least 12 of the 16 round-1 indices while the incoming span covers 8; `12 + 8 > 16` forces an overlap for *any* permutation. So the objective is **minimum total overlap**, not zero.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Tournaments/Domain/LbIntakeOrderingTest.php`:

```php
<?php

use App\Modules\Tournaments\Domain\LbIntakeOrdering;

/**
 * Applies an ordering result to a natural [0..m-1] list, so a test can assert
 * the resulting permutation rather than an ordering's name.
 */
function permutationFor(int $size, int $k): array
{
    return LbIntakeOrdering::forSize($size)[$k];
}

it('reproduces the previously hand-maintained ordering table for size 8', function () {
    // size 8, k=2: 'reverse' over 2 positions.
    expect(permutationFor(8, 2))->toBe([1, 0]);
})->group('bracket');

it('reproduces the previously hand-maintained ordering table for size 16', function () {
    // size 16, k=2: 'reverse_half_shift' over 4 positions -> [1,0,3,2].
    expect(permutationFor(16, 2))->toBe([1, 0, 3, 2]);
    // size 16, k=3: 'reverse' over 2 positions -> [1,0].
    expect(permutationFor(16, 3))->toBe([1, 0]);
})->group('bracket');

it('leaves the losers-bracket final unpermuted, since a single loser drops in', function () {
    // The last WB round always drops exactly one loser.
    expect(permutationFor(8, 3))->toBe([0])
        ->and(permutationFor(16, 4))->toBe([0]);
})->group('bracket');

it('has no intake rounds at all for the degenerate size 2', function () {
    expect(LbIntakeOrdering::forSize(2))->toBe([]);
})->group('bracket');

it('is deterministic', function (int $size) {
    expect(LbIntakeOrdering::forSize($size))->toBe(LbIntakeOrdering::forSize($size));
})->with([4, 8, 16, 32, 64])->group('bracket');

it('achieves the proven minimum number of overlapping intake pairs', function (int $size, int $expectedOverlap) {
    expect(overlapScoreFor($size))->toBe($expectedOverlap);
})->with([
    [4, 0],
    [8, 0],
    [16, 0],
    // Above size 16 a counting argument forces overlaps in the
    // second-to-last intake round; 2 is the proven minimum, not a shortfall.
    [32, 2],
    [64, 2],
])->group('bracket');

/**
 * Replays the losers-bracket lineage bookkeeping against the computed
 * ordering and counts how many intake pairings are NOT lineage-disjoint,
 * excluding the losers-bracket final (a single incoming loser, no freedom).
 */
function overlapScoreFor(int $size): int
{
    $rounds = (int) log($size, 2);
    $ordering = LbIntakeOrdering::forSize($size);

    // LB round 1 pairs WB round-1 losers naturally: match i covers round-1
    // matches 2i and 2i+1.
    $survivors = [];
    for ($i = 0; $i < intdiv($size, 4); $i++) {
        $survivors[] = [2 * $i, 2 * $i + 1];
    }

    $overlap = 0;

    for ($k = 2; $k <= $rounds; $k++) {
        $intakeSize = intdiv($size, 2 ** $k);

        // Minor (halving) rounds until the survivor count matches the batch.
        while (count($survivors) > $intakeSize) {
            $merged = [];
            for ($i = 0; $i < intdiv(count($survivors), 2); $i++) {
                $merged[] = array_values(array_unique(
                    array_merge($survivors[2 * $i], $survivors[2 * $i + 1]),
                ));
            }
            $survivors = $merged;
        }

        $span = 2 ** ($k - 1);
        $spans = [];
        for ($j = 0; $j < $intakeSize; $j++) {
            $spans[] = range($j * $span, ($j + 1) * $span - 1);
        }

        $permutation = $ordering[$k];
        $next = [];

        for ($i = 0; $i < $intakeSize; $i++) {
            $incoming = $spans[$permutation[$i]];

            if ($intakeSize > 1 && array_intersect($incoming, $survivors[$i]) !== []) {
                $overlap++;
            }

            $next[] = array_values(array_unique(array_merge($survivors[$i], $incoming)));
        }

        $survivors = $next;
    }

    return $overlap;
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Tournaments/Domain/LbIntakeOrderingTest.php`
Expected: FAIL — `Class "App\Modules\Tournaments\Domain\LbIntakeOrdering" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Modules/Tournaments/Domain/LbIntakeOrdering.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Tournaments\Domain;

/**
 * Computes the losers-bracket intake orderings for a double-elimination
 * bracket of a given size. Pure positional computation: no IO, no match ids,
 * no framework dependencies.
 *
 * ## What an intake ordering is for
 *
 * The losers bracket alternates *intake* rounds (which absorb a fresh batch of
 * winners-bracket losers) and *minor* rounds (which halve the LB survivors
 * internally). Before a batch of WB losers is zipped index-for-index against
 * the current LB survivors, the batch is permuted, so that an entrant dropping
 * out of the WB does not immediately re-meet an opponent it already played.
 *
 * ## How the permutation is chosen
 *
 * The unit of reasoning is *lineage*: the set of WB round-1 match indices that
 * can reach a given LB slot. A WB round-`k` match `j` spans round-1 indices
 * `[j*2^(k-1), (j+1)*2^(k-1))`, and every WB opponent a dropped entrant could
 * have faced lives inside its own span. A pairing is *clean* when span and
 * lineage are disjoint.
 *
 * Candidates are the four canonical permutation families used by mainstream
 * bracket generators (natural, reverse, half-shift, reverse-half-shift). The
 * chosen sequence is the one minimising the total number of non-clean intake
 * pairings across all rounds, with the candidate order as the tie-break.
 *
 * ## Two properties worth knowing before changing this
 *
 * 1. **The search must look ahead.** Greedily taking the first clean ordering
 *    is wrong: at size 16, `reverse` at k=2 is locally clean but saturates the
 *    survivor lineages and strands k=3 with no clean option at all. Only
 *    whole-sequence scoring finds the working combination.
 *
 * 2. **Zero overlap is impossible above size 16 — by counting, not by
 *    weakness of this search.** At size 32 a survivor entering the k=4 intake
 *    has a lineage of at least 12 of the 16 round-1 indices (2 after LB round
 *    1; +2 at k=2 -> 4; the minor round before k=3 unions two of those -> 8;
 *    +4 at k=3 -> 12; the minor round before k=4 only unions further), while
 *    the incoming span covers 8. Since 12 + 8 > 16 the sets cannot be
 *    disjoint. The minimum total overlap is therefore 2 for sizes 32 and 64,
 *    confined to the second-to-last intake round. That is inherent to double
 *    elimination at those field sizes, not a defect.
 *
 * The computed result reproduces the hand-maintained per-size table this class
 * replaced (size 8 -> reverse; size 16 -> reverse-half-shift then reverse),
 * which is locked by test.
 */
final class LbIntakeOrdering
{
    /**
     * Permutation families to try at each intake round, in tie-break
     * preference order. Named rather than stored as callables, so Larastan
     * level 8 can see through the dispatch in {@see permute()}.
     *
     * @var list<string>
     */
    private const CANDIDATES = ['natural', 'reverse', 'half_shift', 'reverse_half_shift'];

    /**
     * Applies one named permutation family to a natural position list.
     *
     * @param  list<int>  $positions
     * @return list<int>
     */
    private static function permute(string $candidate, array $positions): array
    {
        return match ($candidate) {
            'natural' => $positions,
            'reverse' => array_values(array_reverse($positions)),
            'half_shift' => self::halfShift($positions),
            'reverse_half_shift' => self::reverseHalfShift($positions),
        };
    }

    /**
     * The intake permutation for every WB round `k` whose losers drop into
     * the losers bracket (k = 2..log2(size); WB round 1's losers are paired
     * among themselves naturally and never appear here).
     *
     * Position `i` of a returned list names the WB-loser index that faces LB
     * survivor `i`. A round with a single incoming loser (the LB final) maps
     * to `[0]`.
     *
     * @return array<int, list<int>> keyed by WB round number
     */
    public static function forSize(int $size): array
    {
        $rounds = (int) log($size, 2);

        // Size 2: a single WB round, no losers bracket at all.
        if ($rounds < 2) {
            return [];
        }

        // LB round 1 pairs WB round-1 losers naturally: LB match i can be
        // reached from WB round-1 matches 2i and 2i+1.
        $survivors = [];
        for ($i = 0; $i < intdiv($size, 4); $i++) {
            $survivors[] = [2 * $i, 2 * $i + 1];
        }

        $best = self::search($size, $rounds, 2, $survivors);

        // A solution always exists: `natural` is always applicable, so the
        // search can never exhaust its candidates without reaching the end.
        return $best['ordering'];
    }

    /**
     * Exhaustively scores every candidate sequence from round `$k` onward and
     * returns the cheapest. Bounded by 4^(log2(size)-1) leaves — at most 1024
     * for size 64, over sets of at most 64 small integers.
     *
     * @param  list<list<int>>  $survivors  lineage per LB survivor, in position order
     * @return array{score: int, ordering: array<int, list<int>>}
     */
    private static function search(int $size, int $rounds, int $k, array $survivors): array
    {
        if ($k > $rounds) {
            return ['score' => 0, 'ordering' => []];
        }

        $intakeSize = intdiv($size, 2 ** $k);
        $survivors = self::applyMinorRounds($survivors, $intakeSize);
        $spans = self::spansFor($size, $k);

        // The LB final: exactly one incoming loser, so there is nothing to
        // permute and any overlap is structurally unavoidable.
        if ($intakeSize <= 1) {
            $next = [self::union($survivors[0], $spans[0])];
            $rest = self::search($size, $rounds, $k + 1, $next);

            return [
                'score' => $rest['score'],
                'ordering' => [$k => [0]] + $rest['ordering'],
            ];
        }

        $best = null;

        foreach (self::CANDIDATES as $candidate) {
            $permutation = self::permute($candidate, range(0, $intakeSize - 1));

            $overlap = 0;
            $next = [];

            for ($i = 0; $i < $intakeSize; $i++) {
                $incoming = $spans[$permutation[$i]];

                if (array_intersect($incoming, $survivors[$i]) !== []) {
                    $overlap++;
                }

                $next[] = self::union($survivors[$i], $incoming);
            }

            $rest = self::search($size, $rounds, $k + 1, $next);
            $total = $rest['score'] + $overlap;

            if ($best === null || $total < $best['score']) {
                $best = [
                    'score' => $total,
                    'ordering' => [$k => $permutation] + $rest['ordering'],
                ];
            }
        }

        // CANDIDATES is never empty, so a best branch always exists.
        return $best ?? ['score' => 0, 'ordering' => []];
    }

    /**
     * Halves the LB survivors (pairing neighbours, exactly as the generator
     * builds its minor rounds) until their count matches the incoming batch.
     *
     * @param  list<list<int>>  $survivors
     * @return list<list<int>>
     */
    private static function applyMinorRounds(array $survivors, int $intakeSize): array
    {
        while (count($survivors) > $intakeSize) {
            $merged = [];

            for ($i = 0; $i < intdiv(count($survivors), 2); $i++) {
                $merged[] = self::union($survivors[2 * $i], $survivors[2 * $i + 1]);
            }

            $survivors = $merged;
        }

        return $survivors;
    }

    /**
     * The WB round-1 index span of each WB round-`k` match, in position order.
     *
     * @return list<list<int>>
     */
    private static function spansFor(int $size, int $k): array
    {
        $count = intdiv($size, 2 ** $k);
        $width = 2 ** ($k - 1);

        $spans = [];
        for ($j = 0; $j < $count; $j++) {
            $spans[] = range($j * $width, ($j + 1) * $width - 1);
        }

        return $spans;
    }

    /**
     * @param  list<int>  $a
     * @param  list<int>  $b
     * @return list<int>
     */
    private static function union(array $a, array $b): array
    {
        return array_values(array_unique(array_merge($a, $b)));
    }

    /**
     * Swaps the two halves ([0,1,2,3] -> [2,3,0,1]).
     *
     * @param  list<int>  $positions
     * @return list<int>
     */
    private static function halfShift(array $positions): array
    {
        $half = intdiv(count($positions), 2);

        return array_values(array_merge(
            array_slice($positions, $half),
            array_slice($positions, 0, $half),
        ));
    }

    /**
     * Reverses each half in place ([0,1,2,3] -> [1,0,3,2]).
     *
     * @param  list<int>  $positions
     * @return list<int>
     */
    private static function reverseHalfShift(array $positions): array
    {
        $half = intdiv(count($positions), 2);

        return array_values(array_merge(
            array_reverse(array_slice($positions, 0, $half)),
            array_reverse(array_slice($positions, $half)),
        ));
    }
}
```

Note on `[$k => …] + $rest['ordering']`: PHP's `+` on arrays preserves existing keys and appends the rest, which is what we want since `$k` never collides with the deeper rounds' keys.

- [ ] **Step 4: Run the test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Tournaments/Domain/LbIntakeOrderingTest.php`
Expected: PASS — all cases, including the size 8/16 table reproduction and the overlap scores 0/0/0/2/2.

If the size-16 case fails, the lineage bookkeeping in `applyMinorRounds` has drifted from the generator's actual minor-round pairing — compare against `BracketGenerator::doubleElimination()`'s `while (count($survivorIds) > $intakeSize)` loop, which pairs `survivorIds[2*i]` with `survivorIds[2*i+1]`.

- [ ] **Step 5: Run the quality gate**

Run: `composer check`
Expected: pint, phpstan level 8, and the full pest suite all green.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Tournaments/Domain/LbIntakeOrdering.php tests/Unit/Tournaments/Domain/LbIntakeOrderingTest.php
git commit -m "feat(tournaments): compute losers-bracket intake orderings instead of tabulating them"
```

---

### Task 2: Wire the generator to the computed ordering

**Files:**
- Modify: `app/Modules/Tournaments/Domain/BracketGenerator.php` (replace `lbIntakeOrder()` and `reverseHalfShift()`, lines ~421-485; update the `doubleElimination()` docblock at ~105-126 and its call site at ~329)
- Modify: `tests/Unit/Tournaments/Domain/DoubleEliminationTest.php`
- Modify: `tests/Unit/Tournaments/Domain/BracketPropertyTest.php`

**Interfaces:**
- Consumes: `LbIntakeOrdering::forSize(int $size): array<int, list<int>>` from Task 1.
- Produces: `BracketGenerator::doubleElimination(array $entryIds): BracketPlan` — unchanged signature, now accepting any `count($entryIds) >= 2` with no size ceiling.

- [ ] **Step 1: Write the failing tests**

First extend the existing datasets. In `tests/Unit/Tournaments/Domain/DoubleEliminationTest.php`, change **every** `->with([4, 8, 16, 6])` to:

```php
})->with([4, 8, 16, 6, 32, 64, 5, 7, 17, 23, 31]);
```

and the single power-of-two-only case `->with([4, 8, 16])` (the "exactly 2n-1 potential matches" test) to:

```php
})->with([4, 8, 16, 32, 64]);
```

Then in `tests/Unit/Tournaments/Domain/BracketPropertyTest.php`, extend the double-elimination convergence dataset from `->with([4, 8, 16])` to:

```php
})->with([4, 8, 16, 32, 64, 5, 7, 17, 23, 31]);
```

and append the fairness property — the narrow guarantee the ordering actually provides, measured rather than assumed:

```php
/**
 * The intake ordering's real guarantee: an entrant's FIRST losers-bracket
 * match is not a rematch of a winners-bracket opponent.
 *
 * Two exemptions, both structural rather than incidental:
 *  - the LB final, where a single WB-final loser drops in with no reordering
 *    freedom, and
 *  - above bracket size 16, the second-to-last intake round, where a counting
 *    argument forces overlaps for any permutation whatsoever (see
 *    LbIntakeOrdering's class docblock).
 */
it('never opens an entrant\'s losers-bracket run with a winners-bracket rematch', function (int $n) {
    $bracketSize = 1;
    while ($bracketSize < $n) {
        $bracketSize *= 2;
    }
    $lastLbRound = 2 * ((int) log($bracketSize, 2) - 1);
    // Above size 16 the second-to-last intake round is provably forced; it
    // lands two LB rounds before the LB final (an intake round preceded by a
    // minor round).
    $exemptFrom = $bracketSize > 16 ? $lastLbRound - 2 : $lastLbRound;

    foreach (range(1, 5) as $seed) {
        $plan = app(BracketGenerator::class)->doubleElimination(range(1, $n));
        $rng = new Randomizer(new Mt19937($seed));
        $progressor = app(BracketProgressor::class);

        $wbOpponents = [];
        $playedLb = [];

        do {
            $playable = collect($plan->matches())
                ->first(fn ($m) => $m->isPlayable() && ! $m->isDecided());

            if ($playable === null) {
                break;
            }

            $a = $playable->slot1->entryId();
            $b = $playable->slot2->entryId();

            if ($a !== null && $b !== null) {
                if ($playable->bracket === Bracket::Losers && $playable->round < $exemptFrom) {
                    foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
                        expect(isset($playedLb[$x]) || ! isset($wbOpponents[$x][$y]))
                            ->toBeTrue("entry {$x} opened its LB run against WB opponent {$y} in LB round {$playable->round} (n={$n}, seed={$seed})");
                    }

                    $playedLb[$a] = true;
                    $playedLb[$b] = true;
                } elseif ($playable->bracket === Bracket::Losers) {
                    $playedLb[$a] = true;
                    $playedLb[$b] = true;
                }

                if ($playable->bracket === Bracket::Winners) {
                    $wbOpponents[$a][$b] = true;
                    $wbOpponents[$b][$a] = true;
                }
            }

            $s1 = $rng->getInt(0, 2);
            $s2 = $s1 === 2 ? $rng->getInt(0, 1) : 2;
            $plan = $progressor->apply($plan, $playable->id, $s1, $s2);
        } while (true);
    }
})->with([4, 8, 16, 32, 64]);
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Tournaments/Domain/DoubleEliminationTest.php tests/Unit/Tournaments/Domain/BracketPropertyTest.php`
Expected: FAIL — `InvalidArgumentException: Double elimination LB intake ordering is only defined for size 4, 8 or 16, got 32.`

- [ ] **Step 3: Replace the table with the computed ordering**

In `BracketGenerator.php`, delete the `lbIntakeOrder()` and `reverseHalfShift()` methods entirely and replace the call site. The current line reads:

```php
$orderedWbLoserIds = $this->lbIntakeOrder($size, $k, $wbLoserRoundIds);
```

Replace it with a lookup into the ordering computed once before the LB loop. Add this immediately before the `for ($k = 2; $k <= $wbRoundCount; $k++)` loop:

```php
// Intake permutations for every WB round whose losers drop into the LB,
// computed from the bracket's own structure (see LbIntakeOrdering).
$intakeOrdering = LbIntakeOrdering::forSize($size);
```

and change the call site to:

```php
$orderedWbLoserIds = array_map(
    static fn (int $position): int => $wbLoserRoundIds[$position],
    $intakeOrdering[$k],
);
```

Add the import at the top of the file if the class is referenced by short name (same namespace — `App\Modules\Tournaments\Domain` — so **no** `use` statement is needed).

Then replace the size-limitation paragraph in the `doubleElimination()` docblock. The current text ends with "…This ordering table is verified (by construction and by exhaustive simulation during development) for size 4, 8 and 16 — the sizes this engine is exhaustively tested against." Replace that closing sentence with:

```
 * ordering before zipping them against the current LB survivors, so that a
 * dropped WB loser does not immediately re-meet an opponent from the same
 * recent bracket lineage. That ordering is computed from the bracket's own
 * structure by {@see LbIntakeOrdering} — there is no size ceiling; field
 * sizes from 2 to 64 are exhaustively tested.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Tournaments/Domain/`
Expected: PASS — including the unchanged 4/8/16 structural assertions, which prove existing brackets are untouched.

- [ ] **Step 5: Run the quality gate**

Run: `composer check`
Expected: green. Larastan may flag the deleted methods' now-unused imports (`InvalidArgumentException` is still used by the `$n < 2` guards, so it stays).

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Tournaments/Domain/BracketGenerator.php tests/Unit/Tournaments/Domain/
git commit -m "feat(tournaments): remove the double-elimination bracket size ceiling"
```

---

### Task 3: Lift the `StartTournament` field-size cap

The generator now accepts any size, but the action still rejects almost every non-power-of-two count.

**Files:**
- Modify: `app/Modules/Tournaments/Actions/StartTournament.php:34-41,74-77`
- Modify: `app/Modules/Tournaments/Exceptions/TournamentException.php:44-50`
- Modify: `lang/de/tournaments.php` (the `errors.unsupported_double_elimination_size` entry)
- Modify: `tests/Feature/Tournaments/StartTournamentTest.php`

**Interfaces:**
- Consumes: `BracketGenerator::doubleElimination()` from Task 2 (now uncapped).
- Produces: `StartTournament::handle(Tournament $tournament): Tournament` — unchanged signature; accepts double-elimination fields of 2..64 entries.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Tournaments/StartTournamentTest.php`. Read the existing tests in that file first and mirror their setup helper for creating a tournament with `n` checked-in entries rather than inventing a new one.

```php
it('starts a double-elimination tournament at previously rejected field sizes', function (int $n) {
    $tournament = doubleEliminationTournamentWithEntries($n);

    $started = app(StartTournament::class)->handle($tournament);

    expect($started->status)->toBe(TournamentStatus::Live)
        ->and(GameMatch::query()->where('tournament_id', $started->id)->count())
        ->toBeGreaterThan(0);
})->with([5, 7, 17, 31]);

it('still rejects a double-elimination field above the supported ceiling', function () {
    $tournament = doubleEliminationTournamentWithEntries(65);

    expect(fn () => app(StartTournament::class)->handle($tournament))
        ->toThrow(TournamentException::class);
});
```

Add a `doubleEliminationTournamentWithEntries(int $n): Tournament` helper to the file only if no equivalent already exists; if the file already builds tournaments inline, follow that style instead and drop the helper.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Tournaments/StartTournamentTest.php`
Expected: FAIL — the `[5, 7, 17, 31]` cases throw `TournamentException` from the allowlist check.

- [ ] **Step 3: Replace the allowlist with a range check**

In `StartTournament.php`, delete the constant:

```php
private const SUPPORTED_DOUBLE_ELIMINATION_SIZES = [2, 4, 6, 8, 16];
```

and replace it with:

```php
/** Matches single elimination's tested ceiling; see LbIntakeOrdering. */
private const MIN_DOUBLE_ELIMINATION_ENTRIES = 2;

private const MAX_DOUBLE_ELIMINATION_ENTRIES = 64;
```

Replace the guard:

```php
if ($tournament->format === TournamentFormat::DoubleElimination
    && ! in_array(count($entryIds), self::SUPPORTED_DOUBLE_ELIMINATION_SIZES, true)) {
    throw TournamentException::unsupportedDoubleEliminationSize(count($entryIds));
}
```

with:

```php
if ($tournament->format === TournamentFormat::DoubleElimination
    && (count($entryIds) < self::MIN_DOUBLE_ELIMINATION_ENTRIES
        || count($entryIds) > self::MAX_DOUBLE_ELIMINATION_ENTRIES)) {
    throw TournamentException::unsupportedDoubleEliminationSize(count($entryIds));
}
```

Update the class docblock paragraph, which currently reads "Double-elimination brackets are only generated (and progressed) correctly for participating-entry counts in {2, 4, 6, 8, 16} — see `BracketGenerator::doubleElimination()`'s LB intake ordering table and `BracketProgressor`'s convergence assumptions. Any other count throws before the generator is invoked." Replace it with:

```
 * Double-elimination brackets support participating-entry counts from 2 to
 * 64, matching single elimination's tested range; counts that are not a power
 * of two are padded with byes. Anything outside that range throws before the
 * generator is invoked.
```

In `TournamentException.php`, reword the message:

```php
public static function unsupportedDoubleEliminationSize(int $count): self
{
    return new self(
        "Double elimination is only supported for 2 to 64 participating entries, got {$count}.",
        'tournaments.errors.unsupported_double_elimination_size',
    );
}
```

In `lang/de/tournaments.php`, update the matching German string:

```php
'unsupported_double_elimination_size' => 'Doppel-K.-o.-System wird nur für 2 bis 64 teilnehmende Anmeldungen unterstützt.',
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Tournaments/`
Expected: PASS — including the existing `DoubleElimE2ETest`, which must remain green.

- [ ] **Step 5: Run the quality gate**

Run: `composer check`
Expected: green.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Tournaments/Actions/StartTournament.php app/Modules/Tournaments/Exceptions/TournamentException.php lang/de/tournaments.php tests/Feature/Tournaments/StartTournamentTest.php
git commit -m "feat(tournaments): accept any double-elimination field size from 2 to 64"
```

---

### Task 4: Expose byes on the shared projection

Byes are already persisted unambiguously; this surfaces them. No migration.

**Files:**
- Modify: `app/Modules/Tournaments/Support/BracketMatchProjection.php` (the `fromMatch()` return array)
- Create: `tests/Feature/Tournaments/BracketByeProjectionTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `BracketMatchProjection::fromMatch()` gains an `isBye` key of type `bool`, consumed by Task 5's `BracketMatchDto`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Tournaments/BracketByeProjectionTest.php`. Read `tests/Feature/Tournaments/TournamentPageTest.php` first and reuse its factory setup style for tournaments/entries/matches.

```php
<?php

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Support\BracketMatchProjection;

it('marks a completed match with a single entrant as a bye', function () {
    $match = GameMatch::factory()->create([
        'status' => MatchStatus::Completed,
        'entry2_id' => null,
        'score1' => null,
        'score2' => null,
    ]);

    expect(BracketMatchProjection::fromMatch($match->fresh())['isBye'])->toBeTrue();
});

it('does not mark a two-entrant completed match as a bye', function () {
    $match = GameMatch::factory()->create([
        'status' => MatchStatus::Completed,
        'score1' => 2,
        'score2' => 1,
    ]);

    expect(BracketMatchProjection::fromMatch($match->fresh())['isBye'])->toBeFalse();
});

it('does not mark a pending match with an unfilled slot as a bye', function () {
    $match = GameMatch::factory()->create([
        'status' => MatchStatus::Pending,
        'entry2_id' => null,
    ]);

    expect(BracketMatchProjection::fromMatch($match->fresh())['isBye'])->toBeFalse();
});
```

Check `database/factories` for the `GameMatch` factory's required attributes (it needs a `tournament_id` and at least `entry1_id`); adjust the `create()` payloads to whatever the factory demands rather than fighting it.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Tournaments/BracketByeProjectionTest.php`
Expected: FAIL — `Undefined array key "isBye"`.

- [ ] **Step 3: Derive the flag**

In `BracketMatchProjection::fromMatch()`, add the key immediately after `'status'`:

```php
'status' => $match->status->value,
'isBye' => self::isBye($match),
```

and add the private helper below `fromMatch()`:

```php
/**
 * A bye ("Freilos"): the entrant advanced without playing, because the
 * bracket was padded to the next power of two or because an upstream slot
 * turned out to be permanently unfillable.
 *
 * Both cases are already unambiguous in stored data — {@see BracketPersister}
 * resolves them before persistence and writes a `Completed` row with a single
 * occupied entry slot and no scores — so no dedicated column is needed. A
 * forfeit is deliberately NOT a bye: it has two real entrants.
 */
private static function isBye(GameMatch $match): bool
{
    if ($match->status !== MatchStatus::Completed) {
        return false;
    }

    return ($match->entry1_id === null) !== ($match->entry2_id === null);
}
```

Add `use App\Modules\Tournaments\Enums\MatchStatus;` to the imports.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Feature/Tournaments/BracketByeProjectionTest.php`
Expected: PASS — all three cases.

- [ ] **Step 5: Run the quality gate**

Run: `composer check`
Expected: green. The infoscreen scene tests consume the same projection and must stay green — if a snapshot-style assertion breaks on the new key, update it to include `isBye`.

- [ ] **Step 6: Commit**

```bash
git add app/Modules/Tournaments/Support/BracketMatchProjection.php tests/Feature/Tournaments/BracketByeProjectionTest.php
git commit -m "feat(tournaments): surface byes on the shared bracket match projection"
```

---

### Task 5: Render "Freilos" in the bracket

**Files:**
- Modify: `resources/js/types/tournaments.ts:97-117`
- Modify: `resources/js/components/bracket/BracketMatchCard.vue`
- Modify: `lang/de/tournaments.php` (new `bracket` section)
- Modify: `app/Modules/Tournaments/Http/TournamentPageController.php` (pass the new labels)

**Interfaces:**
- Consumes: `isBye: boolean` on the projection from Task 4.
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Add the German label**

In `lang/de/tournaments.php`, add a new section immediately after the `'match_status'` block:

```php
    'bracket' => [
        'bye' => 'Freilos',
    ],
```

- [ ] **Step 2: Pass the labels to the page**

In `TournamentPageController::show()`, alongside the existing `'matchStatusLabels' => trans('tournaments.match_status'),` add:

```php
'bracketLabels' => trans('tournaments.bracket'),
```

Then thread `bracketLabels` from the page component down to `BracketMatchCard` the same way `matchStatusLabels` is already threaded. Read `resources/js/pages/Tournaments/Show.vue` and `resources/js/components/bracket/BracketView.vue` / `BracketRound.vue` and follow the existing prop chain exactly — do not introduce a store or provide/inject.

- [ ] **Step 3: Extend the DTO type**

In `resources/js/types/tournaments.ts`, add to `BracketMatchDto` after `status`:

```ts
    /** True when this match was won without being played (bracket padding). */
    isBye: boolean;
```

- [ ] **Step 4: Render the bye**

In `BracketMatchCard.vue`, add the prop to the `defineProps` block:

```ts
        /** `tournaments.bracket` labels: bye. */
        bracketLabels?: Record<string, string>;
```

with `bracketLabels: () => ({}),` in the `withDefaults` defaults object.

Replace the `slotLabel` helper so an empty slot in a bye match names itself:

```ts
function slotLabel(name: string | null): string {
    if (name !== null) {
        return name;
    }

    return props.match.isBye ? (props.bracketLabels.bye ?? '—') : '—';
}
```

The empty slot must read in the calm register — informational, not a signal — so give the slot rows a muted, italic treatment when they are the bye placeholder. Change each of the two slot `<span>`s from:

```html
                <span class="truncate text-sm">{{ slotLabel(match.slot1) }}</span>
```

to:

```html
                <span
                    class="truncate text-sm"
                    :class="
                        match.isBye && !match.slot1
                            ? 'text-muted-foreground italic'
                            : ''
                    "
                    >{{ slotLabel(match.slot1) }}</span
                >
```

and the same for `slot2` (swapping `slot1` -> `slot2`). Leave the winner highlight untouched, so the card reads as "advanced, unopposed".

- [ ] **Step 5: Run the full frontend gate**

Run each, all four must pass:

```bash
npm run types:check
npm run lint:check
npm run format:check
npm run build
```

Expected: all green. If `format:check` fails, run `npx prettier --write` on the touched files and re-run.

- [ ] **Step 6: Run the backend gate**

Run: `composer check`
Expected: green — `TournamentPageTest` asserts the page's props and may need the new `bracketLabels` key added to its expectations.

- [ ] **Step 7: Commit**

```bash
git add resources/js lang/de/tournaments.php app/Modules/Tournaments/Http/TournamentPageController.php tests/
git commit -m "feat(tournaments): show byes as Freilos in the bracket"
```

---

### Task 6: Verify in the running app and update the docs

**Files:**
- Modify: `docs/pre-lan-acceptance-checklist.md` (§9, the double-elimination item)
- Modify: `README.md` (`## Project state`)
- Modify: `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` (insights)

- [ ] **Step 1: Exercise a real odd-sized double-elimination bracket**

Start the stack and drive a real tournament rather than trusting tests alone:

```bash
docker compose up -d
composer run dev
```

Seed a double-elimination tournament with an odd field (e.g. 17 checked-in entries) via tinker or the Filament panel at `/admin`, start it, and open the tournament page. Confirm with your own eyes:

- the bracket renders without a horizontal-overflow break at this size,
- the padded first-round matches show **Freilos** in the empty slot, in muted italic, and
- the entrants holding those byes appear in round 2 as already advanced.

Use the preview tooling to capture the bracket page and check the browser console for errors.

- [ ] **Step 2: Close the acceptance-checklist item**

In `docs/pre-lan-acceptance-checklist.md` §9, replace the "Double-elimination field size" bullet with:

```markdown
- **Double-elimination field size — done.** The bracket engine now supports
  double elimination for any field from 2 to 64 entrants, matching single
  elimination. The per-size intake-ordering table was replaced by a computed
  search (`LbIntakeOrdering`); non-power-of-two fields are padded with byes,
  which surface in the bracket as "Freilos". Above size 16 a small, provably
  unavoidable number of first-losers-bracket-match rematches remains — see the
  counting argument in `LbIntakeOrdering`'s class docblock.
```

- [ ] **Step 3: Update the README project state**

Add a sentence to `## Project state` in `README.md` recording that double elimination now covers 2–64 entrants with visible byes. Match the surrounding tone and keep it accurate — do not claim a tag that does not exist.

- [ ] **Step 4: Record the insight in the roadmap**

Append to the roadmap's insights section: the ordering table was replaceable by computation, greedy selection fails at size 16 (lookahead is mandatory), and zero-overlap is provably impossible above size 16 so the criterion is minimum overlap.

- [ ] **Step 5: Final full gate**

```bash
composer check
npm run types:check && npm run lint:check && npm run format:check && npm run build
```

Expected: all green.

- [ ] **Step 6: Commit**

```bash
git add docs README.md
git commit -m "docs(tournaments): record double-elimination 2..64 support and visible byes"
```

---

## Self-Review

**Spec coverage:**

| Spec section | Task |
| --- | --- |
| §4.1 extract `LbIntakeOrdering` | 1 |
| §4.2 computed ordering with lookahead | 1 |
| §4.3 minimum overlap, sizes 2..64 | 1 (overlap test), 2 (fairness property) |
| §4.4 existing brackets unchanged | 1 (table reproduction), 2 (unchanged 4/8/16 assertions) |
| §4.5 lift the `StartTournament` cap | 3 |
| §4.6 make byes visible | 4 (projection), 5 (UI) |
| §5 testing | 1–5, each task's own tests |
| §6 out of scope | no tasks — correctly absent |
| §7 risk: lineage model drift | 1, Step 4's failure hint |
| §7 risk: large-bracket rendering | 6, Step 1 |
| §7 risk: "minimum overlap" misread as a bug | 1, class docblock |

**Placeholder scan:** No TBD/TODO. Every code step carries real code. Two steps deliberately instruct the implementer to read a neighbouring file and follow its existing pattern (Task 3's tournament-factory helper, Task 5's prop chain) rather than inventing a shape this plan cannot see — those name the exact file to read.

**Type consistency:** `LbIntakeOrdering::forSize(): array<int, list<int>>` is produced in Task 1 and consumed in Task 2 as `$intakeOrdering[$k]`, a `list<int>` of positions, mapped through `$wbLoserRoundIds`. `isBye` is produced as a PHP `bool` in Task 4 and consumed as a TS `boolean` in Task 5. `bracketLabels` is introduced in Task 5 Step 2 and consumed in Step 4 with the same name.
