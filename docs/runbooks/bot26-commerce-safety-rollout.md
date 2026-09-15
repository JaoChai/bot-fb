# Bot 26 commerce safety: staged activation and production rollout

## Authority and current decision

**Documentation only. NO-GO for staging activation or production rollout until every applicable gate below is evidenced and the owner separately approves execution.** C4 implementation, passing PHPUnit fakes, and approval of C1–C3 are not activation approval.

This runbook implements the authoritative `task-4-preflight-final.txt` ruling supplied for C4, correcting the production order in [Task 4](../superpowers/plans/2026-09-14-bot26-03-reply-quality-rollout.md). The preflight's original NO-GO at `30fd4dc8` was historical; re-evaluate every gate against the exact later reviewed release commit. Use the [rollback runbook](bot26-commerce-safety-rollback.md) for containment and recovery.

Implementation baseline: `645b386824ebff711b19e535ec4f12897fe970ba`. Known open gates at this baseline:

- [C2 evidence](../testing/bot26-v28-evaluation.md) records **raw inference unexecuted**, T17 classification blocked, and application semantic gaps. Historical replay success is not 38/38 reviewed raw-text acceptance for v28.
- The actual candidate is at repository-root `resources/prompts/bot26/v28.txt`. Its required adjacent **`v28.manifest.json` is absent**. The evaluation inventory under `backend/tests/Fixtures/PromptEval/bot26-v28/manifest.json` is not the C3 deployment manifest. C3 deliberately rejects that alternative schema. A separately reviewed release must supply the measured nested C3 manifest; do not improvise one during deployment.
- C3 resolves artifacts beneath `prompt_deployment.artifact_root`, defaulting to the repository's `resources/prompts/bot26`. The backend Dockerfile copies a backend build context into `/var/www/html`; repository-root resources cannot be assumed present in that image. Prove the builder, packaged paths and configured artifact root satisfy C3's containment checks before activation.
- Immediate hold propagation and a usable measured shadow report are not established. See limitations below. Full-suite execution evidence is recorded below; failures do not disappear because the command ran.

## Required nonproduction gate checklist

Record evidence IDs, timestamps, exact commit and reviewer/owner for every box. A missing, failed or unverified box is NO-GO; do not silently waive it.

- [ ] One clean, independently reviewed commit contains C1–C4, the v28 artifact and its valid C3 deployment manifest together.
- [ ] Measure candidate: 23,133 Unicode characters, SHA-256 `b5d8815cd45626949482f26f5c2f0942371b5940a3ccd0f5f810379687b1fa24`. Active-flow source: 41,100 Unicode characters, MD5 `3f08720a6fb34f916561e5531119d5f1`. Manifest measurements match actual bytes.
- [ ] Security review of payment provenance, pricing review of canonical normal/VIP Page/G3D/Nolimit matrix, scoped-behavior review and dependency audit have no unresolved P0/P1 findings. A1–A3, B1–B3 and C1–C3 required checks and independent reviews are green.
- [ ] C4 RED/GREEN, the complete backend suite result and applicable frontend checks are recorded. Resolve release-blocking suite findings; recording a failed suite closes execution evidence, not acceptance.
- [ ] Migration up/down evidence exists on an **empty disposable PostgreSQL database only**, including safety/audit migrations and rollback proof. SQLite tests are not PostgreSQL concurrency or migration proof. No production down migration.
- [ ] Stage has separate database, cache/Redis, queues, storage and stock database. It contains no production DSN, LINE token, Telegram token/chat, EasySlip credential, customer history or fulfillment account.
- [ ] Egress controls or dedicated test accounts make production Telegram, stock and fulfillment destinations unreachable. Test the isolation independently of PHPUnit fakes.
- [ ] Synthetic staged bot retains ID **26**, flow ID **24**, and a trusted enabled payment plugin owned by that flow matching configured IDs (currently plugin **1**). A clone with a new bot ID silently becomes unscoped.
- [ ] Exactly one scheduler runs across the environment. The Docker image already embeds one; additional replicas/schedulers must not duplicate it.
- [ ] Deploy initially with `BOT26_COMMERCE_SAFETY_MODE=off`. Read back deployment SHA/status, image/build source, packaged artifact path, migration status and runtime mode from **every** web/worker instance.
- [ ] Establish the containment mechanism and demonstrate mode propagation with no stale worker overlap; meet the hold prerequisite below.
- [ ] Switch isolated stage to `shadow` using the old prompt, restart every config-cached process and read back mode. Run all **41/41 application cases** and **38/38 reviewed raw-text cases** with the exact candidate, model and reasoning in the isolated evaluation harness. Do not expose v28 through shadow customer serving.
- [ ] Produce the measured, aggregate, redacted shadow report through an approved and validated facility. The current code does not provide one; absence blocks this box.
- [ ] Confirm `hold`, then rehearse C3 prepare/apply/cache verification and synthetic generation before switching only the isolated staged bot to `enforce`. Rerun paid success/failure, manual/automatic race, duplicate, VIP, changed-cart, stock-change, ambiguous Telegram and T16/T17/T30 image cases. A canned T17 result does not close recognition acceptance.
- [ ] Rehearse `hold → prompt rollback by UUID → both cache invalidations → exact old hash → mode readback`, retaining proof and audit rows.
- [ ] Owner approves the measured stage packet before any production work.

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
3. **Shadow with old prompt.** Set mode `shadow` through the approved deployment configuration mechanism. Rebuild cached configuration and restart/redeploy **all** web, worker and scheduler processes; verify old instances are gone. Inspect the measured redacted shadow report and compare against reviewed acceptance criteria. **Current absence of that report facility is NO-GO.**
4. **Enter hold before prompt mutation.** Stop bot-26 ingress and financial-effect consumption first, account for in-flight work, set mode `hold`, restart cached processes and confirm hold everywhere. Keep containment until the later owner checkpoint. Changing only the shell environment or using `queue:restart` alone is insufficient: an old worker can keep its cached config, and graceful restart can finish an in-flight job. C3 does not enforce this operational hold ordering for you.
5. **Prepare and apply v28 through C3.** Execute the commands below only in the approved environment, after all gates pass. Retain deployment UUID and safe command readback. Verify actual Flow 24 hash, flow-cache eviction/refill with the new hash and bot-26 semantic-cache purge. Stay in hold on any exception, failed status or incomplete cache verification.
6. **Verify a cache miss used v28.** Under containment, generate one dedicated synthetic nonfinancial response using the serving application path and candidate hash. Record request ID, instance/SHA, effective mode/model/reasoning, prompt hash at generation and actual cache-miss evidence; review final output for price/contact/marker leakage. C3 `--status` alone does not prove which prompt a generated answer used. This probe may write semantic cache or usage accounting and needs authorization as such. Do not submit real money or use a customer conversation.
7. **Owner checkpoint.** Present stage report, fresh production readbacks, deployment UUID/cache verification, synthetic response and generation hash evidence. Obtain the owner's explicit approval to enable all bot-26 traffic. Missing evidence or approval means remain contained in hold.
8. **Enforce and monitor.** Set mode `enforce`, restart/redeploy all cached processes and verify their SHA/mode before releasing ingress/effect consumption. There is no percentage canary: this enables **all bot-26 traffic**. Monitor continuously for **15 minutes**, hourly for **six hours**, and daily for **seven days**. Any rollback trigger starts containment immediately.

> **Do not apply v28 while serving customer traffic in `shadow`.** Shadow preserves legacy replies/plugins and does not enforce checkout or the scoped reply policy. Applying v28 there exposes the prompt before its safety boundary.

### Exact C3 commands and required outputs

Run from `backend/` in a verified repository-layout release. The actual path is `../resources/prompts/bot26/v28.txt`; the brief's `backend/resources/...` location does not exist at the implementation baseline. Check both candidate and adjacent C3 manifest are packaged inside the allowed root. These commands will fail closed until the missing manifest is supplied by a reviewed release.

```sh
php artisan bot:deploy-prompt 26 24 ../resources/prompts/bot26/v28.txt \
  --expected-current-md5=3f08720a6fb34f916561e5531119d5f1 \
  --expected-candidate-sha256=b5d8815cd45626949482f26f5c2f0942371b5940a3ccd0f5f810379687b1fa24 \
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
| Immediate hold is not achievable with the boot-cached environment switch alone. | `BOT26_COMMERCE_SAFETY_MODE` needs restart/redeploy; stale workers may continue. Production activation requires a demonstrated atomic propagation SLA or shared runtime kill switch, plus a validated ingress/consumer containment procedure. Do not claim instantaneous stopping from an environment edit. |
| No usable measured shadow-report facility exists in current code. | Define, implement and independently validate the facility in separately reviewed work before satisfying the shadow gate. Logs or a fabricated report are not equivalent. |
| `/api/health` gaps. | It does not probe Redis queue depth or worker liveness when Redis is default; it writes a temporary cache key. Collect queue/process evidence separately and describe its write accurately. |
| T17 nondeterminism. | Vision cannot reliably distinguish original bank-app images from camera photos. Canned classification tests prove response handling only. Resolve and measure this separately; do not mark image recognition green from PHPUnit. |
| No percentage canary. | `enforce` applies to all bot-26 traffic; only an isolated stage clone is a canary. |
| No deployed runtime fake-transport mode. | PHPUnit fakes cannot prove staged egress isolation; validate network policy/test accounts independently. |
| Cache-miss generation is not literally read-only. | Semantic caching/usage accounting may write state; obtain approval for the dedicated synthetic nonfinancial probe. |
| Live platform topology is unverified. | Repository state cannot prove Railway builder, replicas, live SHA, environment variables, egress or process overlap. No backend `railway.toml/json` is committed; historical Railway instructions conflict with bundled Docker topology. Verify live state after authorization. |
| Artifact packaging/manifest gap. | Root v28 exists, adjacent deployment manifest does not; backend-only image may omit both. Resolve in a reviewed release before C3 prepare. |

## C4 local verification record

This section records implementation evidence only, from the baseline plus the three C4 files. It does not authorize environment activation or close the open gates above.

- 2026-09-15, PHP 8.5.2 / PHPUnit 12.5.33, SQLite `:memory:`: `./vendor/bin/phpunit tests/Feature/CommerceSafety/Bot26EndToEndTest.php --no-coverage`.
- **RED:** 8 tests, 0 assertions, 8 missing-class errors for test-local `Tests\Feature\CommerceSafety\Bot26TransportFake`, before its implementation. Existing production classes were already present; this RED is test-harness evidence, not a newly fixed production defect. Output: `/tmp/bot26-c4-red.txt` (local, uncommitted).
- **GREEN:** 8 tests, 536 assertions; success. Output: `/tmp/bot26-c4-green.txt` (local, uncommitted). Covers normal and VIP journeys, exact cart revision change and consent, trusted automatic/manual payment, duplicate slip/output replay, failed/unverified images, stock closure after money, uncertain Telegram and independent effects, with synthetic HTTP and an isolated stock adapter/database. Does not prove concurrent worker races, ingress signature/dedup middleware, actual vision recognition, live raw inference or stage egress.
- **Full backend suite — FAILED / ABORTED, exit 2:** ran `./vendor/bin/phpunit --no-coverage` from `backend/` **once**. PHPUnit discovered 2,320 cases. Before abort, stdout recorded 824 completed progress markers: 787 ordinary passes (`.`), 22 notice-marked cases (`N`), 15 skips (`S`), and 0 assertion-failure/error markers (`F`/`E`). These are observed progress counts, not a completed-suite summary. PHP exhausted its 134,217,728-byte (128 MiB) memory limit while bootstrapping `Tests\Unit\Services\LineWebhook\LineWebhookResponseServiceTest::test_scoped_alternate_replies_are_guarded_before_persistence#40` (data: `cached-vision`, `off`, `@adsvance`); allocation failed in `backend/routes/console.php:62`. No final assertion/pass/fail totals were produced, and the remaining cases were not completed. Output: `/tmp/bot26-c4-full-backend.txt` (local, uncommitted). No retry or memory-limit workaround was performed. This records the required full-suite invocation honestly; complete-suite acceptance remains unverified and requires follow-up approval/work.
- **Frontend:** skipped; B-series did not change the admin confirmation contract, and C4 changes no frontend files.
- **Formatting:** Pint on the changed PHP file passed. `git diff --check` passed before commit.
- No production access, staging activation, push or `.superpowers` artifact changes were performed for C4.
