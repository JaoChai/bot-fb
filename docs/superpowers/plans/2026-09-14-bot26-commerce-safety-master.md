# Bot 26 — Commerce Safety Master Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Each referenced sub-plan is mandatory. Execution and production rollout are separate approvals.

**Goal:** Safely deploy bot 26’s shorter customer-service prompt while making payment, price, stock, consent and fulfillment decisions authoritative in the backend.

**Architecture:** Option 2: retain the existing application and prompt-assisted conversation, but place bot-26-scoped backend trust boundaries around payment and checkout. Implement in three independently reviewable subprojects: verified payment authority, canonical persisted checkout, and reply/evaluation/rollout. Keep all modes off until code, tests and staged evidence are complete.

**Tech Stack:** Laravel 13/PHP 8.4, PostgreSQL, Redis/queues, existing LINE/EasySlip/Telegram integrations, React/TypeScript admin UI where required, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-09-14-bot26-commerce-safety-design.md`

## Global Constraints

- Bot 26 first; every task includes a non-scoped bot regression.
- No serving-model, price, VIP-entitlement, stock, recipient-account, Terms or verified-contact changes.
- Never use prompt text, model output, free-form Memory or customer-supplied markers as financial authority.
- Money received, checkout consent, Order creation and fulfillment authorization are separate persisted facts.
- TDD for every behavior change: observe RED, minimal GREEN, refactor only after green, then independent review.
- Use a clean worktree at the freshly verified successful production revision. Do not touch or clean the user’s unrelated dirty checkout.
- No production DB write, deployment, customer message, slip, notification or fulfillment until a later explicit production approval.

## Documents

1. Payment authority: `docs/superpowers/plans/2026-09-14-bot26-01-payment-authority.md`
2. Canonical checkout: `docs/superpowers/plans/2026-09-14-bot26-02-authoritative-checkout.md`
3. Reply quality and rollout: `docs/superpowers/plans/2026-09-14-bot26-03-reply-quality-rollout.md`

## File-ownership boundaries

- A tasks own `VerifiedPaymentEvent`, proof, financial effect ledger and trusted side-effect dispatch.
- B tasks own strict proposal parsing, canonical cart validation, persisted checkout revision/consent and settlement.
- C tasks own final customer-reply policy, prompt/evaluation assets and audited prompt deployment.
- Shared files (`AIService`, payment/LINE/plugin services) are changed only in the task named in the sequence below; later tasks rebase and modify only the seam they own. If two task diffs overlap, integrate sequentially and review the resulting whole file before commit.

## Execution sequence and hard gates

### Phase 0 — clean baseline

- [ ] Read live successful backend commit and compare to plan baseline `61ae63fa203c70b734c27cd7ef3f8b74d365d7e9`.
- [ ] Load `superpowers:using-git-worktrees`; create a clean worktree and branch from the live commit.
- [ ] Run backend unit baseline, frontend baseline and `git status --short`; save outputs. Existing failure blocks implementation unless separately understood and approved.
- [ ] Refresh only safe production facts: bot 26/flow 24 hashes and settings; canonical prices/VIP values/stock flags; plugin ownership; nonsecret ORDER/cache flags. No customer data.

### Phase 1 — establish trust objects with modes off

- [ ] Execute **A1**: SafetyScope, MoneyMinor and immutable verified payment event.
- [ ] Run A1 targeted SQLite tests and disposable-PostgreSQL uniqueness/race tests.
- [ ] Independent review: event provenance, money parsing, schema/index correctness, bot-27 compatibility.
- [ ] Execute **B1**: strict proposal adapter and canonical cart validator for every product.
- [ ] Run price/VIP/stock matrix plus integer overflow and duplicate-line capacity tests.
- [ ] Independent commerce-pricing review. Reject any prompt/model amount that differs from canonical data.

**Gate 1:** no config mode enabled; proof and validation exist with all new consumers inactive. Both commits individually green and reviewed.

### Phase 2 — bind actual consent and settlement

- [ ] Execute **B2**: persisted checkout revision, presented challenge and stage-specific customer acceptance.
- [ ] Verify edits invalidate confirmation; Support/Terms cannot be accepted before presentation; Memory cannot create price entitlement.
- [ ] Execute **A2**: genuine payment producers and proof-required financial consumers.
- [ ] Verify ordinary model text cannot create success Flex, financial plugin, Order or reserve job.
- [ ] Execute **B3**: settle proof against the exact payable checkout; canonical Order and delivery authorization.
- [ ] Run SQLite logic plus PostgreSQL concurrency/race matrix.
- [ ] Independent security review and pricing-integrity review.

**Gate 2:** a verified receipt without a valid checkout becomes `paid_hold`; it is never discarded or auto-fulfilled. One checkout/event produces at most one Order.

### Phase 3 — durable effects and customer reply boundary

- [ ] Execute **A3** after B3: durable, per-effect idempotency for LINE receipt, Telegram notification and reservation.
- [ ] Verify external HTTP is outside transactions; uncertain transport is reconciled, not blindly replayed.
- [ ] Execute **C1**: exact contact allowlist and scoped truthful AI identity.
- [ ] Rerun known T09 and sanitizer tests; bot 27 behavior stays unchanged.
- [ ] Independent review of shared output/plugin files after A2/A3/C1 are integrated.

**Gate 3:** generated text is presentation only. Every financial side effect has verified-event + checkout + idempotent effect identity. Customer-visible contact and identity outputs pass scoped policy.

### Phase 4 — evaluated candidate and release tooling

- [ ] Execute **C2**: versioned prompt/manifest, 41 application specifications, 38 raw-model text cases and three image paths.
- [ ] Manual semantic review of every returned raw-model reply; automatic 38/38 alone is insufficient.
- [ ] Execute **C3**: hash-preconditioned prompt deployment, encrypted backup, cache invalidation and exact rollback.
- [ ] Prove prepare has no Flow mutation; prove apply/rollback byte equality in isolated databases.
- [ ] Run full backend suite, frontend tests/build if UI changed, format/static checks and dependency audit.
- [ ] Request final pre-production code review using `requesting-code-review`; resolve all P0/P1 and rerun affected suites.

**Gate 4:** reviewed code and candidate exist in one exact commit; release mode remains `off`; no unresolved automated or semantic failures.

### Phase 5 — stage and separately approved production rollout

- [ ] Execute only the nonproduction portions of **C4**: staged clone, fake external transports, shadow/enforce test and reconciliation.
- [ ] Produce a measured release packet: commit, migration hashes, prompt hashes, model/settings, 41-case results, paid_hold count, effect reconciliation and rollback rehearsal.
- [ ] Ask owner for explicit production approval. Execution-method selection is not this approval.
- [ ] If approved, follow C4 production sequence exactly: backend off → migration → shadow → prompt apply/cache purge/readback → owner checkpoint → enforce.
- [ ] Monitor scoped safety metrics and keep hold/rollback commands ready. Do not use real customer payments as smoke tests.

## Required review packet

- Git diff and commit list per A1/B1/B2/A2/B3/A3/C1/C2/C3/C4-test task.
- RED and GREEN command output for each behavior change.
- SQLite logic and disposable PostgreSQL race/unique evidence.
- Full backend and applicable frontend results.
- Raw-model request/returned model/settings/request IDs and every reviewed output.
- Exact active/candidate/backup hashes and cache-invalidation readback.
- Security, pricing and final code-review findings with dispositions.
- Explicit list of skipped or unverified external paths; zero fabricated passes.

## Definition of done

Implementation is ready to request production approval only when:

1. Text cannot originate a trusted payment event or completed financial side effect.
2. Product, method, quantity, normal/VIP unit price, total, stock and consent are server-validated from one checkout revision.
3. Duplicate/reordered automatic and manual events produce one settlement and idempotent effects.
4. Customer replies use only verified contacts and truthful AI identity for bot 26; other bots do not change.
5. All 41 application specifications, 38 raw-text evaluations, image paths, full suites and independent reviews pass.
6. Prompt rollout and rollback are hash-guarded, audited, cache-safe and rehearsed.
7. Production remains unchanged until the owner gives a separate explicit rollout approval.