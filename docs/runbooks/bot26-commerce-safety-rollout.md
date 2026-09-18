# Bot 26 commerce safety: staged activation and production rollout

## Authority and current decision

**Documentation only. NO-GO for staging activation or production rollout until every applicable gate below is evidenced and the owner separately approves execution.** C4 implementation, passing PHPUnit fakes, and approval of C1–C3 are not activation approval.

This runbook implements the authoritative `task-4-preflight-final.txt` ruling supplied for C4, correcting the production order in [Task 4](../superpowers/plans/2026-09-14-bot26-03-reply-quality-rollout.md). The preflight's original NO-GO at `30fd4dc8` was historical; re-evaluate every gate against the exact later reviewed release commit. Use the [rollback runbook](bot26-commerce-safety-rollback.md) for containment and recovery.

Implementation baseline: `645b386824ebff711b19e535ec4f12897fe970ba`. Known open gates at this baseline:

- [C2 evidence](../testing/bot26-v28-evaluation.md) records T17 classification blocked and application semantic gaps. Raw inference is now **executed** — twelve full runs, 456 live calls — but the gate **FAILS**: reviewing all 38 responses found four REJECT verdicts (T07, T11, T13, T26). Two of them, T11 and T13, reproduce at the same rate against the artifact as committed at `639867bd`, so they are pre-existing defects rather than anything this branch introduced. See [live raw eval evidence](../testing/bot26-v28-live-raw-eval-2026-09-16.md). Historical replay success was never raw-text acceptance for v28. **Update 2026-09-18:** T13 is fixed (`3667db49`) and T26 is fixed and measured — 6 of 29 baseline replies defective versus 0 of 30 after, Fisher exact p = 0.0105, see [T26 off-topic refusal](../testing/bot26-v28-t26-offtopic-2026-09-18.md). That measurement also found and fixed a control-token leak: `[[OFFTOPIC]]` placed mid-message reached the customer, because `OffTopicSignalExtractor` stripped a trailing tag only; it now strips the tag wherever it appears, while still counting a circuit-breaker strike only when the tag is last. **Update 2026-09-18 (2):** T07 and T11 are fixed and measured too — 6/75 → 0/75 (p = 0.0282) and 9/75 → 0/75 (p = 0.0030), see [T07 and T11](../testing/bot26-v28-t07-t11-2026-09-18.md). All four semantic REJECTs from the gate review are now closed. One deterministic defect still blocks a clean sweep: the model closes a sentence with ครับ against a link (`https://lin.ee/h5wYpIfครับ`), which CustomerReplyPolicy reads as an unapproved URL and replaces the whole reply for. It appeared once in three full 38-case runs.
- **Fixed.** The candidate lives inside the backend build context at `backend/resources/prompts/bot26/v28.txt` (moved via `git mv`; subsequently re-measured after the consent-vocabulary fix, the SUPPORT_DELAY conditional-consent fix, the Personal-cannot-receive-a-Page-normally rule, the T18 cancel-after-slip Support-contact fix and the Page-upsell disambiguation rule fix below). The required adjacent nested C3 manifest is committed at `backend/resources/prompts/bot26/v28.manifest.json`, measured from the actual artifact bytes (24,916 Unicode characters, 63,473 bytes, SHA-256 `b88b8619faa911e2e76f92a08389c85dd695a02bc213bc272a93356bbdbd3473`) with the known active-flow source facts (41,100 Unicode characters, MD5 `3f08720a6fb34f916561e5531119d5f1`). It is distinct from, and not confused with, the evaluation inventory schema at `backend/tests/Fixtures/PromptEval/bot26-v28/manifest.json`, which C3 still deliberately rejects. `backend/tests/Feature/CommerceSafety/PromptDeploymentTest.php::test_prepare_accepts_the_real_committed_v28_manifest_and_measurements_match_file_bytes` loads the real committed manifest through `PromptDeploymentService::prepare()` and asserts its measurements match the artifact bytes; every check except the literal production-prompt content (which this environment cannot reproduce) passes.
- **Fixed.** The Support-Delay consent vocabulary mismatch between `backend/app/Services/CommerceSafety/CheckoutAuthority.php::SUPPORT_ACCEPT` and the v28 prompt's accepted-word list is resolved: both now accept the same set (ตกลง, ยอมรับ, รับทราบ, รับได้, โอเค, ได้, ok, accept, agree), and the prompt no longer instructs the model to ask for an invented re-acceptance phrase. This changed the candidate's bytes/SHA-256; see [C2 evidence](../testing/bot26-v28-evaluation.md).
- **Fixed.** Live raw-model evaluation found that same wording change made the model over-apply its conditional-consent handling: an unconditional accept of the SUPPORT_DELAY question (e.g. plain "ยอมรับ") was incorrectly re-asked as if the customer had added a condition (case T13). The SUPPORT_DELAY instruction now explicitly scopes the "explain and ask for one exact listed word" behavior to only the case where the customer's own latest message adds a condition, and restates that every other clear accepted word advances immediately. This changed the candidate's bytes/SHA-256; see [live raw eval evidence](../testing/bot26-v28-live-raw-eval-2026-09-15.md).
- **Fixed.** Owner-supplied business rule: a Personal account cannot receive a Page by the normal method (share/partner share/admin invite), but the shop's own technique can get a Page onto a Personal account, and that requires the customer to notify the Support team first. Line 178's Personal clause now says so instead of only covering partner-share; no cart mutation, BM upsell or guaranteed-outcome language was added. This changed the candidate's bytes/SHA-256 above; see the 2026-09-15 addendum in [live raw eval evidence](../testing/bot26-v28-live-raw-eval-2026-09-15.md).
- **Fixed.** Live T24 runs against the previous artifact were inconsistent (one clean pass, one needless cart re-summary/re-confirmation, one bare "รับได้" claim omitting the not-normal-way caveat). The rule now lives primarily on the Page catalog line (read before the Support/misc section), explicitly forbids answering just "ได้"/"รับได้", requires stating the normal way does not work plus the shop's Support-first technique with the existing Technical Support contact, and states this question must not re-summarize the cart or re-ask for ยืนยัน. Line 178 was shortened to a cross-reference to avoid duplicating contradictory text. This changed the candidate's bytes/SHA-256 above; see the second 2026-09-15 addendum in [live raw eval evidence](../testing/bot26-v28-live-raw-eval-2026-09-15.md).
- **Fixed.** Live sampling of T18 (customer says "ยกเลิกครับ" after a slip was already sent) found the model correctly refused to cancel and routed to staff every time, but omitted the exact Support contact link in most runs (1/4 sampled passes). The `<cart_invariants>` cancel-after-slip line now explicitly requires attaching the exact contact `https://lin.ee/h5wYpIf` every time the reply tells the customer to contact or wait for staff about this order. No consent vocabulary, price, marker or Page/Personal rule was touched. This changed the candidate's bytes/SHA-256 above; see the 2026-09-16 addendum in [live raw eval evidence](../testing/bot26-v28-live-raw-eval-2026-09-15.md).
- **Fixed.** `prompt_deployment.artifact_root` now defaults to `base_path('resources/prompts/bot26')` (i.e. `backend/resources/prompts/bot26`), inside the Dockerfile's `backend` build context. `docker build -t bot26-pkg-check backend` followed by `docker run --rm --entrypoint sh bot26-pkg-check -c 'sha256sum /var/www/html/resources/prompts/bot26/v28.txt'` reproduced the same SHA-256 inside the built image, proving the artifact and its manifest are packaged and resolvable under the configured root. `backend/.dockerignore` does not exist, so nothing excludes `resources/prompts/bot26` from the `COPY . .` build step. C3's path-containment checks (`PromptDeploymentService::containedPath()`) were not modified.
- **Partially fixed, not yet independently reviewed or staged.** A shared runtime hold kill switch (`App\Services\CommerceSafety\HoldOverride`, operated by `php artisan bot26:commerce-safety-hold`) and a measured, aggregate, redacted shadow-report facility (`php artisan bot26:shadow-report`, reading the new additive `commerce_safety_shadow_observations` table) now exist on branch `feat/bot26-shadow-report`, built and proven locally with PHPUnit only — no staging/production execution. See the two checklist items and the Limitations table below for exactly what is and is not established.

## Required nonproduction gate checklist

Record evidence IDs, timestamps, exact commit and reviewer/owner for every box. A missing, failed or unverified box is NO-GO; do not silently waive it.

- [ ] One clean, independently reviewed commit contains C1–C4, the v28 artifact and its valid C3 deployment manifest together.
- [ ] Measure candidate: 24,916 Unicode characters, SHA-256 `b88b8619faa911e2e76f92a08389c85dd695a02bc213bc272a93356bbdbd3473`. Active-flow source: 41,100 Unicode characters, MD5 `3f08720a6fb34f916561e5531119d5f1`. Manifest measurements match actual bytes.
- [ ] Security review of payment provenance, pricing review of canonical normal/VIP Page/G3D/Nolimit matrix, scoped-behavior review and dependency audit have no unresolved P0/P1 findings. A1–A3, B1–B3 and C1–C3 required checks and independent reviews are green.
- [ ] C4 RED/GREEN, the complete backend suite result and applicable frontend checks are recorded. Resolve release-blocking suite findings; recording a failed suite closes execution evidence, not acceptance.
- [ ] Migration up/down evidence exists on an **empty disposable PostgreSQL database only**, including safety/audit migrations and rollback proof. SQLite tests are not PostgreSQL concurrency or migration proof. No production down migration.
- [ ] Stage has separate database, cache/Redis, queues, storage and stock database. It contains no production DSN, LINE token, Telegram token/chat, EasySlip credential, customer history or fulfillment account.
- [ ] Egress controls or dedicated test accounts make production Telegram, stock and fulfillment destinations unreachable. Test the isolation independently of PHPUnit fakes.
- [ ] Synthetic staged bot retains ID **26**, flow ID **24**, and a trusted enabled payment plugin owned by that flow matching configured IDs (currently plugin **1**). A clone with a new bot ID silently becomes unscoped.
- [ ] Exactly one scheduler runs across the environment. The Docker image already embeds one; additional replicas/schedulers must not duplicate it.
- [ ] Deploy initially with `BOT26_COMMERCE_SAFETY_MODE=off`. Read back deployment SHA/status, image/build source, packaged artifact path, migration status and runtime mode from **every** web/worker instance.
- [ ] Establish the containment mechanism and demonstrate mode propagation with no stale worker overlap; meet the hold prerequisite below. **Mechanism now provided, not yet demonstrated in a real environment.** `App\Services\CommerceSafety\HoldOverride` is a shared, cache-backed kill switch that `SafetyScope::mode()` checks before config (`backend/app/Services/CommerceSafety/SafetyScope.php`), so it forces `hold` for a bot across web, every queue worker and the scheduler with no restart, redeploy or config-cache purge. Operate it with `php artisan bot26:commerce-safety-hold --bot=26 --engage` (readback via `--status`; undo via `--release`) — see exact commands below. Proven locally (no staging access) in `backend/tests/Unit/Services/CommerceSafety/SafetyScopeHoldOverrideTest.php` and `backend/tests/Feature/CommerceSafety/CheckoutSettlementTest.php::hold_override_blocks_the_next_fulfillment_promise_for_an_already_resolved_presenter`, which resolves a service instance before engaging hold (simulating a worker that started under `enforce`) and asserts it is blocked immediately with `commerce_safety.bots.<id>.mode` in config left untouched. This box still needs the real demonstration: engage in the actual staged environment and read back `resolved_mode` from every live web/worker/scheduler process. It does not change how `off`/`shadow`/`enforce` transitions propagate — those still require the restart/config-cache-purge procedure in the production sequence below; only `hold` is instantaneous by design (it is the safety fallback).
- [ ] Switch isolated stage to `shadow` using the old prompt, restart every config-cached process and read back mode. Run all **41/41 application cases** and all **38 raw-text cases, every one reviewed with no REJECT verdict** (owner ruling, 2026-09-16: semantic review is the gate, the fixture literals are a recorded smoke signal only — see [live raw eval evidence](../testing/bot26-v28-live-raw-eval-2026-09-16.md)), with the exact candidate, model and reasoning in the isolated evaluation harness. Do not expose v28 through shadow customer serving.
- [ ] Produce the measured, aggregate, redacted shadow report through an approved and validated facility. **Facility now provided and reviewed (2026-09-18); still not run against real traffic.** The review found and fixed three defects before any flip: (1) `ShadowObservationRecorder` called `forceCreate()` unguarded inside `AIService::generateResponse()`, so a failed observation write — an unreachable table, a connection blip — threw straight through and killed the customer's reply; it now logs `commerce_safety.shadow_observation.write_failed` and continues, proven by `ShadowObservationRecordingTest::test_a_failed_observation_write_never_breaks_the_customer_reply`. (2) `SafetyScope::mode()` escalated to `hold` whenever `HoldOverride` could not read the cache, **in every configured mode**, so a Redis outage would suppress bot 26's payment plugin and block its orders while commerce safety was configured `off`; the fail-closed reading now applies only to configured `enforce`/`hold`, while an operator-engaged hold still reaches `shadow` (the rollout's own containment step depends on that). (3) the payment-plugin trust check ran a `flow_plugins` `COUNT` on every `mode()` call in `shadow`, measured at 6 extra queries per `AIService::generateResponse()` alone and now 0; it runs for `enforce` only, where it is the only mode that can wrongly trust a plugin — which also removes the trap where a stale plugin id silently turned an observation phase into `hold`. Pre-flight readbacks against production on 2026-09-18: `commerce_safety_shadow_observations` exists with 0 rows, and payment plugin 1 belongs to flow 24 of bot 26 and is enabled, so a flip to `shadow` resolves to `shadow` and not `hold`. Scope limit to set expectations: the report's own `notes` state that `consent_mismatch_count`, `would_be_checkout_states`, `paid_hold_candidates` and `effect_intents` are structurally always 0 in shadow, so this facility measures cart-proposal validity (price/stock reason codes) and nothing about consent or settlement. `php artisan bot26:shadow-report --bot=26 --since=<ISO8601> --until=<ISO8601> [--json=<path>]` aggregates the new additive `commerce_safety_shadow_observations` table into counts only (never customer text/PII) — see "What shadow mode records" and exact commands below. This box still needs independent review of the facility and a real run against staged `shadow`-mode traffic; the PHPUnit evidence in `backend/tests/Feature/CommerceSafety/ShadowObservationRecordingTest.php` and `backend/tests/Feature/Console/Bot26ShadowReportCommandTest.php` is implementation proof, not that evidence.
- [ ] Confirm `hold`, then rehearse C3 prepare/apply/cache verification and synthetic generation before switching only the isolated staged bot to `enforce`. Rerun paid success/failure, manual/automatic race, duplicate, VIP, changed-cart, stock-change, ambiguous Telegram and T16/T17/T30 image cases. A canned T17 result does not close recognition acceptance.
- [ ] Rehearse `hold → prompt rollback by UUID → both cache invalidations → exact old hash → mode readback`, retaining proof and audit rows.
- [ ] Owner approves the measured stage packet before any production work.

### Shadow report and hold kill switch (new, local implementation only)

Built and PHPUnit-tested in an isolated worktree only; no staging/production execution, no independent review yet. Merge and review before relying on either in a real environment.

**What shadow mode records today.** Before this change, `shadow` mode computed a `CartValidation` on every model turn (`backend/app/Services/AIService.php`, `inspectScopedProposal()`) but nothing consumed it: both call sites that would read it (`LineWebhookResponseService::commerceGuardsOutput()` and `ProcessAggregatedMessages::checkoutProposal()`) are gated to `enforce`/`hold` only, so the validation was computed and discarded. The only thing shadow mode actually persisted anywhere was `App\Services\CommerceSafety\CustomerReplyGuard::generated()` writing unaggregated `Log::warning(...)` lines with a redacted reason code — not scoped to shadow specifically (it also fires in enforce/hold), and not a report. `CheckoutAuthority`, `PaymentEffectDispatcher` and the whole payment/settlement pipeline never run in shadow mode at all (gated to `enforce`/`hold`), so no checkout-state, paid-hold or payment-effect signal existed to record.

**What was added.** A new additive table `commerce_safety_shadow_observations` (migration `backend/database/migrations/2026_09_16_000001_create_commerce_safety_shadow_observations_table.php`) and `App\Services\CommerceSafety\ShadowObservationRecorder`, wired into the one place shadow mode already computes something real: `AIService::generateResponse()`, right after `inspectScopedProposal()`. The recorder is a no-op unless `SafetyScope::mode($bot) === 'shadow'` (checked fresh on every call, nothing memoized), writes only fixed category/outcome/reason codes (e.g. `cart_proposal` / `valid`|`invalid` / `PRICE_MISMATCH`, `OUT_OF_STOCK`, …, sourced from `CanonicalCartValidator`'s existing enum-like error codes) — never customer text, contact details, slip images, account-delivery payloads or credentials — and never creates authoritative state or fires effects.

Report it with:

```sh
php artisan bot26:shadow-report --bot=26 --since=2026-09-01T00:00:00Z --until=2026-09-16T00:00:00Z --json=/path/to/report.json
```

Use explicit UTC timestamps (`Z` suffix) for `--since`/`--until` — the table stores UTC-naive timestamps and a bare local-looking date is ambiguous against the app's non-UTC timezone. Sample output (redacted, aggregate-only; `would_be_checkout_states`, `paid_hold_candidates`, `effect_intents` and `consent_mismatch_count` are structurally always 0/empty under current instrumentation — the report's own `notes` field explains why, per the CheckoutAuthority/PaymentEffectDispatcher gating above):

```json
{
    "bot_id": 26,
    "window": {"since": "2026-09-01T00:00:00+00:00", "until": "2026-09-16T00:00:00+00:00"},
    "data_source": "commerce_safety_shadow_observations (rows written only while commerce_safety.bots.26.mode was shadow at record time; ...)",
    "total_observations": 5,
    "decisions_by_outcome_reason": [
        {"category": "cart_proposal", "outcome": "valid", "reason": null, "count": 2},
        {"category": "cart_proposal", "outcome": "invalid", "reason": "PRICE_MISMATCH", "count": 1},
        {"category": "cart_proposal", "outcome": "invalid", "reason": "OUT_OF_STOCK", "count": 2}
    ],
    "cart_proposal_summary": {"valid": 2, "invalid": 3},
    "price_mismatch_count": 1,
    "stock_mismatch_count": 2,
    "consent_mismatch_count": 0,
    "would_be_checkout_states": [],
    "paid_hold_candidates": 0,
    "effect_intents": 0,
    "notes": ["..."]
}
```

**Hold kill switch.** `App\Services\CommerceSafety\HoldOverride` plus `SafetyScope::mode()` checking it first (see the checklist item above for the mechanism). Operate it with:

```sh
php artisan bot26:commerce-safety-hold --bot=26 --engage   # forces hold everywhere, immediately
php artisan bot26:commerce-safety-hold --bot=26 --status   # read back override state + resolved mode
php artisan bot26:commerce-safety-hold --bot=26 --release  # falls back to normal config-resolved mode
```

Run `--engage` on any one instance (web or any worker) — it writes to the default cache store (`config('cache.default')`), which every process reads fresh on every `SafetyScope::mode()` call, so no per-instance repetition is needed. **Prerequisite:** the default cache store must be a store genuinely shared across all processes (Redis or database — not `array`/local file) and reachable from every instance; this was not independently verified against the real deployed cache configuration. This does not replace the restart/config-cache-purge procedure for `off`→`shadow`→`enforce` transitions in the production sequence below — it only makes `hold` itself instantaneous.

### Reconcile staged outcomes

Use deterministic synthetic inbound IDs, provider transaction references, checkout IDs/revisions, verified-event IDs, Order IDs, effect IDs/retry keys and stock unit references. Compare committed rows against observed outbound attempts, including attempts that timed out.

| Case | Required evidence |
| --- | --- |
| Changed cart | One checkout row; revision increments exactly once for the edit (e.g. 1 → 2); old acceptances cannot authorize the new revision; required support/Terms stages are presented and accepted again. |
| Successful payment, duplicate/replay, same-proof manual/automatic race | One verified event and one Order for the same proof; one effect row per kind; at most one LINE receipt, Telegram payment notification and reservation for the single-unit scenario; no duplicate stock reservation. Separate genuine proofs must remain auditable even if a second proof is held. |
| Failed EasySlip and unverified images | Zero verified events, Orders and financial effect rows. An unreadable-slip admin diagnostic is not a payment notification. |
| Stock closes after money | Preserve proof and receipt; checkout `paid_hold`, event `manual_hold`; no Order, Telegram payment or reservation. A truthful LINE receipt effect may exist. |
| Telegram response lost | Exactly one `uncertain` Telegram effect; no blind resend or automatic requeue; LINE receipt and reservation remain independently traceable. |
| Isolation | No mutations or effects in another conversation/bot or any production destination. |

## Production preflight readback

After separate owner approval for production work, collect a redacted packet. Compare every value with the approved stage/release packet. Any drift stops the rollout before applying v28.

- Exact successful deployment SHA/status and image/build source; all expected migration **filenames and content hashes**, plus applied migration status. Use the release's real migration names: the C3 table migration is `2026_09_15_000002_create_prompt_deployments_table.php`, not the preliminary plan's name. Include all A/B migrations and the rest of the release inventory.
- Flow 24 belongs to bot 26, is undeleted/default, and `default_flow_id=24`; bot has no overriding `system_prompt`. Read active prompt MD5/SHA-256/character count, candidate hash and manifest hash, effective model `openai/gpt-5.6-luna`, reasoning `medium`, flow 24 → KB 7 linkage, `multiple_bubbles_enabled=true`, delimiter `|||`, Terms and configured contact allowlist.
- Canonical normal/VIP Page/G3D/Nolimit prices, availability, manual-off flags and stock snapshot unchanged from approval. No stock or price edits as part of rollout.
- Trusted payment plugin ownership, configured ID(s), enabled state and destination checks, without printing tokens or recipient secrets.
- `ORDER_PAYLOAD_ENABLED=true` and effective `delivery.order_payload_enabled=true`.
- Zero unresolved `checkout_sessions.state=paid_hold` and `verified_payment_events.disposition=manual_hold` for bot 26.
- Zero stale payment effects: no `running` claim older than **300 seconds**, no `uncertain`, no exhausted failure (five attempts), and no ready pending effect older than two scheduler intervals. Reconciliation is scheduled every minute, so that pending threshold is currently **120 seconds**.
- Every worker alive; Redis queues and database-fallback queue depths stable; no bot-26 jobs executing from an older deployment. Record queue names, depths, oldest age, worker build SHA/PID/start time and in-flight jobs.
- Both Redis and database `retry_after` values exceed the **160-second** worker timeout: configure and read back at least **200 seconds**, e.g. `REDIS_QUEUE_RETRY_AFTER=200` and `DB_QUEUE_RETRY_AFTER=200`. Repository defaults are 90 and are insufficient.
- Default cache store works; semantic cache reachable; exactly one scheduler executes `payment-effects:reconcile`. `/api/health` alone cannot establish these facts.
- Validated stop-ingress/stop-consumption procedure and atomic mode propagation SLA or shared runtime kill switch are ready. Do not infer this from a changed environment variable.

Useful inventory commands from the reviewed repository (hashes/status only):

```sh
git rev-parse HEAD
git status --porcelain
cd backend
php artisan migrate:status
php artisan schedule:list
```

Capture migration hashes from the reviewed source, then independently compare them to the deployed image. Never dump environment variables, prompt contents, customer conversations or payment records into the evidence packet. A new CLI process reading `config('commerce_safety.bots.26.mode')` does not prove existing workers loaded that mode.

## Correct production sequence

**off → readback → shadow (old prompt) → hold → C3 apply v28 → verify cache-miss hash → owner checkpoint → enforce → monitor**

1. **Deploy off.** Deploy the exact approved commit with mode `off`. Current Docker startup runs config/event/route caching, migrations, then the web process, scheduler and three workers (LLM, fast and database fallback). Verify actual topology before following it. Do not add a second scheduler. Keep bot-26 traffic/effect consumption contained as required by the approved propagation procedure.
2. **Read back.** Verify successful SHA, migrations, process set, per-instance configuration and health using the preflight packet. Smoke only non-scoped synthetic bots at this point.
3. **Shadow with old prompt.** Set mode `shadow` through the approved deployment configuration mechanism. Rebuild cached configuration and restart/redeploy **all** web, worker and scheduler processes; verify old instances are gone. Inspect the measured redacted shadow report (`php artisan bot26:shadow-report --bot=26 --since=... --until=...`; see "Shadow report and hold kill switch" above) and compare against reviewed acceptance criteria. **The facility exists on `feat/bot26-shadow-report` but is unreviewed and unexercised against real traffic; treat this step as still NO-GO until that review and a real run are done.**
4. **Enter hold before prompt mutation.** Stop bot-26 ingress and financial-effect consumption first, account for in-flight work, set mode `hold`, restart cached processes and confirm hold everywhere. Keep containment until the later owner checkpoint. Changing only the shell environment or using `queue:restart` alone is insufficient: an old worker can keep its cached config, and graceful restart can finish an in-flight job. C3 does not enforce this operational hold ordering for you. **Prefer `php artisan bot26:commerce-safety-hold --bot=26 --engage`** (see above) as the actual containment step — it is immediate and does not depend on any restart, so it removes the stale-worker race the paragraph above warns about; still confirm hold everywhere with `--status` against each live process before continuing.
5. **Prepare and apply v28 through C3.** Execute the commands below only in the approved environment, after all gates pass. Retain deployment UUID and safe command readback. Verify actual Flow 24 hash, flow-cache eviction/refill with the new hash and bot-26 semantic-cache purge. Stay in hold on any exception, failed status or incomplete cache verification.
6. **Verify a cache miss used v28.** Under containment, generate one dedicated synthetic nonfinancial response using the serving application path and candidate hash. Record request ID, instance/SHA, effective mode/model/reasoning, prompt hash at generation and actual cache-miss evidence; review final output for price/contact/marker leakage. C3 `--status` alone does not prove which prompt a generated answer used. This probe may write semantic cache or usage accounting and needs authorization as such. Do not submit real money or use a customer conversation.
7. **Owner checkpoint.** Present stage report, fresh production readbacks, deployment UUID/cache verification, synthetic response and generation hash evidence. Obtain the owner's explicit approval to enable all bot-26 traffic. Missing evidence or approval means remain contained in hold.
8. **Enforce and monitor.** Set mode `enforce`, restart/redeploy all cached processes and verify their SHA/mode before releasing ingress/effect consumption. There is no percentage canary: this enables **all bot-26 traffic**. Monitor continuously for **15 minutes**, hourly for **six hours**, and daily for **seven days**. Any rollback trigger starts containment immediately.

> **Do not apply v28 while serving customer traffic in `shadow`.** Shadow preserves legacy replies/plugins and does not enforce checkout or the scoped reply policy. Applying v28 there exposes the prompt before its safety boundary.

### Exact C3 commands and required outputs

Run from `backend/` in a verified repository-layout release. The candidate and its adjacent C3 manifest now live at `backend/resources/prompts/bot26/`, inside the backend Docker build context; the path below is relative to `backend/`. Check both files are packaged inside the allowed root.

```sh
php artisan bot:deploy-prompt 26 24 resources/prompts/bot26/v28.txt \
  --expected-current-md5=3f08720a6fb34f916561e5531119d5f1 \
  --expected-candidate-sha256=b88b8619faa911e2e76f92a08389c85dd695a02bc213bc272a93356bbdbd3473 \
  --actor='<approved-operator-id>' --prepare

php artisan bot:deploy-prompt --status='<deployment-uuid>'

php artisan bot:deploy-prompt --apply='<deployment-uuid>' \
  --actor='<approved-operator-id>' --force

php artisan bot:deploy-prompt --status='<deployment-uuid>'
```

Replace angle-bracket placeholders with the approved operator and UUID returned by prepare. Actions are mutually exclusive; `apply` and `status` take no bot/flow/path positional arguments. Every mutation needs `--actor`; production apply/rollback also requires `--force`. That flag is a CLI guard, **not owner approval**.

Prepare stores an encrypted prior-prompt backup/audit row; it does not mutate the Flow or touch either cache. Save `previous_sha256` for rollback. Require status `prepared`, unchanged source hash and unchanged mode before apply. `--status` reads DB/cache and can populate the flow cache; it is not a strictly write-free probe.

After apply require `status=applied`, null `failure_stage`/`last_error_code`, non-null `apply_cache_verified_at`, and both `flow_sha256` and `cached_flow_sha256` equal the candidate SHA-256 above. `semantic_count` must be **0 before the generation probe**. C3 invalidates `FlowCacheService::invalidateBot(26)` and `SemanticCacheService::clearForBot(26)` after commit; it then refills/checks the flow cache. Do not require the flow cache to stay empty after this verification.

A cache failure may occur **after the prompt transaction committed**: inspect status and hashes under hold. `status=failed` / `failure_stage=apply_cache` means failed activation, not proof that the old prompt remains. Fix the cache dependency, then retry the same `--apply` UUID under hold and verify again; do not make a replacement backup from the candidate or clear global caches. Third-party hash/manifest drift requires investigation, not bypassing C3 checks.

## Monitoring and final readback packet

Record deployment commit/status, migration state, exact flow and cached-flow hashes, cache-miss generation using the new hash, mode on every instance, one synthetic nonfinancial response, and measured metrics. A successful command without this readback is not completion.

Track wrong-price/false-success/unknown-contact/raw-marker or JSON output, unexpected suppression, duplicates per verified event, paid holds/manual holds, pending age, stale running claims, uncertain/exhausted effects, queue depth and oldest-job age, worker liveness and scheduler overlap. Compare receipt/Order/Telegram/reservation counts by event and checkout revision. Link anomalies to redacted IDs; keep private proof in its access-controlled source. Use the [rollback triggers and eight steps](bot26-commerce-safety-rollback.md) on any anomaly.

## Documented limitations and unverified assumptions

| Limitation | Operational consequence / gate |
| --- | --- |
| A plain `BOT26_COMMERCE_SAFETY_MODE` environment edit alone is still not immediate: production runs `php artisan config:cache` at container boot (`backend/Dockerfile`), which bakes `env()` into `bootstrap/cache/config.php` for the lifetime of every supervised process (php-fpm and all three queue workers share one container/process tree), so a changed env var only reaches a fresh boot. | **A shared runtime kill switch now exists**: `App\Services\CommerceSafety\HoldOverride`, checked by `SafetyScope::mode()` before config, operated via `php artisan bot26:commerce-safety-hold --engage`. It reads the default cache store fresh on every call (nothing memoized per-process), so it is immediate for `hold` specifically without a restart. Proven locally with PHPUnit only (see "Shadow report and hold kill switch" above) — not yet demonstrated against the real deployed cache store, and it does not make `off`/`shadow`/`enforce` transitions atomic; those still need the restart/config-cache-purge procedure. Do not claim instantaneous stopping from an environment edit alone. |
| A usable measured shadow-report facility did not exist in code; shadow mode computed a cart validation on every turn but nothing consumed or persisted it (see "What shadow mode records today" above for exact file:line evidence). | **Now implemented**: `commerce_safety_shadow_observations` (additive table) + `App\Services\CommerceSafety\ShadowObservationRecorder`, wired into `AIService::generateResponse()`, aggregated by `php artisan bot26:shadow-report`. It only covers what shadow mode actually computes today — cart-proposal validity and CanonicalCartValidator's reason codes; `would_be_checkout_states`, `paid_hold_candidates`, `effect_intents` and `consent_mismatch_count` are structurally 0/empty because `CheckoutAuthority`/`PaymentEffectDispatcher` never run in shadow mode. Independently review and validate this facility, and run it against real staged `shadow` traffic, before satisfying the shadow gate; PHPUnit evidence is implementation proof, not that evidence. |
| `/api/health` gaps. | It does not probe Redis queue depth or worker liveness when Redis is default; it writes a temporary cache key. Collect queue/process evidence separately and describe its write accurately. |
| T17 nondeterminism. | Vision cannot reliably distinguish original bank-app images from camera photos. Canned classification tests prove response handling only. Resolve and measure this separately; do not mark image recognition green from PHPUnit. |
| No percentage canary. | `enforce` applies to all bot-26 traffic; only an isolated stage clone is a canary. |
| No deployed runtime fake-transport mode. | PHPUnit fakes cannot prove staged egress isolation; validate network policy/test accounts independently. |
| Cache-miss generation is not literally read-only. | Semantic caching/usage accounting may write state; obtain approval for the dedicated synthetic nonfinancial probe. |
| Live platform topology is unverified. | Repository state cannot prove Railway builder, replicas, live SHA, environment variables, egress or process overlap. No backend `railway.toml/json` is committed; historical Railway instructions conflict with bundled Docker topology. Verify live state after authorization. |
| Artifact packaging/manifest gap. | **Fixed locally, not yet independently reviewed.** v28 and its adjacent deployment manifest now live at `backend/resources/prompts/bot26/`, inside the Docker build context; a local `docker build`/`docker run sha256sum` proved packaging. Still requires the separate independently reviewed release/commit review before C3 prepare in a real environment. |

## C4 local verification record

This section records implementation evidence only, from the baseline plus the three C4 files. It does not authorize environment activation or close the open gates above.

- 2026-09-15, PHP 8.5.2 / PHPUnit 12.5.33, SQLite `:memory:`: `./vendor/bin/phpunit tests/Feature/CommerceSafety/Bot26EndToEndTest.php --no-coverage`.
- **RED:** 8 tests, 0 assertions, 8 missing-class errors for test-local `Tests\Feature\CommerceSafety\Bot26TransportFake`, before its implementation. Existing production classes were already present; this RED is test-harness evidence, not a newly fixed production defect. Output: `/tmp/bot26-c4-red.txt` (local, uncommitted).
- **GREEN:** 8 tests, 536 assertions; success. Output: `/tmp/bot26-c4-green.txt` (local, uncommitted). Covers normal and VIP journeys, exact cart revision change and consent, trusted automatic/manual payment, duplicate slip/output replay, failed/unverified images, stock closure after money, uncertain Telegram and independent effects, with synthetic HTTP and an isolated stock adapter/database. Does not prove concurrent worker races, ingress signature/dedup middleware, actual vision recognition, live raw inference or stage egress.
- **Full backend suite — FAILED / ABORTED, exit 2:** ran `./vendor/bin/phpunit --no-coverage` from `backend/` **once**. PHPUnit discovered 2,320 cases. Before abort, stdout recorded 824 completed progress markers: 787 ordinary passes (`.`), 22 notice-marked cases (`N`), 15 skips (`S`), and 0 assertion-failure/error markers (`F`/`E`). These are observed progress counts, not a completed-suite summary. PHP exhausted its 134,217,728-byte (128 MiB) memory limit while bootstrapping `Tests\Unit\Services\LineWebhook\LineWebhookResponseServiceTest::test_scoped_alternate_replies_are_guarded_before_persistence#40` (data: `cached-vision`, `off`, `@adsvance`); allocation failed in `backend/routes/console.php:62`. No final assertion/pass/fail totals were produced, and the remaining cases were not completed. Output: `/tmp/bot26-c4-full-backend.txt` (local, uncommitted). No retry or memory-limit workaround was performed. This records the required full-suite invocation honestly; complete-suite acceptance remains unverified and requires follow-up approval/work.
- **Full backend suite — PASSES (2026-09-18, exit 0).** The abort above was a 128 MiB PHP memory limit, not a failing suite. Re-ran the same command with `php -d memory_limit=2G vendor/bin/phpunit --no-coverage` from `backend/` on the branch carrying the T25 contact-allowlist fix, the T26 off-topic fix and the shadow-mode pre-flight fixes: **2,374 tests, 14,020 assertions, 0 failures, 0 errors, 78 PHPUnit notices, 62 skipped** (the 38 opt-in raw-inference cases plus 24 pre-existing skips). This closes the checklist item that required a recorded complete-suite result; the notices are pre-existing and unrelated to these changes.
- **Frontend:** skipped; B-series did not change the admin confirmation contract, and C4 changes no frontend files.
- **Formatting:** Pint on the changed PHP file passed. `git diff --check` passed before commit.
- No production access, staging activation, push or `.superpowers` artifact changes were performed for C4.
