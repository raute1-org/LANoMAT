# Arrival planning

Participants may state when they arrive and leave; the orga see how many people to expect on site over time. Everything is optional: no data means "weiß ich noch nicht".

## Data

Table `event_registrations` gets `arrival_day`, `arrival_window`, `departure_day`, `departure_window` (all nullable). Written only through `RegisterForEvent` (optional `ArrivalPlan` argument) and `UpdateArrivalPlan` (policy `RegistrationPolicy::updateArrivalPlan`: own, non-cancelled registration, event not `finished`/`archived`). Re-registering after a cancellation starts with an empty plan.

## Time windows (single definition: `Registration\Enums\TimeWindow`)

| Value | Label (lang/de) | Range used by the forecast |
|-------|-----------------|----------------------------|
| `morning` | bis 12 Uhr | 00:00-12:00 |
| `afternoon` | 12–18 Uhr | 12:00-18:00 |
| `evening` | 18–22 Uhr | 18:00-22:00 |
| `night` | nach 22 Uhr | 22:00-24:00 |

Days come from `EventDays` (calendar days from `starts_at` to `ends_at` in the app timezone; an `ends_at` of exactly 00:00 does not add a day). Validation (`ArrivalPlanRules`, re-checked in the actions): the day must be an event day, day and window come as a pair, arrival must not be after departure (same day: window order decides; same window is fine).

## Forecast (`Registration\Support\ArrivalForecast`, pure and IO-free)

- A registration is **placed** when it has a complete arrival (day + window) on an event day. All others are **unknown** (shown as its own "Unbekannt" bar and tile, never counted as present). A plan with only a departure counts as unknown.
- A departure counts only when complete, on an event day and not before the arrival slot; otherwise the person is assumed to stay until the end.
- Expected present in slot s = placed arrivals in slots up to and including s, minus departures in slots before s. Someone who arrives and leaves in the same window counts as present for that window only (conservative peak).
- Peak = first slot with the highest present count (none when nobody is placed).
- Actual vs expected: checked-in count next to "expected so far" = placed arrivals in slots that have already started.
- Cancelled registrations are excluded everywhere.

## Orga overview

Admin panel -> Events -> edit event -> **Anreise-Übersicht** (`/admin/events/{slug}/arrival`, view access via `EventPolicy::view`): summary tiles, stacked chart of arrivals per day and window, chart of expected present per slot with peak, and a filterable/sortable table (the existing registrations CSV export is unchanged).
