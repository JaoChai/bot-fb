# Final Review Fix Wave Report

Date: 2026-09-22
Base: `15463460d79d9d47e4875d1a40848c927f53ddf9`

## Result

Implemented the complete final-review fix wave as one coherent change set. The existing three-argument `FlowPluginService::executePlugins()` API remains unchanged for legacy callers; queued aggregated responses use the new immutable snapshot entry point.

## Per-finding TDD evidence

### Finding 1 — Immutable plugin input

- **RED:** The pre-production focused run failed the new snapshot expectations because `ExecuteFlowPlugins` had no snapshot fields/entry point and the service had no snapshot method. The same RED run reported the new snapshot/dispatch tests as failures before production changes.
- **GREEN:** The final focused run passed `test_snapshot_is_immutable_after_dispatch` and `test_snapshot_uses_captured_flow_and_message_ids_in_captured_order`.
- **Implementation:** `ProcessAggregatedMessages` captures `conversation.current_flow_id ?? bot.default_flow_id` and the old synchronous query's five chronological message IDs immediately after delivery and before stats. `FlowPluginService::executePluginsSnapshot()` scopes the captured flow to the bot, loads only captured IDs scoped to the conversation, restores their captured order, and uses the shared plugin loop.

### Finding 2 — Duplicate plugin side effects

- **RED:** The pre-production run failed the timeout assertion (`90` observed versus required `75`) and failed the duplicate test because the message idempotency key was absent.
- **GREEN:** `ExecuteFlowPluginsTest` passed the 75-second timeout/retry-after assertions and `test_duplicate_delivery_for_the_same_message_executes_plugins_once`.
- **Implementation:** The job timeout is 75 seconds. `handle()` claims `flow_plugins:message:{messageId}` with atomic `Cache::add(..., 86400)` after record/scope validation and before plugin execution. Existing `$tries = 1` remains.

### Finding 3 — Never execute plugins inline on the sync queue

- **RED:** The pre-production run failed because `QueueRouter::asyncConnection()` did not exist. The sync/default integration expectation also failed before the routing change.
- **GREEN:** `QueueRouterConnectionTest` passed all five required routing cases. `test_plugin_execution_can_acquire_lock_after_parent_releases_it` passed after proving the queued job was not invoked inline and explicitly executing the captured job afterward.
- **Implementation:** Added `QueueRouter::asyncConnection()`: Redis-down routes to `database`; Redis-up uses the configured asynchronous default; `sync` routes to `database`; empty defaults to `redis`. Aggregated plugin dispatch uses this method.

### Finding 4 — Dispatch even when statistics fail

- **RED:** The pre-production integration run failed the new statistics/dispatch cases before the lock-release dispatch seam existed. The first combined run also exposed the SQLite transaction contamination caused by running these RefreshDatabase files together after earlier failures; the tests were then rerun independently during the GREEN cycle.
- **GREEN:** `test_statistics_failure_still_dispatches_plugins_and_propagates_original_exception` passed, proving dispatch occurs and the original statistics exception escapes. `test_dispatch_failure_does_not_replace_successful_response` passed using the focused dispatch seam.
- **Implementation:** Initializes `$botMessage` and `$pluginSnapshot` before guarded work. The snapshot is captured before `updateStats()`. The same `finally` block releases the response lock first, dispatches through `dispatchFlowPlugins()`, and catches dispatch failures without replacing the original exception. Delivery failures still produce no plugin dispatch.

### Deferred-minor fixes

- **Exception swallowing GREEN:** `test_plugin_exception_is_swallowed` passed; plugin exceptions are caught by `ExecuteFlowPlugins::handle()`.
- **Sanitized logging:** New job and parent dispatch catches log exception type/class and numeric code, not raw exception messages.
- **Dispatch-failure GREEN:** The focused seam test passed; no framework-internal facade forcing was needed.

## Design decisions

- At-most-once message-level claiming is intentional. A crash after the atomic claim can lose best-effort plugin work, but duplicate Telegram notifications and duplicate orders are the higher-risk failure.
- Legacy callers continue through `executePlugins()` and retain live-state flow/context behavior. Only the aggregated queued path uses immutable snapshot inputs.
- No Eloquent models are serialized into `ExecuteFlowPlugins`; only scalar IDs and snapshot scalar data are queued.
- No durable outbox, queue-worker, Redis/Neon, Railway, deployment, or unrelated webhook changes were introduced.

## Exact files modified

- `backend/app/Jobs/ExecuteFlowPlugins.php`
- `backend/app/Jobs/ProcessAggregatedMessages.php`
- `backend/app/Services/FlowPluginService.php`
- `backend/app/Support/QueueRouter.php`
- `backend/tests/Unit/Jobs/ExecuteFlowPluginsTest.php`
- `backend/tests/Unit/Jobs/ProcessAggregatedMessagesPluginDispatchTest.php`
- `backend/tests/Unit/QueueRouterConnectionTest.php`
- `backend/tests/Unit/Services/FlowPluginServiceTest.php`
- `docs/superpowers/specs/2026-09-22-aggregated-response-pipeline-design.md`
- `docs/superpowers/plans/2026-09-22-aggregated-response-pipeline.md`
- `.superpowers/sdd/2026-09-22-aggregated-response-pipeline/final-fix-report.md`

## Verification

Focused command:

```text
42 passed, 103 assertions, exit 0
```

Required focused set including delivery, plugin job, aggregated orchestration, dispatch, and FlowPluginService passed. QueueRouter connection tests also passed.

Direct full Pint:

```text
php vendor/laravel/pint/builds/pint --test
result: passed, exit 0
```

Full backend suite:

```text
composer test
Tests: 16 skipped, 1336 passed (3489 assertions)
Duration: 22.57s
exit 0
```

The baseline skip count of 16 was preserved.

## Self-review

- `git diff --check` passed.
- No new raw exception messages are emitted by the new ExecuteFlowPlugins or parent dispatch catches.
- Delivery, persistence, stats, broadcasts, fallback response, and failed-delivery behavior remain covered by existing/focused tests.
- Existing `executePlugins(Bot, Conversation, Message)` callers were not changed.
- The final commit is the single commit containing this complete fix wave; its SHA is supplied in the final task response.

## Unresolved concerns

- Best-effort dispatch remains non-durable by approved design; a queue outage or process failure after response delivery can still lose plugin work.
- Existing missing/mismatched-record warning noise remains and should be monitored.
- The at-most-once claim intentionally accepts loss after a worker crash following claim acquisition.
