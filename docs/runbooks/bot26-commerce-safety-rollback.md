# Bot 26 commerce safety: containment and rollback

## Scope and triggers

**Documentation only; executing this runbook requires separate owner approval.** Approving C4 implementation does not approve production access, payment actions, deployments or customer messages. Before rollout, the owner must approve the incident containment procedure and who can invoke it when a trigger fires. Obtain fresh owner approval before returning to serving mode.

Follow the authoritative C4 preflight ruling and the [rollout gates](bot26-commerce-safety-rollout.md). Keep a safety-capable backend deployed throughout recovery. Prompt rollback does not undo money received, revoke sent notifications, cancel Orders or release reserved stock.

Start containment on **any** of these Task 4 triggers:

- Wrong price, including incorrect normal/VIP price or cart total.
- False payment success or fulfillment promise unsupported by trusted proof/state.
- Unknown/invented contact or an unallowlisted destination.
- Customer-visible raw marker, internal JSON or ORDER/OFFTOPIC payload.
- Duplicate Order, notification or stock reservation.
- Unexpected reply suppression.

Also stop activation on failed cache verification, wrong deployment/hash/mode readback, unresolved hold/uncertain-effect findings, or inability to prove worker containment. If there is no usable propagation mechanism, activation must already be blocked; an incident is not the time to assume changing an environment variable instantly stops work.

## Eight-step rollback ruling

1. **Stop bot-26 ingress and effect consumption.** Use the owner-approved, stage-rehearsed platform containment mechanism before changing mode or prompt. Suspend new bot-26 webhook work, delayed replies, financial consumers, retries and relevant reconciliation dispatch. Account for both Redis workers and database-fallback consumers, existing queued jobs and in-flight transports. Preserve incoming webhook events for controlled reconciliation; do not delete queues or lose receipts. The repository has no scoped instant queue-stop command: document the actual routing/process controls and any broader impact in the approved incident procedure. Do not improvise a platform command from historical Railway instructions.
2. **Set `hold` and verify every process.** Set `BOT26_COMMERCE_SAFETY_MODE=hold` through the deployment configuration mechanism, rebuild boot-cached configuration and restart/redeploy all web/worker/scheduler processes. Read back each instance's mode, SHA, PID/start time and outstanding jobs; verify old processes are gone. Keep ingress/consumers stopped until this is proven. An environment edit or one CLI config readback is insufficient; `queue:restart` is graceful and does not by itself rebuild cached config or stop a running financial call. Record the actual propagation interval and unresolved in-flight attempts.
3. **Preserve proof and audit state.** Retain receipts, slip verifications, verified incoming-money events, checkout IDs/revisions/consent rows, effect claims/attempts/retry keys, Orders/items, deliveries/reservation references and prompt deployment audit rows. Keep encrypted prompt backups decryptable with the approved application key handling. Capture safe identifiers, timestamps, counts and hashes in the incident packet; do not export customer messages, real slips, credentials, recipient configuration or prompt bytes into repository artifacts.
4. **If prompt-related, roll back by C3 UUID with the candidate-hash precondition.** Inspect the recorded deployment using the commands below. Require the current flow hash to equal the deployment's candidate hash before the first rollback. Use the encrypted prior bytes held by that audit row, not a copied prompt or direct SQL. If a third party edited the prompt, C3 refuses rollback: stay contained in hold, preserve the unexpected hash and obtain an independently reviewed recovery decision. For a non-prompt incident, retain the prompt and proceed with reconciliation; restoring old text cannot repair payment/effect defects.
5. **Verify exact old prompt and both caches.** Require `status=rolled_back`, non-null `rollback_cache_verified_at`, null failure fields, and `flow_sha256=cached_flow_sha256=previous_sha256`. Compare `flow_md5` with the recorded prior MD5 (for the original source, `3f08720a6fb34f916561e5531119d5f1`). Require `semantic_count=0` before any authorized probe. C3 verifies decrypted backup bytes before restoring them and invalidates both FlowCache and bot-26 SemanticCache after commit. A failed cache step can leave the old prompt already restored: remain in hold and retry the **same rollback UUID** after fixing the dependency, then read back again. Do not globally flush other bots' caches.
6. **Reconcile holds and uncertain work.** Follow the reconciliation checklist below for `paid_hold`, event `manual_hold`, pending/failed/running effects, Orders and stock references. A trusted receipt remains proof even when no Order can safely be created. Telegram timeout is an **uncertain** send, not evidence of non-delivery: verify destination evidence before any decision and never blindly resend. Do not mark an effect pending or manufacture a new event merely to retry it. Keep potentially conflicting automatic consumers contained during manual reconciliation.
7. **Preserve forward-only production schema and safety support.** Never run production down migrations, `migrate:rollback`, `migrate:reset`, `migrate:fresh`, drop audit tables, or deploy a pre-safety image lacking `hold`. Use reviewed forward migrations or a reviewed safety-capable image for fixes. Migration up/down exercises belong only on an empty disposable database, with PostgreSQL evidence required by the rollout gate. An application rollback does not authorize a destructive schema rollback.
8. **Return to `enforce` or `off` only after reconciliation and fresh owner approval.** Satisfy every return condition below and the applicable rollout gates. Restart/redeploy cached processes and verify mode on each instance before resuming ingress/consumption. `off` restores legacy behavior; it is not a containment mode and must not silently resume financial traffic. Monitor using the rollout schedule and retain the incident packet.

## Exact C3 status and rollback commands

Run from the approved release's `backend/`, under confirmed hold and containment. Replace placeholders with the incident's deployment UUID and approved operator. `--force` is required for production mutation; it is not a substitute for owner approval.

```sh
php artisan bot:deploy-prompt --status='<deployment-uuid>'

php artisan bot:deploy-prompt --rollback='<deployment-uuid>' \
  --expected-current-sha256=041781b417a842d1213de53da76d4690b16dd58a6635d27f0011c5f817b11c1f \
  --actor='<approved-operator-id>' --force

php artisan bot:deploy-prompt --status='<deployment-uuid>'
```

Use exactly one action per invocation. Do not pass bot/flow/path or prepare-only hash options to `rollback`/`status`. The expected-current SHA above is v28's candidate hash; if the selected audit row is for a different approved version, use its recorded candidate hash after verifying the incident scope. Do not replace this precondition with an arbitrary current hash to force an overwrite.

C3 rollback requires a previously applied deployment. On a retry after a rollback cache failure, continue passing the **candidate** SHA as required by the CLI; the service detects that bytes were already restored and verifies the prior hash before retrying caches. Keep the same UUID. After successful rollback, that candidate's deployment cannot simply be prepared/applied again: C3 rejects rolled-back candidates and retains the unique `(flow_id, candidate_sha256)` audit key. Returning to v28 needs a separately reviewed redeployment strategy; never delete/reset the audit row to bypass this rule.

The status command may populate the flow cache. It reports hashes and semantic row count, not prompt bytes, current runtime mode, worker liveness or actual generation provenance. Supplement it with per-process readbacks and, only when approved, a dedicated synthetic nonfinancial cache-miss probe. This probe may write usage/cache state; do not describe it as read-only.

## Reconciliation checklist

Keep one incident ledger keyed by bot 26, conversation, checkout/revision, provider transaction/event, receipt, Order, effect and stock unit reference. Reconcile attempts, not merely successful responses. Do not copy private payloads into the ledger.

- [ ] Inventory every in-flight job at containment, including old-deployment workers, delayed LINE bubbles, EasySlip retries, Redis consumers, database fallback and scheduler dispatches. Establish which operations committed before the stop.
- [ ] For each verified event, compare the immutable proof/source/amount/recipient validation with its persisted receipt, checkout binding and disposition. Preserve all distinct genuine incoming-money proofs; do not deduplicate unrelated transfers just because the cart or amount matches.
- [ ] For changed carts, verify one checkout row has the expected revision increment, canonical product IDs/normal or VIP amounts and only current-revision acceptances after presented challenges. Model prose is not consent or payment authority.
- [ ] For one successful proof or its duplicate/replay, reconcile one event, one Order, one effect row per kind and no duplicate reservation. Different proofs racing for the same checkout may require an additional held event; only one settlement/Order is allowed and neither proof may be discarded.
- [ ] For failed verification or unverified image cases, require zero verified events/Orders/financial effects. Distinguish a diagnostic alert from a Telegram payment notification. Any financial side effect here is an incident finding.
- [ ] For money received after stock closure, retain proof plus truthful receipt, `paid_hold` and `manual_hold`; require no Order, Telegram payment or reservation until an explicitly approved resolution. Do not force stock availability or alter prices to make settlement pass.
- [ ] For every effect, compare state, attempt count, claim token/time, transport-start marker, next attempt, remote ID and retry key. A `running` claim older than 300 seconds is stale; an exhausted failure has five attempts. Investigate ready pending work older than two one-minute scheduler intervals.
- [ ] For uncertain Telegram, inspect the actual destination through approved access and identify whether the message exists. Reconciliation deliberately does not automatically resend `uncertain`. An unobserved acknowledgement cannot prove non-delivery. Keep uncertain work held until an owner-approved, audited resolution; there is no documented generic resend/reset command to run here.
- [ ] For stock, compare each persisted delivery item and exact `order_ref`/unit key with the isolated external reservation record before any reserve/release/resend decision. Never blindly repeat a reservation after an ambiguous result.
- [ ] Check LINE receipt retry keys and remote acknowledgements separately from Telegram and stock: failure in one effect must not duplicate the other effects. A LINE retry after a transport failure can use the same durable retry key; do not infer general at-most-one network attempt guarantees beyond the successful/duplicate test scenario.
- [ ] Record unresolved cases with an accountable owner and approved resolution. Preserve evidence when the resolution requires separate customer/payment action. This runbook does not authorize arbitrary refunds, messages or fulfillment.

`php artisan payment-effects:reconcile` is **not a read-only report**: it may queue pending/failed work and repair stale claims. Execute only under the approved containment/recovery plan after confirming which consumers can run. Use read-only database inspection for the initial inventory. Never use a scheduler run as a substitute for reviewing uncertain effects.

## Conditions for return to enforce

All must pass; elapsed time and a successful rollback command are insufficient.

- [ ] Root cause identified, corrected in an exact reviewed safety-capable release and independently reviewed; required security, pricing, scoped-behavior and dependency checks have no unresolved P0/P1.
- [ ] All affected proofs, Orders, stock and external notifications reconciled; zero unresolved bot-26 `paid_hold`/`manual_hold`, zero uncertain/exhausted effects, no stale running claim and no overdue ready pending effect.
- [ ] Fresh stage packet passes required failed/success/manual race/duplicate/cart-change/stock-change/ambiguous Telegram/image cases. Raw v28 acceptance and T17 recognition are established separately from replay fakes.
- [ ] Prompt/version chosen explicitly, exact restored or newly approved hash proven, both caches verified and an approved nonfinancial cache-miss generation demonstrably uses that hash. If targeting a rolled-back candidate, resolve C3's forward redeployment limitation through review first.
- [ ] Fresh production readback matches the [rollout preflight](bot26-commerce-safety-rollout.md): SHA/migrations/model/reasoning/KB/delimiter/prices/stock/plugins, effective configuration, working caches, stable Redis/database queue depths, live workers, no old jobs and one scheduler. Both queue `retry_after` values are at least 200 seconds for 160-second workers.
- [ ] Hold propagation/kill mechanism and ingress/effect containment are demonstrated; no stale cached process remains. A measured shadow report is available and reviewed where the rollout requires it.
- [ ] Owner explicitly approves the destination mode and resumption scope after reviewing the incident packet. For `off`, document acceptance of legacy behavior; for `enforce`, acknowledge all bot-26 traffic is enabled because no percentage canary exists.
- [ ] After resumption, verify every instance again and monitor continuously for 15 minutes, hourly for six hours and daily for seven days, with the same rollback triggers.

## Limitations retained during recovery

The rollout's limitations also apply here: immediate hold cannot be promised with boot-cached environment configuration; no usable shadow-report facility exists; `/api/health` writes a cache key and does not prove Redis queue depth/worker liveness; actual T17 classification is nondeterministic; there is no percentage canary or deployed fake-transport mode. Live Railway builder/replicas/egress/process overlap are unverified from source. The candidate's adjacent C3 deployment manifest and its container packaging are unresolved at baseline `645b3868`. None of these assumptions may be converted into a checked gate by this document.
