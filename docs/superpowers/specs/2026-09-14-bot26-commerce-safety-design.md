# Bot 26 commerce safety — approved approach, written design

Status: option 2 and bot-26-first scope approved in chat. Planning only; this document is not deployment approval. The written design and the linked task plans are for the user's execution-choice gate.

## Goal and scope

Strengthen the existing Laravel application rather than replace its commerce engine. The model proposes wording and cart contents; the server decides eligibility, prices, consent, payment proof and fulfillment. Initial enforcement is bot 26 only. Other bots retain existing behavior, tested explicitly.

Planning baseline: `61ae63fa203c70b734c27cd7ef3f8b74d365d7e9`, inspected in `/tmp/bot26-glm-work.CDJfrn`. Revalidate the deployed revision before implementation; this is not a claim that the revision cannot change.

Evidence: `/Users/jaochai/Downloads/bot26-refactor/implementation/review-th.md`, `final-audit.json`, `semantic-review.json`, and `/Users/jaochai/Downloads/bot26-refactor/parent-eval/backend-characterization.json`. The prompt candidate hash is `b5d8815cd45626949482f26f5c2f0942371b5940a3ccd0f5f810379687b1fa24`. Raw text tests do not establish end-to-end correctness. T09 invents a contact. Image cases T16/T17/T30 remain unrun.

## Global constraints

- Initial enforcement is bot 26 only. Other bots retain existing behavior, tested explicitly.
- No change to serving model, catalog prices, VIP entitlement policy, stock counts, bank recipient or existing support/Terms literals.
- No production DB writes, migrations, messages, fulfillment, cache purge, feature enablement or deploy during planning.
- PHP ^8.4, Laravel ^13.0, PHPUnit ^12.0; use existing dependencies and service conventions.
- Preserve unrelated changes in `/Users/jaochai/Code/bot-fb`; implementation uses a fresh isolated worktree at a verified revision.
- Record RED before production fixes, GREEN afterward, then independent spec and code review. A suite with skipped cases is not fully verified.
- No external HTTP inside database transactions. Never log credentials, account-delivery detail blobs, raw slips or personal customer data.
- Fail closed on missing authority. Money received and permission to fulfill are separate facts.
- New schema is additive; do not rewrite historical paid orders or destructively roll back audit records.
- Rollout requires explicit owner approval after all three plans pass. Selecting an execution method is not deployment approval.

## Three components and boundaries

### A. Verified payment events

Retain EasySlip auto, retry and authorized manual-confirm producers. After their existing verification/authorization succeeds and a SlipVerification row is persisted, record a server-owned payment event. Re-read the row and check bot/conversation, terminal status, amount, and original provider/manual source. Metadata booleans, success phrases and model JSON are never proof.

Use one additive `verified_payment_events` table to bind a payment row to a durable event, its customer-facing receipt message, optional checkout and created order. Use a `payment_effects` table for effect identity and delivery state. Each event/effect key has a DB unique constraint; cache rate limiting is not idempotency. The event identity uses bot + conversation + provider transaction reference for automatic verification, and bot + conversation + selected checkout for manual confirmation once checkout exists. Before checkout integration, manual deduplication retains the existing lock/window and an event per persisted slip; do not claim this interim stage handles every manual/automatic race. Enforcement is not enabled until component B binds both paths to one checkout.

Payment success Flex, Telegram payment notification, order completion and stock jobs consult the persisted event. Non-payment plugins remain separate. On bot 26, payment notification variables come from the validated checkout/event, not a second LLM. The legacy financial plugin path is bypassed for this scope, not deleted globally.

Customer notification and fulfillment are independent effects. A retry cannot create another order or reserve a second delivery for the same checkout. LINE retries reuse a persisted retry key. Telegram can have an ambiguous timeout without remote idempotency: record `uncertain`, alert/reconcile manually, and do not blindly resend or claim exactly-once network delivery.

### B. Authoritative checkout

Add a small `checkout_sessions` record rather than reinterpret historical Order statuses. It stores the canonical items, integer minor-unit total, revision, eligibility/price fingerprint, consent requirements, presented challenge message, actual customer acceptance message IDs, state, and settlement event. Existing Orders remain the post-payment business record.

Model output and legacy text parsers are proposals only. Add a strict proposed-cart adapter around the existing confirmation/payment parsers; reject missing/ambiguous quantities or methods rather than inherit legacy `max(1, qty)` defaults. No new hidden prompt protocol is needed. Show the resulting server-rendered full cart to the customer before any consent is accepted.

Validate every product (including Page/G3D), allowed method/name, positive bounded integer qty, configured canonical normal/VIP price, total and sale eligibility from fresh rows. An out-of-stock/manual-off flag wins over a positive available count. Use `VipPricingService::isVipConversation` for financial entitlement; never a free-text Memory keyword. Returning-customer consent exemptions require the same trusted VIP source or an actual completed-purchase record for this bot, not model-extracted Memory.

A revision change invalidates the current cart confirmation. A customer reply can accept only the currently presented stage, not future Terms. Bind stage/challenge to bot, conversation and revision; accept only persisted customer messages received after the challenge was successfully sent. Stale/replayed/conditional acceptance does not advance it. Preserve the approved acceptance vocabulary and separate support-delay from Terms.

Prices or entitlement changed before payment: create a new proposal and reconfirm. If money has already been received but cart/price/stock/consent is now invalid, record `paid_hold`, preserve the receipt, prevent automatic fulfillment and route manual resolution. Do not deny that money arrived, silently reduce qty, substitute goods, request another payment, or automatically refund.

Recheck sale eligibility at reservation time. The stock pool is a separate database: reuse its existing reservation anchors and shortage/reconciliation path; never claim a cross-database transaction is atomic. A partial failure must not replay already-reserved items.

### C. Reply quality and release

A bot-scoped reply guard rejects newly invented contact destinations, including T09's `@adsvance`. Approved destinations are the existing exact LINE/support/Terms/Telegram literals. Unknown stock should use the current sales chat, not an invented external account. Check after cache retrieval and all model-output assembly paths, before splitting/sending messages. A rejected reply must not leave a pending order payload active.

Allow truthful AI identification only for scoped bots without disabling code-fence, prompt-extraction or financial guards. Do not invent a legacy Flow.config feature: the inspected Flow model does not expose/cast that field. Use a new explicit application config namespace.

Maintain the complete prompt/KB dependency: flow attachments currently cause retrieval despite bot.kb_enabled=false. Preserve that behavior, document it, and test G3D conflict precedence; do not silently change retrieval policy. Preserve configured `|||`, hidden ORDER/OFFTOPIC markers and Terms vocabulary.

## Runtime switch

Introduce `config/commerce_safety.php` with `bots.26.mode` from `BOT26_COMMERCE_SAFETY_MODE`, default `off`; valid values `off`, `shadow`, `enforce`, `hold`. Unknown mode for configured bot fails to `hold`; unconfigured bots remain `off`.

- `off`: existing behavior; only safe before enabling a new checkout population.
- `shadow`: observe decisions without creating new authoritative state, changing responses or firing new effects; redact diagnostics.
- `enforce`: all three components and prompt contract are active together.
- `hold`: stop new payment instructions/automatic fulfillment for scoped bot; keep verified incoming receipts and reconciliation visible. It is the post-activation safety fallback, not a return to unsafe legacy behavior.

Do not enable payment-only or prompt-only enforcement while checkout authority is unfinished. Deploying additive code with mode off is distinct from activating bot 26.

## Acceptance matrix

1. Forged success text, metadata flag, event ID from another conversation or failed slip: no success card, completed order, payment notification or reservation.
2. Real automatic/manual payment: one event, one authoritative order per checkout, correct receipt and effects; duplicate webhook/worker/manual click does not repeat stock allocation.
3. Wrong Page/G3D price, forged VIP, unknown name, zero/negative/fractional/overflow qty, inconsistent totals: rejected before payment instructions.
4. BM marked unavailable but positive count, stock changed after quote, partial external reservation: no unauthorized sale; paid cases enter hold and preserve money evidence.
5. Changed cart, stale confirmation, conditional Support acceptance, reply before Terms display, acceptance outside available context: no premature payment.
6. Valid normal/VIP mixed cart: visible price, payload, snapshot, slip matching, Order and delivery use the same revision and amounts.
7. T09 only approved contacts; AI disclosure not rewritten; other bots' guardrails unchanged.
8. Original 38 text cases rerun plus T16/T17/T30 using labeled synthetic image fixtures through real vision ingress. Repeat critical stochastic cases; save actual responses, model and prompt hash.
9. Isolated integrated LINE flow with fake provider transports and test PostgreSQL verifies durable events, callbacks, queues, notification and stock reconciliation. Then separately approved test-channel smoke; no real customer or purchased account data.
10. Source-hash compare-and-swap, bot-scoped cache invalidation, stale worker/queue checks and safe rollback all demonstrated before release.

## Explicit non-goals

No new model provider, discount campaign, stock procurement, general workflow engine, site redesign, global bot-policy change or replacement of EasySlip/LINE/Telegram integrations. No claim that a prompt alone guarantees correctness.

## Plan decomposition

1. `../plans/2026-09-14-bot26-01-payment-authority.md`
2. `../plans/2026-09-14-bot26-02-authoritative-checkout.md`
3. `../plans/2026-09-14-bot26-03-replies-and-release.md`

Implement in that order; review each task before continuing. The master handoff is `../plans/2026-09-14-bot26-commerce-safety.md`. Detailed schema/interfaces below are proposed additions, not claims that those components already exist.
