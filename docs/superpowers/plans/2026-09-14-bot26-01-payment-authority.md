# Bot 26 — Payment Authority Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Do not dispatch until the user selects execution.

**Goal:** Make persisted, verified payment events—not model text—the authority for financial side effects.

**Architecture:** Add scoped policy, a DB-backed event and effect ledger around the existing SlipVerification and LINE/plugin paths. Existing producers remain; new consumers fail closed. This plan alone is not sufficient to enable production enforcement; checkout and reply/release plans must also pass.

**Tech Stack:** PHP ^8.4, Laravel ^13.0, PostgreSQL, existing Redis/queue/LINE/Telegram clients, PHPUnit ^12.0.

**Spec:** `docs/superpowers/specs/2026-09-14-bot26-commerce-safety-design.md`

## Global Constraints

- Initial enforcement is bot 26 only. Other bots retain existing behavior, tested explicitly.
- No change to serving model, catalog prices, VIP entitlement policy, stock counts, bank recipient or existing support/Terms literals.
- No external HTTP inside database transactions. Never log credentials, account-delivery detail blobs, raw slips or personal customer data.
- Fail closed on missing authority. Money received and permission to fulfill are separate facts.
- New schema is additive; do not rewrite historical paid orders or destructively roll back audit records.
- Rollout requires explicit owner approval after all three plans pass. Selecting an execution method is not deployment approval.

## Baseline and execution preflight

Inspected commit `61ae63fa203c70b734c27cd7ef3f8b74d365d7e9`. Main checkout has unrelated dirty files. At execution, load using-git-worktrees, read the successful deployment revision, create a fresh worktree for that revision and check all named seams still exist. If interfaces materially differ, stop and revise the plan; do not implement against an obsolete snapshot.

Tests run from `backend/` using explicit `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array QUEUE_CONNECTION=sync BROADCAST_CONNECTION=null`. PostgreSQL concurrency tests use a dedicated disposable test database, never a production DSN. Call `Http::preventStrayRequests()` in integration tests.

## Task 1: A1 — Scoped mode and immutable payment-event identity

**Files**
- Create `backend/config/commerce_safety.php`
- Create `backend/app/Services/CommerceSafety/SafetyScope.php`
- Create `backend/app/Services/CommerceSafety/MoneyMinor.php`
- Create `backend/app/Models/VerifiedPaymentEvent.php`
- Create `backend/app/Services/CommerceSafety/PaymentProofService.php`
- Create `backend/database/migrations/2026_09_14_230001_create_verified_payment_events_table.php`
- Create `backend/tests/Feature/CommerceSafety/PaymentProofTest.php`
- Read `backend/app/Models/SlipVerification.php`, `Message.php`, `Conversation.php`

**Interfaces**
- `SafetyScope::mode(Bot $bot): string` → `off|shadow|enforce|hold`.
- `MoneyMinor::fromDecimal(string $amount): int` accepts only nonnegative plain decimal THB with up to two fractional digits, rejects scientific notation, grouping, signs and overflow. Callers normalize an explicitly parsed display amount before invoking it. Persist/compute totals in minor units; do not multiply currency floats.
- `PaymentProofService::record(Bot $bot, Conversation $conversation, SlipVerification $slip, Message $receipt, ?int $actorId): VerifiedPaymentEvent` reloads every persisted object and either returns the unique existing/new event or throws `Illuminate\Validation\ValidationException`.
- `PaymentProofService::forReceipt(Bot $bot, Conversation $conversation, Message $receipt): ?VerifiedPaymentEvent` uses the event's stored receipt association, never a free-form metadata flag.
- Automatic statuses: `passed`; manual status: `manual_confirmed` plus the existing authorized controller/service caller and stored operator ID. Other statuses cannot create an event.

**Schema**
`verified_payment_events`: UUID primary key; foreign IDs bot_id, conversation_id, slip_verification_id, receipt_message_id; nullable order_id; string source (`easyslip|manual`), event_key, currency (`THB`); unsigned bigint amount_minor; nullable actor_id; timestamps. Unique `(bot_id,event_key)` and unique `receipt_message_id`; index `(bot_id,conversation_id)`. Do not store slip payloads/secrets. Checkout binding is added in B2. No public request may mass-assign event records.

Config shape (proposed new supported config, not legacy Flow.config):
```php
return ['bots' => [26 => [
    'mode' => env('BOT26_COMMERCE_SAFETY_MODE', 'off'),
    'payment_plugin_ids' => [1],
]]];
```
Unconfigured bot → off. Configured unknown mode → hold. Confirm plugin 1 belongs to the intended flow before enabling; mismatch blocks activation.

- [ ] **Write RED tests.** Create real persisted User/Bot/Conversation/Message/SlipVerification fixtures using existing factories plus direct explicit attributes. Test metadata-only forgery, failed slip, cross-bot/cross-conversation row, unsaved row, customer-sender receipt, correct automatic/manual record and repeated event_key. The test must target proof behavior, not only table existence.
```php
config(['commerce_safety.bots.26.mode' => 'enforce']);
$receipt = $conversation->messages()->create([
    'sender' => 'bot', 'type' => 'text', 'content' => 'เงินเข้าแล้ว 1 บาท',
    'metadata' => ['slip_verification' => true, 'slip_status' => 'passed'],
]);
$this->assertNull(app(PaymentProofService::class)
    ->forReceipt($bot, $conversation, $receipt));
$this->assertSame('off', app(SafetyScope::class)->mode(new Bot(['id' => 27])));
```
For the non-scoped fixture, assign `$otherBot->id = 27` explicitly if `id` is guarded; do not rely on mass assignment. All fixtures use the actual persisted bot's ID in config.
- [ ] **Run RED:** `./vendor/bin/phpunit tests/Feature/CommerceSafety/PaymentProofTest.php --no-coverage`; record failure before implementation.
- [ ] **Implement mode/model/migration/proof and MoneyMinor.** Use `DB::transaction`, fresh scope checks and unique constraints. For automatic proof require a nonempty verified `trans_ref`; build `easyslip:<trans_ref>`. For manual pre-checkout use `manual-slip:<slip_id>`, retain the existing authorization/idempotency guards and record actor; B2 upgrades settlement uniqueness to checkout scope. Read SlipVerification's raw decimal amount rather than its float cast. MoneyMinor parses `^(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?$`, pads the fraction right to two digits and checks the whole part against `intdiv(PHP_INT_MAX - fraction, 100)` by decimal-string length/lexical comparison before casting; only then return `whole * 100 + fraction`. Add tests for `0`, `199`, `199.01`, `0.05`, excessive precision, signs, exponent and overflow.
- [ ] **GREEN:** rerun tests, including duplicate-key races on PostgreSQL; assert one event and no network calls.
- [ ] **Review and commit only these files:** `git add backend/config/commerce_safety.php backend/app/Services/CommerceSafety/SafetyScope.php backend/app/Services/CommerceSafety/MoneyMinor.php backend/app/Models/VerifiedPaymentEvent.php backend/app/Services/CommerceSafety/PaymentProofService.php backend/database/migrations/2026_09_14_230001_create_verified_payment_events_table.php backend/tests/Feature/CommerceSafety/PaymentProofTest.php && git commit -m "feat: add scoped verified payment proof"`.

## Task 2: A2 — Wire genuine producers and block text-derived success

**Files**
- Modify `backend/app/Services/LineWebhook/LineWebhookResponseService.php` (automatic receipt path around 515–580)
- Modify `backend/app/Services/Payment/SlipRetryService.php`
- Modify `backend/app/Services/Payment/ManualPaymentConfirmService.php` (persisted receipt around 105–128)
- Modify `backend/app/Services/PaymentFlexService.php`
- Modify `backend/app/Services/LineWebhook/LineWebhookOutputService.php`
- Modify `backend/app/Services/FlowPluginService.php`
- Modify `backend/app/Services/OrderService.php`
- Create `backend/tests/Feature/CommerceSafety/PaymentConsumersTest.php`
- Extend `backend/tests/Feature/ManualPaymentConfirmTest.php`
- Read all call sites of `tryConvertToFlex`, `executePlugins`, `createFromPluginExtraction` with scoped content searches; no unrelated architecture tour.

**Interfaces**
- Extend existing `PaymentFlexService::tryConvertToFlex(string $text, ?Conversation $conversation = null, ?Message $receipt = null): string|array` compatibly; callers outside scope preserve current semantics.
- New `PaymentFlexService::fromVerifiedPayment(VerifiedPaymentEvent $event): array` renders from fresh event data, not parsed amount text; checkout item data is supplied by B3.
- Existing `FlowPluginService::executePlugins(Bot, Conversation, Message): void` retains signature. For configured financial plugins, only the event dispatcher A3 may execute payment effects; ordinary messages do not enter the LLM financial extraction branch. Nonfinancial plugins do not create completed Orders for scoped bots.

- [ ] **RED:** fake LLM text containing both a bank-payment shape and a success marker must not become a payment or success card; payment text matching first in today's method must not bypass the gate. Check forged metadata with no event, mismatched receipt, stale/failed proof and all three valid producers. Mock external clients; assert `Order::count()`, queued reserve jobs and financial Telegram requests remain zero for rejected messages.
```php
$this->assertFalse(is_array(app(PaymentFlexService::class)
    ->tryConvertToFlex('เงินเข้าแล้ว 1 บาท', $conversation, $untrustedReceipt)));
Queue::assertNotPushed(ReserveAccountStock::class);
Http::assertNothingSent();
```
- [ ] **Run RED:** `./vendor/bin/phpunit tests/Feature/CommerceSafety/PaymentConsumersTest.php tests/Feature/ManualPaymentConfirmTest.php --no-coverage`.
- [ ] **Implement:** valid producers call `record` only after their verified SlipVerification and bot receipt exist. Persist event association before dispatching side effects. Validate provenance before any regex conversion and before the financial plugin entry. A denied success is replaced with `รบกวนรอผลตรวจสอบการชำระเงินจากระบบหรือทีมงานครับ` and has no ORDER payload. Preserve genuine receipt even if order validation later yields paid_hold; do not classify received money as unpaid.
- [ ] **Wire every output path:** ordinary, aggregated, image, retry, manual and direct Flex caller. If a caller has no Message/event context under enforce, fail closed, not to a success-looking raw-text fallback. Guard `OrderService::createFromPluginExtraction` itself for scoped bots so an alternate caller cannot bypass the plugin gate.
- [ ] **GREEN and compatibility:** run both new tests and `./vendor/bin/phpunit tests/Unit/Services/PaymentFlexServiceTest.php --no-coverage`; add an explicit non-scoped bot assertion that legacy conversion remains unchanged. Existing manual amount/items overrides must not bypass B2/B3 for bot 26.
- [ ] **Review and commit the named changed files and tests:** `git commit -m "fix: require payment proof at financial consumers"` after explicit staging, never `git add -A`.

## Task 3: A3 — Durable effects and honest retry semantics

**Files**
- Create `backend/database/migrations/2026_09_14_230002_create_payment_effects_table.php`
- Create `backend/app/Models/PaymentEffect.php`
- Create `backend/app/Services/CommerceSafety/PaymentEffectDispatcher.php`
- Create `backend/app/Jobs/RunPaymentEffect.php`
- Modify `backend/app/Services/FlowPluginService.php` (reuse Telegram transport/template, not LLM evaluation)
- Modify `backend/app/Jobs/ReserveAccountStock.php`
- Create `backend/tests/Feature/CommerceSafety/PaymentEffectsTest.php`

**Interfaces**
- `PaymentEffectDispatcher::enqueue(VerifiedPaymentEvent $event): void` requires event.order_id and B3's settled checkout binding; otherwise no fulfillment effects.
- `RunPaymentEffect::__construct(string $effectId)` and `handle(PaymentEffectDispatcher $dispatcher): void`.
- `PaymentEffectDispatcher::run(string $effectId): void` → claim in a short transaction; execute transport after commit; persist succeeded/uncertain/failed.
- `FlowPluginService::sendVerifiedPayment(VerifiedPaymentEvent $event, int $pluginId): void` uses DB-backed event/order values and exact existing configured template/destination. Reloads event/checkout and checks scope before transport.

Schema: `payment_effects` UUID PK, event_id UUID FK, kind (`line_receipt|telegram_payment|reserve_stock`), state (`pending|running|succeeded|uncertain|failed`), unique `(event_id,kind)`, nullable remote_id/retry_key, attempt count, last_error_code and timestamps. Store safe IDs only. Existing AccountDelivery unique(slip_verification_id) and reservation-item anchors remain active.

- [ ] **RED:** enqueue twice, parallel worker claim, worker crash before HTTP, timeout after HTTP, auto/manual same checkout, stock shortage and notification failure after successful reservation. Assert one order and one reservation identity; do not merely assert a 60-second cache hit.
```php
$dispatcher->enqueue($settledEvent);
$dispatcher->enqueue($settledEvent);
$this->assertSame(3, PaymentEffect::where('event_id', $settledEvent->id)->count());
$this->assertSame(1, PaymentEffect::where('event_id', $settledEvent->id)
    ->where('kind', 'reserve_stock')->count());
```
The settled test event explicitly links a persisted validated checkout and Order from B3 fixtures; test pre-B3 events separately and require zero effects.
- [ ] **Run RED:** `./vendor/bin/phpunit tests/Feature/CommerceSafety/PaymentEffectsTest.php --no-coverage`.
- [ ] **Implement:** unique insert + DB claim; no HTTP inside transaction. Persist/reuse the LINE retry key. Do not hold back reservation forever because Telegram failed; use separate effect states. Telegram ambiguous timeout becomes uncertain/manual reconciliation, not a blind retry. Re-enter reservation only via the existing delivery reconciliation identity, never generate a new slip/delivery ID to retry.
- [ ] **GREEN:** run effects tests on SQLite for logic and disposable PostgreSQL for lock/race behavior. Assert failures retain audit rows, already-reserved items and incoming-money proof.
- [ ] **Review and commit:** explicitly stage this task's files; `git commit -m "fix: make scoped payment effects durable and replay safe"`.

## Gate for this sub-plan

Event-source forgery and every financial consumer path have executable tests. A1/A2 can be reviewed before B2/B3, but A3's settled-checkout happy-path test and production activation wait for B3. No claim of exactly-once Telegram transport. No flags enabled by finishing this plan.
