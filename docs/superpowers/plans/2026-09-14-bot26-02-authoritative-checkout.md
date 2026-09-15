# Bot 26 — Authoritative Checkout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Execution requires the user's method selection.

**Goal:** Bind actual customer consent, canonical amounts and current sale eligibility to the same persisted checkout revision before payment and delivery.

**Architecture:** Keep existing parsers as untrusted proposal adapters; add a focused validator and checkout record. Existing Orders are still created only after payment. A server renderer produces confirmation and payment instructions; the model cannot set paid/confirmed flags.

**Tech Stack:** PHP ^8.4, Laravel ^13.0, PostgreSQL, existing stock-pool integration, PHPUnit ^12.0.

**Spec:** `docs/superpowers/specs/2026-09-14-bot26-commerce-safety-design.md`

## Global Constraints

- Initial enforcement is bot 26 only. Other bots retain existing behavior, tested explicitly.
- No change to serving model, catalog prices, VIP entitlement policy, stock counts, bank recipient or existing support/Terms literals.
- Fail closed on missing authority. Money received and permission to fulfill are separate facts.
- No external HTTP inside database transactions. Never log credentials, account-delivery detail blobs, raw slips or personal customer data.
- New schema is additive; do not rewrite historical paid orders or destructively roll back audit records.
- Rollout requires explicit owner approval after all three plans pass. Selecting an execution method is not deployment approval.

## Shared contracts

A1 provides SafetyScope, MoneyMinor and VerifiedPaymentEvent. All new services are in `App\Services\CommerceSafety`. These definitions are proposed code to create, not existing application APIs.

- `ProposedLine`: `array{name:string,qty:int,price_minor:int}`.
- `CanonicalLine`: `array{product_id:int,name:string,method:?string,qty:int,unit_minor:int,line_minor:int}`. Methods for Nolimit are exactly `ผูกบัตร|เติมเงิน`; Page/G3D method is null.
- `CartValidation`: immutable DTO with `bool $valid`, `array $items` of CanonicalLine, `int $totalMinor`, `string $fingerprint`, `array $errors`. Error codes: `UNKNOWN_PRODUCT, AMBIGUOUS_PRODUCT, INVALID_QTY, AUTOMATION_LIMIT, PRICE_MISMATCH, TOTAL_MISMATCH, OUT_OF_STOCK, STOCK_UNKNOWN, INSUFFICIENT_STOCK, STALE_ENTITLEMENT`.
- `CheckoutOutcome`: immutable DTO with `string $action` (`clarify|confirm|support_delay|terms|payment|ack|manual_hold`), `?CheckoutSession $checkout`, `?string $customerText`; it is not a payment event.

## Task 1: B1 — Canonical validation for every product and strict proposal parsing

**Files**
- Create `backend/app/Services/CommerceSafety/CartValidation.php`
- Create `backend/app/Services/CommerceSafety/CanonicalCartValidator.php`
- Create `backend/app/Services/CommerceSafety/CartProposalAdapter.php`
- Modify `backend/app/Services/VipPricingService.php` only for a minor-unit adapter, not entitlement policy
- Modify `backend/app/Services/AIService.php` only in scoped branch after model/cache return
- Create `backend/tests/Feature/CommerceSafety/CanonicalCartTest.php`
- Create `backend/tests/Unit/Services/CommerceSafety/CartProposalAdapterTest.php`
- Read `ProductStock.php`, `OrderPayloadExtractor.php`, `PaymentMessageDetector.php`, `VipPriceGuardService.php`, `StockGuardService.php`.

**Interfaces**
- `VipPricingService::effectivePriceMinor(ProductStock $product, bool $isVip): ?int` adapts the existing effectivePrice result to MoneyMinor after fixed two-decimal formatting; reject overflow/non-finite data, preserve null. It must select exactly the same policy price as effectivePrice, covered by parity tests.
- `CanonicalCartValidator::validate(Bot $bot, Conversation $conversation, array $proposedLines, int $claimedTotalMinor): CartValidation` reads uncached current products and authoritative VIP eligibility, aggregates duplicate SKU+method quantities, then validates aggregate stock bounds.
- `CartProposalAdapter::fromText(string $text): ?array` returns `array{lines:list<ProposedLine>,total_minor:int}` only when all line prices/quantities/methods and total are explicit and syntactically valid. It may use existing parseConfirmData/parsePaymentData for locating candidate lines but must read original numbers, not coerced extractor quantities. Ambiguous/missing fields → null, no default qty=1 or inferred free Page.
- `CartProposalAdapter::fromOrderJson(string $json): ?array` validates original JSON types before normalization; reject negative, zero, bool, float/string qty and unknown fields carrying state/provenance. These are proposal adapters, never authority.

- [ ] **Write RED matrix:** normal and VIP Personal/BM; normal Page 199/G3D 50; no VIP from customer words; wrong price on non-VIP-priced products; unknown/ambiguous name; missing method; zero/negative/fractional/string qty; arithmetic mismatch; duplicate lines jointly exceeding stock; manual_off=true with available_count=48; in_stock=false with positive count; missing/null pool availability; revoked VIP; unchanged unrelated product.
```php
$result = app(CanonicalCartValidator::class)->validate($bot, $conversation, [
    ['name' => 'Page', 'qty' => 1, 'price_minor' => 100],
], 100);
$this->assertFalse($result->valid);
$this->assertContains('PRICE_MISMATCH', $result->errors);
$this->assertNull(app(CartProposalAdapter::class)->fromOrderJson(
    '{"items":[{"name":"Page","qty":-2,"price":199}],"total":199}'
));
```
Create ProductStock fixtures with explicit price/vip_price/in_stock/manual_off/available_count. Do not use live inventory. Use persisted bot/conversation fixtures and trusted vip_manual/vip_auto note shapes from current VipPricingService tests.
- [ ] **Run RED:** `./vendor/bin/phpunit tests/Feature/CommerceSafety/CanonicalCartTest.php tests/Unit/Services/CommerceSafety/CartProposalAdapterTest.php --no-coverage`.
- [ ] **Implement allowlist mapping:** exact canonical names and stored aliases, longest-match cannot turn G3D into a Nolimit account. Preserve product IDs and required method. G3D/Page have their own fresh canonical price checks. A mismatched model price causes a server-corrected proposal requiring fresh confirmation, not a silently accepted order. Null price/stock is unknown, not zero/free/unlimited; null count is allowed only for products whose delivery method is not pool stock and whose explicit in_stock flag is true.
- [ ] **Implement numeric checks:** accept only int qty>0; aggregate same product across methods for shared stock capacity; use checked integer addition/multiplication with `intdiv(PHP_INT_MAX, qty)` bounds. Quantities above `delivery.max_qty` are an automation limit, not proof the shop refuses large orders: offer manual handling without truncation or automatic payment/fulfillment. Fingerprint canonical lines, total and entitlement using a stable ordered JSON encoding plus SHA256.
- [ ] **GREEN and parity:** new tests, existing VIP/stock tests and adversarial payload tests. Assert the old non-scoped branch remains byte-compatible. Structured order payload cannot escape a failed validation in AIService.
- [ ] **Review and commit only named files:** `git commit -m "fix: validate canonical checkout prices quantities and stock"` after explicit staging.

## Task 2: B2 — Persisted revision, presented challenge and actual consent

**Files**
- Create `backend/database/migrations/2026_09_14_230003_create_checkout_sessions_table.php`
- Create `backend/app/Models/CheckoutSession.php`
- Create `backend/app/Services/CommerceSafety/CheckoutOutcome.php`
- Create `backend/app/Services/CommerceSafety/CheckoutConsentPolicy.php`
- Create `backend/app/Services/CommerceSafety/CheckoutAuthority.php`
- Create `backend/app/Services/CommerceSafety/CheckoutRenderer.php`
- Modify `backend/app/Services/LineWebhook/LineWebhookResponseService.php`
- Modify `backend/app/Services/LineWebhook/LineWebhookOutputService.php`
- Modify `backend/app/Jobs/ProcessAggregatedMessages.php`
- Create `backend/tests/Feature/CommerceSafety/CheckoutConsentTest.php`

**Schema**
`checkout_sessions`: UUID PK; bot_id/conversation_id FKs; revision unsigned int; state (`draft|awaiting_confirm|awaiting_support|awaiting_terms|payable|paid|paid_hold|cancelled`); items JSON; total_minor unsigned bigint; currency THB; fingerprint string; requirements JSON; accepted JSON mapping stages to actual user message IDs; nullable challenge_message_id and presented_at; nullable unique settled_event_id referencing verified_payment_events; timestamps. Index `(bot_id,conversation_id,state)`. Add nullable checkout_id FK to verified_payment_events. One open checkout is enforced by locking the conversation before checking/creating, not by an unsafe outside-transaction exists() query. Multiple earlier paid/verification-hold checkouts may remain separately traceable.

No cart lives only in LLM history. Do not expose mass assignment of state, accepts, settled_event_id or fingerprint via user metadata.

**Interfaces**
- `CheckoutConsentPolicy::requirements(Bot $bot, Conversation $conversation, array $canonicalItems): array` returns booleans `topup_ack,support_delay,terms`; derive exemptions from authoritative VIP service or actual completed purchases scoped to this bot. Record source IDs, not free-text Memory decisions. New/switched topup acknowledgement remains before confirmation.
- `CheckoutAuthority::propose(Bot $bot, Conversation $conversation, CartValidation $cart): CheckoutOutcome` increments revision if changed, invalidates cart confirmation and returns the next required action. Drafts with slips under review are not silently merged into new purchases.
- `CheckoutAuthority::presented(CheckoutSession $checkout, int $revision, Message $challenge): void` is called only after successful outbound presentation; save the exact revision/challenge and timestamp. Uncertain send → no consent advance.
- `CheckoutAuthority::accept(Bot $bot, Conversation $conversation, Message $customerMessage): CheckoutOutcome` loads/locks the current checkout and presented challenge; accepts one stage only.
- `CheckoutRenderer::render(CheckoutSession $checkout, string $action): string` renders approved full-name cart, warning, Terms or payment template from persisted data. Payment ORDER JSON is server-owned and emitted only at payable.

- [ ] **RED tests:** model says confirmed but no customer message; user reply before presentation; cross-conversation/cross-bot message; old revision; duplicate same message; bare "โอเค" after upsell; Page retained when changing "เฟส"; additive vs replacement proposals; conditional support acceptance; a single reply cannot accept unseen Terms; new quantity after payment instructions; memory outside context window; valid VIP skips only policy-authorized stages; actual customer old-purchase exemption but no discount inferred from it.
```php
$outcome = $authority->accept($bot, $conversation, $replyBeforePresented);
$this->assertNotSame('payment', $outcome->action);
$this->assertNull($checkout->fresh()->settled_event_id);
$revisionBefore = $checkout->revision;
$changed = $authority->propose($bot, $conversation, $validatedChangedCart);
$this->assertGreaterThan($revisionBefore, $changed->checkout->revision);
$this->assertSame('confirm', $changed->action);
```
- [ ] **Run RED:** `./vendor/bin/phpunit tests/Feature/CommerceSafety/CheckoutConsentTest.php --no-coverage`.
- [ ] **Implement actual-message state transitions:** whole-response allowlists after trimming whitespace/quotes/punctuation and polite suffix, not substring search. CONFIRM requires explicit ยืนยัน/confirm; SUPPORT allows ตกลง/ยอมรับ/รับทราบ/รับได้/โอเคครับ/โอเค/accept/agree with no additional condition; TERMS only ยอมรับ/accept/agree. A reply such as "ตกลง แต่ต้องวันนี้" is not unconditional. Source text, sequence and current stage—not model narration—control it.
- [ ] **Integrate before LLM:** scoped current-stage accept/cancel handlers consume actual persisted incoming Message; non-state product questions remain ordinary conversation and do not reset checkout. Persist challenge as pending before sending and mark presented only on delivery success. Aggregate multiple user messages without losing their identity/order; record exact constituent message IDs and reject replay, not merely an aggregated text string.
- [ ] **Re-render and guard:** model confirmation/payment text is only a proposal. Server replacement must visibly show the exact canonical revision the user is being asked to confirm. If strict proposal parsing fails, ask for missing product/qty/method and send no payment instructions. No new ORDER schema keys or hidden [[CART]] marker are introduced.
- [ ] **GREEN:** rerun consent tests and existing confirmation/card tests; exact Terms URL, bank literals, Page default and VIP normal prices stay unchanged. Test no success state is inferred from a customer saying "โอนแล้ว".
- [ ] **Review and commit explicitly:** `git commit -m "feat: bind checkout consent to persisted cart revisions"`.

## Task 3: B3 — Bind payment settlement, Order and reservation to checkout

**Files**
- Modify `backend/app/Services/CommerceSafety/CheckoutAuthority.php`
- Modify `backend/app/Services/CommerceSafety/PaymentProofService.php`
- Modify `backend/app/Services/CommerceSafety/PaymentEffectDispatcher.php`
- Modify `backend/app/Services/Payment/SlipVerificationService.php`
- Modify `backend/app/Services/Payment/ManualPaymentConfirmService.php`
- Modify `backend/app/Services/Payment/SlipRetryService.php`
- Modify `backend/app/Services/OrderService.php`
- Modify `backend/app/Jobs/ReserveAccountStock.php`
- Modify `backend/app/Services/Delivery/AccountDeliveryService.php`
- Modify `backend/app/Http/Controllers/Api/ManualPaymentConfirmController.php`
- Modify `backend/app/Http/Controllers/Webhook/TelegramAlertCallbackController.php`
- Create `backend/tests/Feature/CommerceSafety/CheckoutSettlementTest.php`

**Interfaces**
- `CheckoutAuthority::settle(CheckoutSession $checkout, VerifiedPaymentEvent $event): CheckoutOutcome` locks checkout/event and either links one valid paid order or persists paid_hold.
- `OrderService::createFromCheckout(CheckoutSession $checkout, VerifiedPaymentEvent $event): Order` creates canonical line items without plugin extraction, in the settlement transaction; a checkout/event can link one Order only.
- `CheckoutAuthority::authorizeReservation(Bot $bot, Conversation $conversation, int $slipId): ?CheckoutSession` reloads event/checkout/current sale eligibility. Null blocks auto-reservation and records/alerts paid_hold when money was received.
- `PaymentProofService::bindCheckout(VerifiedPaymentEvent $event, CheckoutSession $checkout): void` scopes and locks rows. A provider transaction cannot settle a second checkout; both auto and manual paths racing for the same checkout serialize to one settlement. Manual bot-26 confirmation must select the persisted checkout/revision; its existing amount/items overrides cannot invent a cart or bypass canonical checks.

- [ ] **RED scenarios:** slipped amount matches text but not checkout; Page underpriced; stock closed after quote; entitlement revoked before payment; same slip reused for another checkout; auto/manual race with distinct SlipVerification rows for same checkout; repeated worker after cache expiry; manual unauthorized actor; genuine received payment but no valid payable checkout; partial remote stock reservation; legacy paid order with no checkout is routed for manual review, not retroactively fabricated.
```php
$result = $authority->settle($payableCheckout, $wrongAmountEvent);
$this->assertSame('manual_hold', $result->action);
$this->assertSame('paid_hold', $payableCheckout->fresh()->state);
$this->assertDatabaseCount('verified_payment_events', 1);
Queue::assertNotPushed(ReserveAccountStock::class);
```
- [ ] **Run RED:** `./vendor/bin/phpunit tests/Feature/CommerceSafety/CheckoutSettlementTest.php --no-coverage`.
- [ ] **Implement settlement atomically:** validate event ownership/status/amount; exact expected amount by default, and never silently widen the existing bot's verified payment tolerance. Any configured tolerance must be read and expressly included in the recorded policy; otherwise mismatch becomes paid_hold. Revalidate consent revision and current canonical price/stock. Create Order and link event/checkout once. Settle before enqueueing A3 effects after commit. An incoming payment without a payable checkout still gets a verified receipt + hold, not an invented matched order.
- [ ] **Implement final reservation boundary:** recheck inside AccountDeliveryService entry even if a caller bypasses ReserveAccountStock. Do not exempt payment lines from scoped stock policy. No coercing negative qty to one or silently clamping a paid order. Existing stock-pool reservation anchors and reconciliation handle shortages; preserve already-reserved units and do not replay external allocations when notifications fail.
- [ ] **GREEN on two DB modes:** logic tests on SQLite; race/unique/lock tests on disposable PostgreSQL with two workers. Assert one settled checkout, one Order and one delivery identity; explicit evidence for each external shortage/timeout path. No claim of an atomic transaction spanning both stock DBs.
- [ ] **Complete A3 GREEN**, then independent security and pricing review. Stage only listed files; `git commit -m "fix: bind payment and fulfillment to canonical checkout"`.

## Gate

No authoritative order reconstruction from generated prose, no financial entitlement from free-text Memory, no paid-state reset on ambiguous messages, and no canonical-stock exemption at delivery. No enforcement flag or production migration is executed by this document.
