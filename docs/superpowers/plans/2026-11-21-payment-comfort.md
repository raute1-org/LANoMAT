# Payment Comfort Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ticket types with prices, a per-event PayPal link (paypal.me with amount or a pool link), visible open amounts with a reference text for participants, per-round food payment with "included in ticket", and payment reminders. The orga still marks payments by hand.

**Architecture:** Registration (core) owns ticket types, the price snapshot, payment settings, the link/reference builders, the `PaymentDue` read model and the reminders. Catering (optional) reads the viewer's ticket key through `MyRegistration` and the link builder from Registration (optional → core is allowed). The Jetzt page's payment card is built in `App\Http\Controllers\NowController` (outside the module graph), not through a WebVoice card source, because core must not implement a contract of the optional WebVoice module (deviation from the spec's `PaymentCardSource` line, same user-visible result).

**Tech Stack:** Laravel 13, Filament v5, Inertia + Vue 3 + Tailwind v4 + shadcn-vue, Pest 4, PostgreSQL 16.

**Spec:** `docs/superpowers/specs/2026-11-21-payment-comfort-design.md`

## Global Constraints

- Money is integer cents everywhere (`*_cents`), currency EUR only; display via German formatting ("25,00 €").
- Payment links: `https` only, max 255 chars; `paypal.me`/`www.paypal.me` get `/<amount>EUR` appended (dot decimal, no trailing zeros beyond cents: `25EUR`, `9.5EUR`, `25.05EUR`), replacing an amount already in the path; any other https URL unchanged; null/empty link or amount 0 → no link.
- Reference text: `"<event name> · <display name> · <what>"`, max 140 chars, truncate the event name first; shown with a copy button and the hint "Bei PayPal als Nachricht einfügen".
- Price snapshot `event_registrations.ticket_price_cents` is never changed by edits of `ticket_types.price_cents`; only reactivation in `RegisterForEvent`, an orga ticket change in the admin, or the bulk action "Preis aus Ticket übernehmen" (unpaid only) re-take it.
- `paid_by` (max 120) and `ticket_price_cents` are not mass assignable; `paid_by` is orga-only (admin, CSV).
- Reminders: defaults 3 days after registration and 3 days before the LAN, per event editable (0 = off); each kind at most once per registration; both due → only `before_event`; sends only 09:00–21:00 Europe/Berlin; notification category `payment`, on by default; `database` always, `DiscordChannel` only when `Module::Discord` is enabled; the message links to the registration page, never the raw PayPal link.
- Policies: ticket types and payment settings mirror `EventPolicy::update` (`$user->isOrga()`).
- Payment link and reference only for the logged-in owner of the registration; never on guest pages.
- Catering stays optional: every Catering change behind its existing module gate; Registration must not reference Catering.
- No changes to the native client API shapes (`api/voice-client/...`); existing native tests stay green. `MyRegistration::summary` only gains fields.
- Every new web route goes into `tests/Feature/Security/RouteAuthorizationMatrixTest.php`.
- UI: Signalpult (`docs/design.md`), `components/Panel.vue` + rows, one amber primary per screen, four states, 375 px, visible focus; German copy in `lang/de/`; no `<style>` blocks.
- Public repo: tests and seeders use `https://paypal.me/example-orga` and `https://www.paypal.com/pool/example`; no real handles.
- Gates per task: `./vendor/bin/pint --test`, `php -d memory_limit=2G ./vendor/bin/phpstan analyse --memory-limit=2G`, focused pest (`DB_DATABASE=<task db> php -d memory_limit=1G ./vendor/bin/pest <paths>`); frontend tasks also `npm run types:check && npm run lint:check && npm run format:check && npm run build`.

## Review Focus

1. A paypal.me link the orga pastes with a trailing slash, an amount already in it, or a query string (`https://paypal.me/example-orga/10?locale.x=de_DE`) → exactly one amount segment, query dropped. Test in Task 2.
2. Odd cent values: 2500 → `25EUR`, 2550 → `25.5EUR`, 2505 → `25.05EUR`, 1 → `0.01EUR`. Test in Task 2.
3. The orga raises a ticket price after people registered → their owed amount and reminders still use the old price until the bulk action; the bulk action skips paid registrations. Tests in Tasks 1 and 3.
4. Reminder command at 08:59 and 21:00 Berlin (also on a DST-switch day) → nothing sent; at 09:00 the backlog goes out once. Test in Task 5.
5. A viewer with no or a cancelled registration opens the catering page or Jetzt → no payment block, `included` false, no error; an event without a payment link → "Bitte bei der Orga bezahlen." instead of a button. Tests in Tasks 4, 6 and 8.

---

### Task 1: Ticket types, price snapshot, migration

**Files:**
- Create: `database/migrations/2026_11_22_100000_create_ticket_types_table.php` (table + `event_registrations.ticket_price_cents`, `paid_by` + backfill)
- Create: `app/Modules/Registration/Models/TicketType.php`, `app/Modules/Registration/Support/EnsureDefaultTicketType.php`
- Modify: `app/Modules/Registration/Actions/RegisterForEvent.php`, `app/Modules/Registration/Models/EventRegistration.php`, `app/Modules/Registration/Support/MyRegistration.php`, `app/Modules/Registration/Support/TicketLabel.php` (fallback stays), `app/Providers/AppServiceProvider.php` (Event `created` hook)
- Test: `tests/Feature/Registration/TicketTypesTest.php`, update `RegisterForEventTest.php` where it relies on `settings['tickets']`

**Interfaces:**
- Produces: `TicketType` model (`event_id, key, name, description, price_cents, sort, active`), relation `Event` is not modified — query via `TicketType::query()->where('event_id', …)`; static `TicketType::activeFor(Event $event): Collection<int, TicketType>` (ordered by `sort`, `id`); `TicketType::findForEvent(Event $event, string $key): ?TicketType`.
- Produces: `RegisterForEvent::allowedTickets(Event $event): array<int, string>` now = active keys from `ticket_types` (signature unchanged).
- Produces: `EventRegistration` attributes `ticket_price_cents ?int`, `paid_by ?string` (guarded).
- Produces: `EnsureDefaultTicketType::for(Event $event): TicketType` (creates key `standard`, name "Standard", price 0, active, when the event has no ticket type).
- Produces: `MyRegistration::summary()` adds `ticketPriceCents: int`, `ticketName: string`, `paidBy` is NOT added (orga-only).

- [ ] **Step 1: Write failing tests** in `TicketTypesTest.php`:
  - `creating an event creates a free standard ticket type` → `TicketType::activeFor($event)` has one row, key `standard`, price 0.
  - `registering snapshots the ticket price` → type price 2500, register, `ticket_price_cents === 2500`; then update type price to 3000 → registration still 2500.
  - `an inactive ticket type is rejected` → `RegistrationException` (invalidTicketType).
  - `a ticket key of another event is rejected`.
  - `reactivating a cancelled registration re-snapshots and clears paid_by` → old snapshot 2500, paid_by "Max", cancel, price now 3000, register again → 3000, `paid_by` null, `paid_at` null.
  - `migration backfill maps legacy tickets` → run the migration's backfill callable on an event with `settings['tickets'] = ['early','standard']` and a registration `early` → two ticket types, registration snapshot 0.
  - `my registration summary carries price and ticket name`.
- [ ] **Step 2: Run** `DB_DATABASE=lanomat_test_p1 php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Registration/TicketTypesTest.php` → FAIL.
- [ ] **Step 3: Implement** the migration (unique `(event_id, key)`; backfill as a private static method of the migration class so the test can call it via the migration instance), the model, `EnsureDefaultTicketType`, the `Event::created` hook, `allowedTickets` from the table, the snapshot (`TicketType::findForEvent(...)->price_cents`) in both `RegisterForEvent` branches inside the existing transaction, `paid_by = null` on reactivation, the `MyRegistration` fields. Key generation: `Str::slug($name, '_')`, suffix `_2`, `_3` on collision, max 64.
- [ ] **Step 4: Run** the new test plus `tests/Feature/Registration tests/Feature/Mcp tests/Feature/WebVoice/NativeClient` → PASS.
- [ ] **Step 5: Commit** `feat(registration): ticket types with price snapshot`.

### Task 2: Payment link, reference, settings, `PaymentDue`

**Files:**
- Create: `app/Modules/Registration/Support/PaymentLink.php`, `app/Modules/Registration/Support/PaymentReference.php`, `app/Modules/Registration/Support/PaymentDue.php`, `app/Modules/Registration/Models/EventPaymentSettings.php`, `database/migrations/2026_11_22_100100_create_event_payment_settings_table.php`
- Test: `tests/Unit/Registration/PaymentLinkTest.php`, `tests/Feature/Registration/PaymentDueTest.php`

**Interfaces:**
- Consumes: Task 1 (`ticket_price_cents`, `TicketType`).
- Produces: `PaymentLink::for(?string $link, int $amountCents): ?string` (built URL or null); `PaymentLink::isValid(?string $link): bool` (https, ≤255); `PaymentLink::formatAmount(int $cents): string` (`25`, `25.5`, `25.05`).
- Produces: `PaymentReference::for(string $eventName, string $displayName, string $what): string`.
- Produces: `EventPaymentSettings::forEvent(Event $event): EventPaymentSettings` (lazy `firstOrNew` with defaults `reminder_after_days = 3`, `reminder_before_event_days = 3`; not saved by reading).
- Produces: `PaymentDue::forRegistration(EventRegistration $r): ?array{amountCents: int, paid: bool, url: ?string, linkAppendsAmount: bool, reference: string, note: ?string}` — null when snapshot is null or 0 or the registration is cancelled.

- [ ] **Step 1: Failing unit tests** (`PaymentLinkTest`): the four amounts from Review Focus 2; `https://paypal.me/example-orga` + 2500 → `https://paypal.me/example-orga/25EUR`; `https://www.paypal.me/example-orga/` → no double slash; `https://paypal.me/example-orga/10?locale.x=de_DE` + 2500 → `https://paypal.me/example-orga/25EUR`; `https://paypal.me/example-orga/10EUR` + 900 → `/9EUR`; `https://www.paypal.com/pool/example` + 2500 → unchanged; `http://paypal.me/x` → `isValid` false and `for` null; amount 0 → null; reference truncation: 200-char event name → result ≤ 140, display name and what intact.
- [ ] **Step 2: Failing feature test** (`PaymentDueTest`): open registration with link → url with amount, reference contains event, user display name and ticket name; paid → `paid: true`; no link → `url: null`; snapshot 0 → null; cancelled → null; pool link → `linkAppendsAmount: false`.
- [ ] **Step 3: Run** both → FAIL.
- [ ] **Step 4: Implement**; table `event_payment_settings` per spec (`payment_link`, `payment_note` ≤160, two smallint day counts with defaults 3, unique `event_id`). Display name: the same accessor the app uses for people lists (look up `User` display-name accessor used by `PeopleDirectory`).
- [ ] **Step 5: Run** → PASS. **Commit** `feat(registration): payment link, reference and due read model`.

### Task 3: Admin — tickets, payment settings, registrations

**Files:**
- Create: `app/Modules/Registration/Filament/RelationManagers/TicketTypesRelationManager.php`, `app/Modules/Registration/Policies/TicketTypePolicy.php`, `app/Modules/Registration/Policies/EventPaymentSettingsPolicy.php`, `app/Modules/Registration/Filament/Actions/EditPaymentSettingsAction.php` (header action on the event edit page opening a form modal)
- Modify: `app/Modules/Events/Filament/Resources/Events/EventResource.php` (`getRelations` adds `TicketTypesRelationManager`), the event edit page header actions (add `EditPaymentSettingsAction`), `app/Modules/Registration/Filament/RelationManagers/RegistrationsRelationManager.php`, `app/Providers/AppServiceProvider.php` (policy registration if not auto-discovered), `lang/de/registration.php`
- Test: `tests/Feature/Registration/TicketTypesAdminTest.php`, update `RegistrationRelationManagerTest.php`

**Interfaces:**
- Consumes: Tasks 1–2 (`TicketType`, `EventPaymentSettings`, `PaymentLink::isValid`, `FoodOrderForm::euroToCents` for the euro input — reuse, do not copy).
- Produces: registrations bulk action `rePrice` ("Preis aus Ticket übernehmen"), table action `toggle_paid` now with optional `paid_by`.

- [ ] **Step 1: Failing tests** (Livewire/Filament testing helpers as in `RegistrationRelationManagerTest`):
  - orga creates a ticket type "Flatrate" 35,00 € → key `flatrate`, 3500; editing the name keeps the key.
  - deleting a used ticket type is refused (action hidden/disabled); unused can be deleted.
  - participant (non-orga) cannot see or mount the relation manager / payment action (policy).
  - payment settings: `http://…` rejected, `https://paypal.me/example-orga` saved, day counts 0..60.
  - toggle paid with paid_by "Max" → `paid_at` set, `paid_by` "Max"; toggle back → both null.
  - bulk "Preis aus Ticket übernehmen" on three registrations (one paid) → two re-priced, paid one unchanged, notification text says "2 aktualisiert, 1 übersprungen (bezahlt)".
  - changing a registration's ticket type in the admin re-snapshots its price.
  - CSV export has columns `ticket_price_eur` and `paid_by`; filter "Zahlung offen" lists unpaid with amount > 0.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** the tests plus `tests/Feature/Security tests/Architecture` → PASS.
- [ ] **Step 5: Commit** `feat(registration): admin for ticket types, payment settings and paid-by`.

### Task 4: Participant registration page — ticket choice and payment block

**Files:**
- Modify: `app/Modules/Registration/Http/RegistrationController.php` (props), `resources/js/pages/Event/Register.vue`, `resources/js/types/*` (`RegistrationDetails`), `lang/de/registration.php`
- Create: `resources/js/components/registration/PaymentBlock.vue`, `resources/js/components/CopyText.vue` (copy button with aria-live "Kopiert", reused by Task 7)
- Test: update `tests/Feature/Registration/RegistrationPageTest.php`

**Interfaces:**
- Consumes: Tasks 1–2 (`TicketType::activeFor`, `PaymentDue::forRegistration`).
- Produces props: `tickets: list<{key: string, name: string, description: string|null, priceCents: int}>` (replaces `tickets: string[]` + `ticketLabels`), `registration.payment: {amountCents, paid, url, linkAppendsAmount, reference, note} | null`.
- Produces: `CopyText.vue` props `{ text: string; label: string }`.

- [ ] **Step 1: Failing tests:** page props list active types with price in sort order (inactive hidden); own registration with link → `payment.url` ends with `/25EUR`; paid → `payment.paid` true; no link → `url` null; another user's payment never in props; guest page has no `payment`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** backend props, then the UI (invoke the frontend-design skill first): radio list with name, description, price ("kostenlos" at 0); payment block in the "Meine Anmeldung" panel: amount + status, amber "Mit PayPal zahlen" (`target="_blank" rel="noopener"`) when `url`, `CopyText` for the reference with the hint, `note`, or "Bitte bei der Orga bezahlen." without url; no block at amount 0 or paid (paid shows "Bezahlt" only). The amber register button and the amber pay button never show at the same time.
- [ ] **Step 4: Run** tests + four frontend gates → PASS. **Step 5: Commit** `feat(registration): ticket prices and payment block for participants`.

### Task 5: Payment reminders

**Files:**
- Create: `app/Modules/Registration/Notifications/PaymentReminder.php`, `app/Modules/Registration/Console/SendPaymentRemindersCommand.php` (`registration:payment-reminders`), `app/Modules/Registration/Models/PaymentReminderSent.php`, `database/migrations/2026_11_22_100200_create_payment_reminders_sent_table.php`
- Modify: `routes/console.php` (hourly), `app/Modules/Notifications/Support/NotificationCategories.php` (`payment` in `ALL`, default on), `lang/de/registration.php`, `lang/de/notifications.php` (category label)
- Test: `tests/Feature/Registration/PaymentRemindersTest.php`

**Interfaces:**
- Consumes: Tasks 1–2 (`EventPaymentSettings::forEvent`, `ticket_price_cents`).
- Produces: `PaymentReminder(EventRegistration $registration, string $kind)` with `$category = 'payment'`, kinds `after_registration` | `before_event`; table `payment_reminders_sent(event_registration_id fk cascade, kind, sent_at, unique(event_registration_id, kind))`.

- [ ] **Step 1: Failing tests** (`Notification::fake()`, `travelTo` in Europe/Berlin):
  - unpaid confirmed registration 3 days old at 10:00 → one `after_registration`; second run → none.
  - event starts in 3 days, registration 4 days old and already reminded after registration → one `before_event`.
  - registration created 1 day before the before-window, both due at once → only `before_event`, `after_registration` recorded as sent.
  - paid / cancelled / snapshot 0 / finished or archived event → nothing.
  - 08:59 and 21:00 Berlin → nothing; 09:00 → sent (repeat on the DST-switch Sunday 2026-10-25).
  - `via()` is `['database']` with Discord off, `['database', DiscordChannel::class]` with Discord on; a user who disabled category `payment` gets no Discord DM (follow `CheckinOpened`'s preference handling).
  - message contains amount and ticket name and the registration page URL, not the PayPal link.
  - day counts 0 disable the respective kind.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** + `tests/Feature/Notifications` → PASS. **Step 5: Commit** `feat(registration): payment reminders`.

### Task 6: Catering — payment method, included tickets, cost split

**Files:**
- Create: `database/migrations/2026_11_22_100300_add_payment_to_food_orders.php` (`food_orders.payment_method` string default `cash`, `payment_link` nullable, `included_ticket_types` jsonb default `[]`; `food_order_items.included` bool default false), `app/Modules/Catering/Enums/FoodPaymentMethod.php` (`Cash = 'cash'`, `Paypal = 'paypal'`), `app/Modules/Catering/Support/FoodIncluded.php`, `app/Modules/Catering/Actions/RecomputeIncludedItems.php`, `app/Modules/Catering/Actions/MarkUserItemsPaid.php`
- Modify: `FoodOrder` model (casts), `FoodOrderItem` (cast, `included` guarded), `PlaceFoodOrderItem` (set `included`), `CostSplit`, `ExportFoodOrderItemsCsv`, `FoodOrderForm` (Bezahlart, Zahlungslink, "Im Ticket enthalten für" multi-select of the event's ticket types by key, with "alle"/"keine" shortcuts), `EditFoodOrder` (call `RecomputeIncludedItems` after save when not closed), `ItemsRelationManager` ("Alles von <Person> bezahlt"), `lang/de/catering.php`
- Test: `tests/Feature/Catering/FoodPaymentTest.php`, update `CostSplitTest`/export tests

**Interfaces:**
- Consumes: Task 1 (`MyRegistration::find()->ticket_type`, `TicketType::activeFor`), Task 2 (`PaymentLink::isValid`).
- Produces: `FoodIncluded::for(FoodOrder $order, ?string $ticketKey): bool`; `CostSplit::for()` perUser entries `{name, totalCents, includedCents, dueCents, paidCents}` (due = non-included, unpaid); `RecomputeIncludedItems::handle(FoodOrder $order): int` (unpaid items only, skipped when closed); `MarkUserItemsPaid::handle(FoodOrder $order, int $userId): int`.
- Design decision: `included_ticket_types` stores ticket **keys** (stable, compared directly with `event_registrations.ticket_type`).

- [ ] **Step 1: Failing tests:** item placed by a `flatrate` user in a round including `flatrate` → `included` true; `basic` user → false; orga edits include-list on an open round → unpaid items recomputed, paid items untouched; closed round → no recompute; `CostSplit` sums per person (total, included, due, paid); export has `included` column; "Alles bezahlt" marks only that user's due items; `payment_link` `http://…` rejected; Catering form ticket options are the event's active ticket types; viewer without registration → `FoodIncluded` false.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** + `tests/Feature/Catering tests/Feature/Now` → PASS.
- [ ] **Step 5: Commit** `feat(catering): payment method and included-in-ticket per round`.

### Task 7: Catering UI — "Meine Bestellung" payment

**Files:**
- Modify: `app/Modules/Catering/Http/CateringController.php` (per round `myPayment`), `app/Modules/Catering/Support/FoodNow.php` (`mine` gains `payment`), `resources/js/pages/Catering/Show.vue`, `resources/js/components/now/FoodPanel.vue`, `resources/js/types/now.ts` (`FoodNowDto.open.mine.payment`), catering types, `lang/de/catering.php`, `lang/de/now.php`
- Test: update `tests/Feature/Catering/CateringPageTest.php` (or the existing page test file), `tests/Feature/Now/FoodNowTest.php`

**Interfaces:**
- Consumes: Task 6 (`FoodIncluded`, `FoodPaymentMethod`, `CostSplit` due), Task 2 (`PaymentLink::for`, `PaymentReference::for`), Task 4 (`CopyText.vue`).
- Produces: `myPayment: {method: 'cash'|'paypal'|'included', dueCents: int, url: string|null, reference: string|null} | null` per round (null when the viewer has no items).

- [ ] **Step 1: Failing tests:** included viewer → `method: 'included'`, `dueCents` 0; cash round → `method: 'cash'`, due = own unpaid sum; paypal round with own link → url with due amount; paypal round without own link → event link; paypal without any link → url null; another user's items never counted.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (frontend-design skill first): items show "inklusive" instead of a price when included; cash → "Bar bei der Orga: 9,50 €"; paypal → amount, pay button + `CopyText`; while the round is open the order button stays the amber primary and the pay button is outline; after close the pay button is amber. Jetzt `FoodPanel` shows the same one-line state for the open round.
- [ ] **Step 4: Run** tests + four frontend gates → PASS. **Step 5: Commit** `feat(catering): show what I owe for food and how to pay`.

### Task 8: Jetzt — payment card

**Files:**
- Modify: `app/Http/Controllers/NowController.php` (prepend a `payment` card after a ready match, before other cards, in states `upcoming` and `live`), `resources/js/types/now.ts` (`NowCardDto.kind` adds `'payment'`), `resources/js/components/now/ForYouPanel.vue`, `lang/de/now.php`
- Test: `tests/Feature/Now/NowPaymentCardTest.php`

**Interfaces:**
- Consumes: Task 2 (`PaymentDue::forRegistration`), `MyRegistration::find`.
- Produces card: `{kind: 'payment', id: 'payment:<registrationId>', state: 'open', title: <"Ticket noch nicht bezahlt">, body: <"25,00 € · Flatrate">, at: null, data: {amountCents, actionLabel: "Bezahlen"}, href: route of the registration page}`.

- [ ] **Step 1: Failing tests:** unpaid with amount → card present in upcoming and live, href = registration page; ordered after a ready match card and before vote/order cards; paid / amount 0 / cancelled / no registration → absent; state `none` → absent; card never contains the PayPal URL.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement** (ForYouPanel: warn dot, amount in `font-mono`, outline button; the ready-match "Verbinden" remains the only amber).
- [ ] **Step 4: Run** + `tests/Feature/Now` + four frontend gates → PASS. **Step 5: Commit** `feat(now): payment card for an unpaid ticket`.

### Task 9: Docs

**Files:**
- Modify: `README.md` (`## Project state`: payment comfort), `CHANGELOG.md` (one line with PR number at PR time), `docs/superpowers/plans/2026-07-14-lanomat-v2-roadmap.md` (backlog #5 done, link spec), `docs/architecture.md` (Registration row: ticket types, payment link, reminders), `docs/design.md` only if a new pattern (CopyText) needs a line
- Create: `docs/anleitung-orga-bezahlung.md` (German orga guide: ticket types, link paypal.me vs pool, bulk re-price, reminders, food rounds, "Freunde und Familie" note neutral)

- [ ] **Step 1:** Write the docs; placeholders only (`paypal.me/example-orga`).
- [ ] **Step 2:** `bash scripts/ops/check-public-safe.sh` → "public-safe check passed".
- [ ] **Step 3: Commit** `docs: payment comfort`.

## Execution order

Task 1 → Task 2 → in parallel worktrees {Task 3, Task 4, Task 5, Task 6, Task 8} → Task 7 (after 6 and 4) → Task 9. Each parallel worktree uses its own test DB (`lanomat_test_p<N>`).
