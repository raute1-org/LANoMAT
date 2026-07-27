# Double Elimination for Any Field Size + Visible Byes — Design

**Date:** 2026-07-27
**Status:** Draft (awaiting review)
**Closes:** the "Double-elimination field size" item in
`docs/pre-lan-acceptance-checklist.md` §9 (noted there as a deliberate
deferral, now pulled forward on request).

## 1. Goal

Two user-facing outcomes, one shared root cause:

1. **Double elimination works for every field size from 2 to 64** — today it
   is capped at an allowlist of exactly `{2, 4, 6, 8, 16}`.
2. **Byes ("Freilose") are visible** — a participant who advances a round
   without playing can see *that* they did, and why, instead of silently
   appearing one round further along.

These are entangled: byes only occur at non-power-of-two field sizes, and
double elimination currently **rejects almost every** non-power-of-two size,
so byes are effectively unreachable in that format today.

## 2. Current state (verified, not assumed)

Read from the code before designing:

- **`BracketGenerator::doubleElimination()`** builds a winners bracket (WB), a
  losers bracket (LB), and a grand final with a conditional reset. Sound for
  any size *except* that `lbIntakeOrder()` throws for any bracket size outside
  `{4, 8, 16}` — it consults a hand-maintained per-size ordering table.
- **`StartTournament`** carries a *second*, stricter cap:
  `SUPPORTED_DOUBLE_ELIMINATION_SIZES = [2, 4, 6, 8, 16]`. This is an
  allowlist of **exact entry counts**, not bracket sizes, so `n = 5, 7, 9..15,
  17..31` are rejected outright. `6` is the only permitted non-power-of-two.
- **Byes already work end to end.** `BracketGenerator` pads with `Slot::bye()`;
  `BracketProgressor::resolveAutoAdvances()` auto-resolves byes (and chains of
  them) without a report; `BracketPersister::resolveByes()` runs the same logic
  before persistence, so a bye is stored as a `Completed` `GameMatch` with one
  null entry slot, a `winner_entry_id`, and **no scores**.
- **Byes are invisible.** No `lang/` string and no frontend branch mentions a
  bye. `grep` for `bye|Freilos|wildcard` across `resources/` and `lang/`
  returns nothing.
- **`BracketMatchProjection::fromMatch()`** is the single `GameMatch` -> wire
  DTO chokepoint, shared by the tournament page and the infoscreen scenes.

## 3. What the existing ordering table actually guarantees

The table's purpose was documented only loosely ("so a dropped WB loser does
not immediately re-meet an opponent from the same recent bracket lineage").
This was **measured** rather than trusted, by playing 500 randomised playouts
per size through the real engine:

- A naive reading — "no rematches" — is false: 140–196 of 300 playouts contain
  a rematch somewhere. Rematches are normal in double elimination.
- The property that **does** hold exactly is narrower:

  > An entrant's **first** losers-bracket match is never against an opponent it
  > already faced in the winners bracket — **except at the LB final**, where a
  > single WB-final loser drops in and there is no reordering freedom.

  Measured: **0 / 500 violations** in earlier rounds for sizes 4, 8 and 16;
  the only violations are at the LB final, which is structurally unavoidable.

This narrow property is the real specification of the ordering, and it becomes
the acceptance criterion (refined in §4.3).

## 4. Design

### 4.1 Extract the ordering into a pure, computed unit

Replace the per-size table with a new pure domain class:

```
app/Modules/Tournaments/Domain/LbIntakeOrdering.php
```

It answers one question: *for a bracket of this size, which permutation is
applied to each batch of WB losers before they are zipped against the current
LB survivors?* It has no IO, no framework dependency, and no knowledge of
match ids — it works purely in positions.

`BracketGenerator` calls it once per generation and consumes the result. This
keeps the generator from growing further (it is already ~600 lines carrying
three formats) and makes the ordering independently testable.

### 4.2 How the ordering is computed

The unit of reasoning is **lineage**: the set of WB round-1 match indices that
can reach a given LB slot. A WB round-`k` match `j` spans WB round-1 indices
`[j·2^(k-1), (j+1)·2^(k-1))`. An intake pairing is **clean** when the incoming
loser's span and the LB survivor's lineage are disjoint — because the WB
opponents a dropped entrant can have faced all live inside its own span.

The ordering is chosen by an **exhaustive search over sequences** of the four
canonical permutations — `natural`, `reverse`, `half_shift`,
`reverse_half_shift` — scored by the total number of overlapping intake pairs,
with the candidate list order as the tie-break.

**Lookahead is mandatory, not an optimisation.** A greedy "first clean
ordering" is wrong: at size 16 choosing `reverse` at `k=2` is locally clean but
saturates the survivor lineages and leaves `k=3` with *no* clean option at all.
The search must evaluate whole sequences. This was verified empirically before
committing to the approach.

Cost is negligible: at most `4^5 = 1024` leaf paths for size 64, over sets of
at most 64 small integers, computed once per tournament start.

### 4.3 Zero overlap is impossible above size 16 — and that is correct

For sizes 32 and 64 the minimum achievable overlap is **2 pairs**, both in the
second-to-last intake round. This is not a search limitation; it is a counting
fact, and holds for *any* permutation whatsoever:

> At size 32 there are 16 round-1 indices. An LB survivor's lineage grows: 2
> after LB round 1; +2 at the `k=2` intake -> 4; the minor round before `k=3`
> unions two of those -> 8; +4 at the `k=3` intake -> 12. The minor round
> before `k=4` only unions further, so every survivor entering `k=4` covers at
> least 12 of 16. The incoming span covers 8. Since `12 + 8 > 16`, the two sets
> cannot be disjoint — an overlap is forced regardless of permutation.

So the acceptance criterion is **provably minimal overlap**, not zero overlap:

| Bracket size | Minimum overlapping intake pairs |
| ------------ | -------------------------------- |
| 2            | n/a — no LB rounds exist         |
| 4            | 0                                |
| 8            | 0                                |
| 16           | 0                                |
| 32           | 2 (unavoidable)                  |
| 64           | 2 (unavoidable)                  |

Bracket size 2 (`n = 2`) is the existing degenerate case: the WB has a single
round, there is no LB at all, and the WB final's loser feeds the grand final
directly. `LbIntakeOrdering` is never consulted for it.

An entrant in a 32+ field can therefore meet a previous WB opponent in their
first LB match, in the second-to-last intake round only. This is inherent to
double elimination at that field size, not a defect of this implementation.

### 4.4 Existing brackets must not change

The search **reproduces the current hand-maintained table exactly**:

- size 8 -> `reverse`
- size 16 -> `reverse_half_shift`, then `reverse`
- size 4 -> the single intake round is the LB final; no reordering, as today

This is a committed regression test, not an incidental observation: brackets
generated for existing field sizes are byte-identical to today's.

### 4.5 Lift the `StartTournament` cap

`SUPPORTED_DOUBLE_ELIMINATION_SIZES` becomes a range check of `2..64`, aligning
double elimination with single elimination's supported range. Every count in
between — including odd ones — is accepted; the generator pads to the next
power of two with byes, which the progressor already resolves.

`TournamentException::unsupportedDoubleEliminationSize` is retained for counts
below 2 or above 64, so the failure mode stays explicit rather than becoming a
confusing generator error.

### 4.6 Make byes visible

A bye is already unambiguous in stored data — a `Completed` match with exactly
one null entry slot — so **no schema change and no migration is needed**. The
discriminator is derived in `BracketMatchProjection::fromMatch()` as an
`isBye` boolean:

> `status === Completed` **and** exactly one of `entry1_id` / `entry2_id` is
> null.

A forfeit is not a bye: it has two real entrants. A "dead slot" auto-advance
(an LB slot whose feeder turned out to be a bye) satisfies the same predicate
and *is* legitimately a free pass, so it deliberately shares the treatment.

Because the projection is the shared chokepoint, the tournament page and the
infoscreen bracket scene both gain the flag at once and cannot drift.

In `BracketMatchCard.vue`, a bye renders the empty slot as **"Freilos"** (from
`lang/de/tournaments.php`) instead of the `—` placeholder, in the calm register
(`text-muted-foreground`). Per `docs/design.md` the rationed signal-amber
accent stays reserved for live state — a bye is informational, not a signal.
The winning side keeps its existing highlight, so the card reads as "advanced,
unopposed".

## 5. Testing

Pure-domain first, per the module's existing discipline.

**`LbIntakeOrdering` (unit, exhaustive):**

- Reproduces the current table for sizes 4, 8, 16 (regression lock).
- Achieves the minimum overlap in the §4.3 table for every size 4..64.
- Is deterministic: repeated calls yield identical sequences.

**`BracketGenerator` / `BracketProgressor` (property):**

- Extend the existing double-elimination property test from `[4, 8, 16]` to
  cover 32 and 64 plus odd and non-power-of-two counts (e.g. 5, 7, 17, 23, 31)
  — exactly one champion, no matches left open, for multiple random playouts.
- Extend the structural tests (`2n-1` matches, LB round count, every real
  entrant present exactly once) to the new sizes.
- Encode the §3 fairness property as a real test: no entrant's first LB match
  is against a prior WB opponent, except at the LB final and — for sizes 32/64
  — the provably-forced pairs of the second-to-last intake round.

**`StartTournament` (feature):**

- A double-elimination tournament starts successfully at previously-rejected
  counts (e.g. 5, 17, 31).
- Counts below 2 and above 64 still throw
  `unsupportedDoubleEliminationSize`.

**Byes (feature + frontend):**

- The projection sets `isBye` for a padded bye match and leaves it false for
  ordinary, forfeited, and two-entrant completed matches.
- The bracket renders "Freilos" for such a match.
- Frontend gate is the full four: `types:check`, `lint:check`, `format:check`,
  `build`.

## 6. Out of scope

- **Manual wildcard assignment.** Who receives a bye continues to follow
  seeding: manually seeded entries first, then the existing deterministic
  shuffle in `SeedEntries`. No orga UI for hand-picking bye recipients.
- **A staged "draw ceremony"** (an animated reveal of the draw). Considered and
  dropped as scope creep; the bracket already reveals the draw at start.
- **Field sizes above 64.** The algorithm generalises, but 64 matches single
  elimination's tested ceiling and no LAN scenario needs more.
- **Round-robin and single elimination** are untouched.

## 7. Risks

| Risk | Mitigation |
| --- | --- |
| The lineage model in the ordering unit drifts from how the generator actually builds the LB (minor-round pairing order). | The model was validated by reproducing the existing table exactly for sizes 8 and 16; that reproduction is a committed test. |
| Larger fields make bracket rendering unwieldy (64 entrants, many LB rounds). | Rendering is out of scope for correctness, but the bracket view is checked at size 32/64 for horizontal-scroll sanity; no layout redesign is undertaken. |
| Someone reads "minimum overlap" as a bug. | The counting argument is documented in the class docblock, not just here, so the next reader finds it at the code. |
