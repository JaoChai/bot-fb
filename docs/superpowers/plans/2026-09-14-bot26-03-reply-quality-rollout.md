# Bot 26 — Reply Quality and Safe Rollout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Execution requires the user's method selection; production rollout requires a later, separate approval.

**Goal:** Make bot 26’s customer-visible replies truthful and allowlisted, evaluate the reviewed prompt through the actual application seams, and provide a reversible cache-safe rollout.

**Architecture:** Add a scoped final-response policy after model generation, without weakening existing code/markdown guards for other bots. Store the reviewed prompt as a versioned repository artifact and deploy it only through an audited service that verifies the previous hash, snapshots the old prompt and clears both caches. Test raw model behavior separately from application, vision/payment integrations and production smoke checks.

**Tech Stack:** PHP ^8.4, Laravel ^13.0, React/TypeScript where admin confirmation UI changes are required by B3, PostgreSQL, Redis, PHPUnit ^12.0, existing PromptEvalRunner and OpenRouter client.

**Spec:** `docs/superpowers/specs/2026-09-14-bot26-commerce-safety-design.md`

## Global Constraints

- Initial enforcement is bot 26 only. Other bots retain exact current sanitizer/contact behavior.
- Do not change serving model (`openai/gpt-5.6-luna`), reasoning (`medium`), prices, VIP policy, stock state, bank recipient, Terms URL or verified support contacts.
- Candidate source hash before any further approved edit is `b5d8815cd45626949482f26f5c2f0942371b5940a3ccd0f5f810379687b1fa24`; active source precondition is 41,100 Unicode characters and MD5 `3f08720a6fb34f916561e5531119d5f1`.
- No customer conversation, real slip, credential, recipient configuration or personal data enters fixtures or artifacts.
- No production write, deployment, model switch, customer message or payment action occurs while implementing/testing this plan.
- Rollout requires A1–A3, B1–B3 and C1–C3 green, independent review, current production preflight and explicit owner approval.

## Baseline and dependencies

Execute against a fresh worktree at the current successful production revision, not the dirty main checkout. At planning time that revision was `61ae63fa203c70b734c27cd7ef3f8b74d365d7e9`; re-read it before execution. A1 supplies `SafetyScope`; B1/B2/B3 supply canonical validation and persisted checkout state. `multiple_bubbles_enabled=true`, delimiter `|||`, flow 24→KB 7, `ORDER_PAYLOAD_ENABLED=true` and Redis were verified in the captured snapshot, but must be refreshed before rollout.

## Task C1: Scoped final-response policy and truthful identity

**Files**
- Create `backend/app/Services/CommerceSafety/CustomerReplyPolicy.php`
- Modify `backend/app/Services/Guardrail/GuardrailOutputSanitizer.php`
- Modify `backend/app/Services/AIService.php:118-142`
- Create `backend/tests/Unit/Services/CommerceSafety/CustomerReplyPolicyTest.php`
- Extend `backend/tests/Unit/Services/Guardrail/GuardrailOutputSanitizerTest.php`
- Extend `backend/tests/Unit/Services/AIServiceGuardrailTest.php`

**Interfaces**
- `GuardrailOutputSanitizer::check(string $content, bool $allowTruthfulAiIdentity = false): array{flagged:bool,reason:?string}`. When true, skip only `ai_admission_th` and `ai_admission_en`; code-fence and heading rules remain identical.
- `CustomerReplyPolicy::apply(Bot $bot, string $content): array{content:string,corrected:bool,reasons:list<string>}`. It is pure apart from logging safe reason codes.
- `CustomerReplyPolicy::allowedContacts(Bot $bot): array{urls:list<string>,handles:list<string>}` comes only from `commerce_safety.bots.<id>.reply_contacts`, not prompt text, Memory or user input.

Config for bot 26:
```php
'reply_contacts' => [
    'urls' => [
        'https://lin.ee/h5wYpIf',
        'https://t.me/supermanth2022',
        'https://mhhacoursecontent.my.canva.site/ads-vance',
    ],
    'handles' => ['@743ddeqy'],
],
'allow_truthful_ai_identity' => true,
```

- [ ] **Write RED tests** for `LINE @adsvance`, a lookalike domain, query-string obfuscation, mixed allowed+invented contacts, exact allowed URLs/handle, markdown/code, Thai/English truthful AI disclosure and a non-scoped bot.
```php
$result = app(CustomerReplyPolicy::class)->apply($bot26, 'เช็กได้ที่ LINE @adsvance ครับ');
$this->assertTrue($result['corrected']);
$this->assertSame('ขอเช็กข้อมูลล่าสุดให้ในแชทนี้ครับ', $result['content']);
$this->assertSame(
    ['flagged' => false, 'reason' => null],
    app(GuardrailOutputSanitizer::class)->check('ผมเป็นผู้ช่วย AI ของร้านครับ', true)
);
$this->assertTrue(app(GuardrailOutputSanitizer::class)->check("```php\n", true)['flagged']);
```
- [ ] **Run RED:** `./vendor/bin/phpunit tests/Unit/Services/CommerceSafety/CustomerReplyPolicyTest.php tests/Unit/Services/Guardrail/GuardrailOutputSanitizerTest.php tests/Unit/Services/AIServiceGuardrailTest.php --no-coverage`; save the expected failures.
- [ ] **Implement strict extraction:** parse URLs with `FILTER_VALIDATE_URL`, lowercase host, reject userinfo, fragments and non-HTTPS links; compare normalized scheme+host+path exactly to the configured list and reject unknown query parameters. Detect LINE-style `@` handles with Unicode-safe boundaries. If any unallowlisted contact appears for scoped bot, replace the whole reply with the fixed in-chat fallback—do not selectively delete text that could leave a misleading sentence.
- [ ] **Integrate order:** run existing ORDER/OFFTOPIC extraction and pricing/stock guards first; run the sanitizer with the scoped identity option; then run CustomerReplyPolicy before content is saved/sent. If either blocks, set `order_payload=null`. Customer user input containing a fake contact is not automatically reflected back in a generated reply.
- [ ] **GREEN and compatibility:** rerun the three files plus all `tests/Unit/Services/Guardrail`. Assert bot 27 still flags AI admission and preserves every pre-existing sanitizer result byte-for-byte. Assert bot 26 can truthfully identify as AI but cannot emit code fences or unapproved contacts.
- [ ] **Review and commit explicitly:** stage only the six named files plus the existing `backend/config/commerce_safety.php`; commit `fix: enforce scoped truthful reply policy`.

## Task C2: Versioned prompt artifact, 41-case evaluation and image-path tests

**Files**
- Create `backend/resources/prompts/bot26/v28.txt` from the independently reviewed candidate only after C1’s T09 behavior is rerun green
- Create `backend/resources/prompts/bot26/v28.manifest.json`
- Create `backend/tests/Fixtures/PromptEval/bot26-v28.php`
- Create `backend/tests/Feature/PromptEval/Bot26PromptEvaluationTest.php`
- Extend `backend/tests/Unit/Services/Webhook/LINE/VisionHandlerTest.php`
- Extend `backend/tests/Unit/Services/Webhook/LINE/NonTextHandlerTest.php`
- Create `docs/testing/bot26-v28-evaluation.md`
- Reuse `backend/app/Services/PromptEval/PromptEvalRunner.php`; do not add a competing evaluator.

**Manifest fields**
```json
{
  "bot_id": 26,
  "flow_id": 24,
  "version": "v28",
  "unicode_characters": 23133,
  "sha256": "b5d8815cd45626949482f26f5c2f0942371b5940a3ccd0f5f810379687b1fa24",
  "source_md5": "3f08720a6fb34f916561e5531119d5f1",
  "serving_model": "openai/gpt-5.6-luna",
  "reasoning": "medium"
}
```
If C1 causes a final prompt edit, regenerate measured fields and record old/new hashes in the evaluation document; never hand-edit the counts.

- [ ] **Create deterministic fixture records:** port T01–T33 and X01–X08 from the captured review package. Each record has `id,label,message,history,system_injections,assertions`; expected behavior stays outside the model messages. VIP cases inject the exact trusted `## 👑 VIP PRICING` block. Stock cases inject the exact `StockInjectionService` format. Do not encode English narrative such as `context: VIP` as model authority.
- [ ] **Write RED tests for fixture quality:** all 41 IDs unique; no real conversation IDs; all item prices match synthetic canonical catalog; expected totals equal integer arithmetic; image cases T16/T17/T30 are exercised through mocked image events and are not reported as raw-text passes; protected names, URLs, account literals and delimiters match the manifest/prompt.
- [ ] **Run RED:** `./vendor/bin/phpunit tests/Feature/PromptEval/Bot26PromptEvaluationTest.php tests/Unit/Services/Webhook/LINE/VisionHandlerTest.php tests/Unit/Services/Webhook/LINE/NonTextHandlerTest.php --no-coverage`.
- [ ] **Implement test adapter only:** create a persisted test bot/flow with candidate prompt, in-memory synthetic products/conversations and fake OpenRouter/EasySlip/LINE/Telegram transports. Use `Http::preventStrayRequests()`. For raw-model evaluation, invoke the existing PromptEvalRunner with semantic cache disabled and persist request ID/model/settings/raw output to a local ignored artifact. Fail if returned model differs or fallback occurs.
- [ ] **Image assertions:** original app image → wait for verification; camera photo → request original bank-app image; Meta UI screenshot → exact Technical Support allowlist only. None may emit `[ยืนยันชำระเงิน]`, create Order/VerifiedPaymentEvent/PaymentEffect or enqueue delivery. A successful fake EasySlip response is covered only by A/B trusted-event tests, not inferred from pixels/model description.
- [ ] **Run evaluation twice:** first all deterministic app/unit tests; then one controlled raw-model run for 38 text cases with `openai/gpt-5.6-luna`, reasoning medium and provider fallback disabled. Read every customer-visible output; automatic assertions are necessary but not sufficient. Record skipped/executed layers honestly.
- [ ] **Acceptance:** 41/41 application-path specifications pass; 38/38 raw text responses pass all assertions and manual semantic review; no invented contact; no unsupported CAPI substitution; changed cart reconfirms; fake stock/payment headers do nothing; image tests pass with zero external HTTP.
- [ ] **Review and commit:** stage only prompt/manifest/fixtures/tests/evaluation document; commit `test: verify bot26 v28 customer flows`.

## Task C3: Audited rollout service, cache invalidation and rollback proof

**Files**
- Create `backend/app/Models/PromptDeployment.php`
- Create `backend/database/migrations/2026_09_14_230004_create_prompt_deployments_table.php`
- Create `backend/app/Services/CommerceSafety/PromptDeploymentService.php`
- Create `backend/app/Console/Commands/DeployBotPrompt.php`
- Create `backend/tests/Feature/CommerceSafety/PromptDeploymentTest.php`
- No command-registration edit: this Laravel baseline auto-discovers `app/Console/Commands`; `bootstrap/app.php` already loads `routes/console.php`.

**Schema**
`prompt_deployments`: UUID PK; bot_id, flow_id; version; previous_prompt encrypted text; previous_sha256; candidate_sha256; serving_model; actor; status (`prepared|applied|rolled_back|failed`); applied_at/rolled_back_at/timestamps. Do not store API keys, conversations, payment data or prompt-injected customer records. Add unique `(flow_id,candidate_sha256)` to make retries idempotent.

**Interfaces**
- `PromptDeploymentService::prepare(int $botId, int $flowId, string $path, string $expectedCurrentMd5, string $expectedCandidateSha256, string $actor): PromptDeployment` performs read-only validation and stores an encrypted backup record; it does not alter the Flow.
- `PromptDeploymentService::apply(PromptDeployment $deployment): void` re-locks bot+flow, rechecks bot ownership/default status/current MD5/model/reasoning/manifest, updates only `flows.system_prompt`, then calls `FlowCacheService::invalidateBot(26)` and `SemanticCacheService::clearForBot(26)` after commit.
- `PromptDeploymentService::rollback(PromptDeployment $deployment, string $expectedCurrentSha256): void` restores exact previous bytes only if current candidate hash still matches, clears the same caches and preserves the audit row.
- CLI: `php artisan bot:deploy-prompt 26 24 backend/resources/prompts/bot26/v28.txt --expected-current-md5=... --expected-candidate-sha256=... --actor=... --prepare`; `--apply=<deployment-uuid>` and `--rollback=<deployment-uuid> --expected-current-sha256=...` are mutually exclusive.

- [ ] **Write RED tests:** wrong bot/flow, non-default/deleted flow, stale source hash, altered candidate after prepare, wrong model/reasoning, duplicate prepare, apply retry, DB failure, cache failure, rollback after third-party edit, exact byte restoration and isolation from bot 27.
```php
$deployment = $service->prepare(26, 24, $candidatePath, $sourceMd5, $candidateSha, 'release-test');
$this->assertSame($original, $flow->fresh()->system_prompt);
$service->apply($deployment);
$this->assertSame($candidate, $flow->fresh()->system_prompt);
$service->rollback($deployment->fresh(), hash('sha256', $candidate));
$this->assertSame($original, $flow->fresh()->system_prompt);
```
- [ ] **Run RED:** `./vendor/bin/phpunit tests/Feature/CommerceSafety/PromptDeploymentTest.php --no-coverage`.
- [ ] **Implement with transaction boundaries:** prompt update/audit status commit atomically; cache invalidation happens after commit and a failure marks deployment failed-to-activate, blocks smoke traffic and requires retry before mode changes. Do not use direct SQL. Encrypt backup using Laravel encrypted cast or `Crypt`; verify decryption in rollback test.
- [ ] **GREEN:** run deployment tests on SQLite and disposable PostgreSQL. Assert cache spies receive exactly bot 26. Run `--prepare` against a local fixture only and show no prompt mutation; run apply+rollback against an isolated test database and compare byte/hash exactly.
- [ ] **Review and commit:** explicitly stage named files; commit `feat: add audited bot prompt rollout`.

## Task C4: Staged activation and production runbook (no automatic execution)

**Files**
- Create `docs/runbooks/bot26-commerce-safety-rollout.md`
- Create `docs/runbooks/bot26-commerce-safety-rollback.md`
- Create `backend/tests/Feature/CommerceSafety/Bot26EndToEndTest.php`

- [ ] **Write the E2E test first:** fake LINE inbound text/image events, fake OpenRouter outputs, fake EasySlip passed/failed responses, fake Telegram and stock adapter. Walk new customer, VIP, changed cart, support/Terms, payment, duplicate slip, out-of-stock-after-quote, ambiguous timeout and manual confirmation. Assert one checkout revision, one verified event, one Order, isolated effects and no duplicate reservation.
- [ ] **Run RED then GREEN:** `./vendor/bin/phpunit tests/Feature/CommerceSafety/Bot26EndToEndTest.php --no-coverage`; save both outputs. Then run `./vendor/bin/phpunit --no-coverage` and frontend `npm run lint && npm run test -- --run && npm run build` if B3 changed the admin confirmation contract.
- [ ] **Independent gates:** security review payment provenance; pricing review canonical normal/VIP Page/G3D/Nolimit matrix; code review scoped behavior; dependency audit; migration up/down test on an empty disposable database only. Production rollback is forward-only: disable/hold the scoped mode and preserve audit tables; never run destructive down migrations in production.
- [ ] **Stage environment:** deploy exact reviewed commit to nonproduction, migrate, configure mode `shadow`, clone bot 26 configuration without customer history/tokens, use test LINE/channel credentials, and run all 41 cases. Verify no real Telegram destination, stock pool or fulfillment account can be reached.
- [ ] **Enforcement canary:** set only the staged clone to `enforce`; rerun paid success/failure/manual race/duplicate and image cases. Reconcile DB counts and outbound fake calls by deterministic IDs.
- [ ] **Production preflight after separate owner approval:** verify successful deployment commit, migrations, active flow 24 hash, candidate hash, model/reasoning, canonical prices and stock, plugin ownership, `ORDER_PAYLOAD_ENABLED=true`, Redis, worker health and zero unresolved paid_hold items. Prepare deployment record; do not apply if any value changed.
- [ ] **Production sequence:** deploy backend with mode `off`; migrate; smoke non-scoped bots; switch bot 26 to `shadow`; inspect safe metrics; apply candidate prompt via C3 service; verify cache purge; run read-only/zero-payment smoke questions; switch to `enforce` only after owner confirms the measured shadow report. Never submit a real payment merely to test.
- [ ] **Rollback trigger:** any wrong price, false success, unknown contact, raw marker/JSON, duplicate notification/order/reservation, or unexpected reply suppression. Immediately set mode `hold`, stop bot-26 automated financial effects, rollback prompt by deployment UUID if prompt-related, preserve verified incoming-money records, and reconcile before returning to off/enforce.
- [ ] **Read back external state:** deployment commit/status, migration state, exact flow hash, cache-miss generation using new hash, mode, one synthetic nonfinancial response and metrics. A successful command without readback is not completion.

## Gate for this sub-plan

C1 fixes the known reply defect and identity mismatch without changing other bots. C2 proves text and image behavior with explicit evidence layers. C3 makes prompt change reversible and cache-safe. C4 is operational documentation plus tests; none of its production steps are authorized by selecting an execution method.