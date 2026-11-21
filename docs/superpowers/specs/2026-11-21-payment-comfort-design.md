# Payment comfort: ticket types with prices, PayPal links, reminders

Status: draft for owner approval (2026-11-21).

## Intent

Participants should see in LANoMAT what they owe and get to PayPal in one click; the orga should stop
chasing people by hand. LANoMAT does **not** process payments: money moves in PayPal (or cash), and the
orga still marks "bezahlt" by hand, as today. This is the roadmap backlog item "#5 Flatrate-Bezahlkomfort",
extended by ticket types with prices and per-round food payment.

## Goals

- The orga defines **ticket types with a price** per event in the admin panel; participants pick one when
  registering.
- The orga sets **one payment link per event**: a `paypal.me/<name>` link or a PayPal Pools link (any other
  `https` URL is accepted too).
- Participants see the **open amount**, the **payment link** and a **reference text** to paste into PayPal
  on "Meine Anmeldung", on the Jetzt page ("Für dich" card) and, for food, on "Meine Bestellung".
- **Food rounds** each have a payment method (cash or PayPal) and a list of ticket types for which the round
  is **included in the ticket**.
- **Reminders** for unpaid tickets, by notification bell and Discord DM (when the Discord module is on).
- **"Bezahlt von"**: when marking a registration paid, the orga can note who paid (one person paying for
  several).

## Non-goals

- No payment provider API (PayPal Checkout, Stripe), no webhooks, no automatic "paid" detection. That stays
  backlog (rebuild spec §Backlog).
- No credit/wallet system, no kiosk (deliberately out, roadmap #5).
- No refunds, invoices or receipts.
- No early-bird logic with automatic price switching by date; the orga can deactivate one type and activate
  another by hand.

## Anti-goals (would be a failure even if it works)

- A participant is told to pay an amount that differs from what the orga expects (price changed after
  registration, or "included" food shown as payable).
- The payment UI shouts: more than one amber primary per screen, or payment nagging on every page. The
  "Für dich" card and the registration page are enough.
- A real PayPal handle, pool URL or any personal data ends up in the public repository (tests and seeders use
  placeholders such as `paypal.me/example-orga`).

## What exists today (constraints)

- `event_registrations` (Registration, core): `ticket_type` string, `status` (pending/confirmed/cancelled),
  `paid_at`, `checked_in_at`, `qr_token`. Orga toggles "bezahlt" in the admin relation manager; CSV export
  contains `paid_at`.
- Ticket types are a string list in `events.settings['tickets']` with **no admin field**; in practice every
  registration is `standard`. `TicketLabel` turns keys into labels via `lang/de/registration.php`.
- `food_orders` (Catering, optional): one row per order round with `menu` JSON; `food_order_items` have
  `price_cents` and `paid_at`; `CostSplit` sums per person. Ordering requires a **confirmed** registration
  (`FoodOrderRegistrationRequirement`, reaches Registration only via `MyRegistration`).
- Notifications: `database` channel always, `DiscordChannel` mirror when Module::Discord is enabled, per
  category preferences (see `CheckinOpened`). Dedup of scheduled sends exists in the Discord outbox.
- Infoscreen already has a "Payment-QR" scene with a free payload; unchanged.
- Architecture rules: modular monolith, optional modules may use core modules, never the reverse; every
  authorization through a Policy; Actions per use case; money as integer cents; enums over strings; UI copy
  German in `lang/de/`; Signalpult design system; repo is public.

## Approaches considered

- **A — own table for ticket types (chosen by the owner).** `ticket_types` table owned by Registration,
  admin tab on the event, price snapshot on each registration, food rounds reference ticket types by id.
  More code and a migration, but clean references and no retroactive price changes.
- **B — list in `events.settings` (JSON).** Less code, edited via a repeater. Rejected: no price snapshot,
  renaming breaks existing registrations and the food "included" list, weak validation.
- Payment provider integration (PayPal Checkout + webhooks): rejected for now (needs a business account,
  merchant fees, much more work); stays backlog.

## Design

### Data model (Registration module, core)

New table `ticket_types`:

| column | type | notes |
|---|---|---|
| id | bigint | |
| event_id | fk events, cascade | |
| key | string(64) | stable technical key, unique per event; generated from the name on create, not editable afterwards (keeps `event_registrations.ticket_type` valid when the name changes) |
| name | string | shown to participants |
| description | text, nullable | one line, e.g. "inkl. Essen und Getränke" |
| price_cents | unsigned int | 0 allowed (free ticket, orga/helpers) |
| sort | unsigned smallint | |
| active | bool | inactive types are hidden for new registrations, existing ones keep them |
| timestamps | | |

Unique index `(event_id, key)`.

`event_registrations` gains:

- `ticket_price_cents` unsigned int, nullable — **snapshot** of the price at registration time. This is the
  amount the participant owes. Never changed by later edits of `ticket_types.price_cents`. It is re-taken only
  (a) when a cancelled registration is reactivated through `RegisterForEvent` (that path already resets
  `paid_at`; it also resets `paid_by`), and (b) when the orga changes a registration's ticket type in the
  admin or runs the bulk action below. There is **no participant "change ticket" action** (builder's call):
  a participant who wants another ticket cancels and registers again while registration is open.
- `paid_by` string(120), nullable — free text the orga enters when marking paid ("Max (Sammelüberweisung)").
  Not mass assignable; never shown to other participants.

`ticket_type` on `event_registrations` stays the string **key** (no FK), so old rows, the MCP tool and the
native client API keep working. `RegisterForEvent::allowedTickets()` returns the keys of the event's
**active** ticket types from the new table for every caller (web form, MCP `EventRegisterTool`, native
client); all of them register through `RegisterForEvent`, which validates the key and takes the snapshot.
An event without any ticket type gets a free, active "Standard" type (`key = standard`, price 0) created
when the event is created and by the migration, so registration never breaks.

`MyRegistration` gains `ticketKey`, `ticketName` and `ticketPriceCents` (added fields; existing fields and
their shapes stay unchanged for current consumers, including the native client).

Event-level payment settings, also owned by Registration, new table `event_payment_settings`
(one row per event, created lazily):

| column | type | notes |
|---|---|---|
| event_id | fk events, unique, cascade | |
| payment_link | string, nullable | `https` URL; `paypal.me/...` or a pool link |
| payment_note | string(160), nullable | extra line shown with the link, e.g. "Bitte als Freunde und Familie senden" |
| reminder_after_days | unsigned smallint, default 3 | 0 = no "after registration" reminder |
| reminder_before_event_days | unsigned smallint, default 3 | 0 = no "before the LAN" reminder |

(A separate table rather than more `events` columns, because the Events module must not know about
payment; Registration owns it.)

**Migration of existing data:** for every event, create one ticket type per distinct key found in
`settings['tickets']` and in existing `event_registrations.ticket_type` (at least `standard`), name from
`TicketLabel`, price 0, active. Existing registrations get `ticket_price_cents = 0`. `settings['tickets']`
is no longer read after the migration (left in place, documented as legacy).

For events that already have registrations, the orga sets prices on the ticket types and then runs the bulk
action **"Preis aus Ticket übernehmen"** on the registrations relation manager: it re-snapshots
`ticket_price_cents` from the current ticket type for the selected registrations that are **not paid**
(paid ones are skipped and counted in the result message). Nothing is re-priced automatically.

### Payment link building (`App\Modules\Registration\Support\PaymentLink`)

Pure value object: `PaymentLink::for(?string $configuredLink, int $amountCents): ?PaymentLinkView` with
`url`, `amountAppended: bool`.

- Host `paypal.me` or `www.paypal.me` → append `/<amount>EUR` with a dot as decimal separator and no
  trailing zeros beyond cents (`25EUR`, `9.5EUR`), replacing an amount already present in the path.
- Any other `https` URL (PayPal Pools, a bank page) → returned unchanged, `amountAppended = false`.
- Null/empty link or amount 0 → null (nothing to pay, no link shown).
- Only `https` URLs are accepted in the admin form (validation rule), max 255 chars.

Reference text (`PaymentReference::for(Event, User, string $what)`):
`"<event name> · <display name> · <what>"`, `what` = ticket name, or the food round title. Max 140 chars
(truncate the event name first). Shown with a copy button and the hint "Bei PayPal als Nachricht einfügen";
paypal.me cannot prefill a message.

### Admin (Filament)

- Event resource gets a relation manager **"Tickets"** (Registration module, registered like the existing
  registrations relation manager): list with name, price (EUR, formatted), active, number of registrations;
  create/edit (name, description, price in euros with the same input/validation as catering menu prices,
  active, sort); delete only when no registration uses the key, otherwise only deactivate.
- A **"Bezahlung"** section on the same event page (Registration-owned form/relation): payment link,
  payment note, the two reminder day counts.
- Registrations relation manager: columns ticket name + price; the "Bezahlt umschalten" action opens a small
  modal with optional "Bezahlt von" when switching to paid; clearing paid clears `paid_by`. CSV export
  gains `ticket_price_eur` and `paid_by`. A filter "Zahlung offen".
- Food round form (Catering): **Bezahlart** (`cash` | `paypal`), **Zahlungslink** (optional, falls back to
  the event's link when empty and method is paypal), **Im Ticket enthalten für** (multi-select of the
  event's ticket types; options "alle"/"keine" as shortcuts).

### Participant UI

All new UI follows `docs/design.md` (Signalpult): Panel/rows, one amber primary per screen, all four states,
375 px, focus, German copy in `lang/de/`.

- **Registration page (`Event/Register.vue`)**: ticket choice shows name, description and price per active
  type (radio list); a single type is preselected; price 0 shows "kostenlos".
- **"Meine Anmeldung"** (registration status block): ticket name, amount, status "Bezahlt"/"Zahlung offen".
  When open and a link exists: amber "Mit PayPal zahlen" (opens the built link in a new tab), the reference
  text with copy button and the hint, plus `payment_note`. When open and no link: "Bitte bei der Orga
  bezahlen." When the amount is 0: no payment block.
- **Jetzt page**: a new "Für dich" card kind `payment` for the viewer's own unpaid registration with an amount
  > 0, in the upcoming and live states: "Ticket noch nicht bezahlt · 25,00 €" with action "Bezahlen" linking to
  "Meine Anmeldung" (one click to the full payment block; the card does not open PayPal directly so the
  reference text is seen). Lower priority than a ready match. (`NowFeed` gets a `PaymentCardSource` contract
  with a null implementation, bound by Registration, following the existing Vote/Order card sources.)
- **Food, "Meine Bestellung"** (Catering page and the Jetzt food panel): per round
  - included for the viewer's ticket type → items show "inklusive", no amount, no payment action;
  - method cash → "Bar bei der Orga: 9,50 €";
  - method paypal → amount, "Mit PayPal zahlen" (link with amount), reference text with copy button.
  The amber primary stays the order button while the round is open; after the round closes, the payment
  action becomes the primary.
- Native client API: out of scope for this change (the client keeps showing what it shows today); add the
  fields later. The existing native endpoints must keep passing.

### Catering cost logic

- `food_order_items` gains `included` (bool, default false): set when the item is placed, from the round's
  include-list and the orderer's ticket key (via `MyRegistration`). When the orga edits a round's include-list
  or payment method while the round is not closed, `included` is recomputed for that round's **unpaid**
  items; paid items and closed rounds are never changed (no retroactive amounts).
- The PayPal amount for food is the viewer's **due total for that round** (sum of non-included items not yet
  paid). Payment marking stays per item by the orga, plus an admin action "Alles von <Person> bezahlt" that
  sets `paid_at` on all of that person's due items of the round. `CostSplit` returns per person `totalCents`, `includedCents`, `dueCents`,
  `paidCents`; the orga export and the admin view show "inklusive" separately so the orga sees what the orga
  pays for in total.
- `price_cents` on items stays the menu price (needed for the orga's own bill); "included" never rewrites
  it.

### Reminders

- New command `registration:payment-reminders`, scheduled hourly (Registration, core), sends
  `PaymentReminder` notifications (category `payment`, new; on by default; respects preferences; database
  always, Discord DM when Module::Discord is enabled).
- Who: registrations with status `pending` or `confirmed`, `paid_at` null, `ticket_price_cents` > 0, event not
  finished/archived.
- When: (1) `reminder_after_days` after `created_at` (if > 0); (2) `reminder_before_event_days` before
  `starts_at` (if > 0 and the event has a start). If both are due at the same run (late registration), only
  the "before the LAN" reminder is sent and the other kind is recorded as sent. Sends happen only between
  09:00 and 21:00 Europe/Berlin; outside that window the command does nothing and catches up at 09:00. Each kind at most once per registration: dedupe via a new
  table `payment_reminders_sent (event_registration_id, kind, sent_at, unique(registration, kind))` — not the
  Discord outbox, because the bell notification must not depend on the Discord module.
- The message contains amount, ticket name and a link to "Meine Anmeldung" (never the raw PayPal link in the
  DM, so a changed link cannot go stale in old messages).

### Authorization and privacy

- Ticket types and payment settings: `TicketTypePolicy` and an `EventPaymentSettings` policy that mirror the
  existing `EventPolicy` update rule (orga and admin; participants never). Changing the link or note only
  affects what "Meine Anmeldung" shows; reminders never contain the raw link, so old messages cannot go stale.
- Participants see only their own amount, status and reference; `paid_by` is orga-only (admin panel, CSV).
- Registering with an inactive or foreign ticket type is rejected (validation via the event's active types).
- The payment link is shown to logged-in registered participants only (not on public event pages).

### Testing (what must be covered)

- `PaymentLink`: paypal.me with and without existing amount, `www.` host, cents formatting, pool/other URL
  unchanged, http rejected, amount 0 → null.
- Migration: existing `settings['tickets']` and registrations map to ticket types; snapshot 0.
- Registration: price snapshot on register and on ticket switch; later price edit does not change it;
  inactive type rejected.
- Admin: policy matrix for ticket types and payment settings; delete blocked when used; paid toggle with
  `paid_by`.
- Food: included vs cash vs paypal per viewer ticket type; `CostSplit` sums; export.
- Reminders: both kinds, once each, skipped when paid/cancelled/free/finished, Discord channel only when the
  module is on, preferences respected.
- Jetzt: payment card present/absent per state and amount; Registration module off is not possible (core),
  Catering off hides food payment.
- Route authorization matrix and module isolation tests stay green.

## Decided by the owner (2026-11-21)

- Ticket types with prices (multiple per event), stored in their own table (approach A).
- Payment link may be paypal.me or a PayPal pool link.
- Food: per round cash or PayPal link, and "included in the ticket" per round for a chosen set of ticket
  types (covers "all included" and "only some tickets").
- The orga keeps marking payments by hand.

## Proposed defaults (builder's calls unless the owner objects)

- Reminder timing 3 days after registration and 3 days before the LAN, editable per event.
- The Jetzt card links to "Meine Anmeldung" instead of opening PayPal directly.
- Payment link and reference never appear on public (guest) pages.
- Currency is EUR only.

## Left to the builder

- Exact component split, file names, lang keys, migration order.
- Filament layout details of the Tickets tab and the Bezahlung section.
- How the reference text is truncated beyond the rule above.
