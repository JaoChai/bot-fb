# Aggregated Response Pipeline Refactor — Design

**Date:** 2026-09-22
**Status:** Approved in chat; written-spec review required before implementation
**Path:** Architectural (existing customer-response subsystem)
**Base:** PR #278 head `4ed16df6` (`revert/bot26-commerce-safety`)

## 1. Problem

Production evidence collected on 2026-09-22 shows that HTTP ingress and Neon are not the primary bottlenecks. LINE webhook HTTP requests averaged about 78 ms in the sampled window, while AI-bound `ProcessAggregatedMessages` jobs commonly occupied a worker for 4–8 seconds and had observed outliers up to 21 seconds. The backend also has ample CPU and memory headroom.

After PR #278, `backend/app/Jobs/ProcessAggregatedMessages.php` is 488 lines and still coordinates all of these responsibilities:

- aggregation-cache validation;
- message identity and scope checks;
- bot/conversation state checks;
- per-conversation response locking and redispatch;
- AI generation;
- response persistence;
- LINE delivery selection (Flex, bubbles, plain text);
- synchronous flow-plugin evaluation and notification;
- statistics updates and broadcasts.

The highest-confidence avoidable delay is synchronous `FlowPluginService::executePlugins()` inside `generateAndDeliver()`, while the per-conversation response lock is still held. Plugin execution may perform another OpenRouter call, database queries, Telegram HTTP retries, and order recording after the customer reply has already been sent.

## 2. Goals

1. Release the per-conversation response lock immediately after the customer response is persisted and accepted by LINE delivery.
2. Move flow-plugin execution out of the aggregated-response critical path into a best-effort queued job.
3. Extract channel-delivery selection from the queue job into a focused, independently tested service.
4. Preserve all existing response text, Flex/bubble selection, persistence, statistics, broadcasts, fallback behavior, and plugin trigger semantics.
5. Keep the change isolated to the aggregated LINE response path; do not refactor other webhook paths in this initiative.

## 3. Non-goals

- Do not merge or deploy PR #278 as part of this work.
- Do not change RAG, OpenRouter model selection, prompts, reasoning effort, or semantic-cache behavior.
- Do not consolidate the three `ProcessLINEWebhook` execution paths.
- Do not change Railway variables, queue-split flags, worker replicas, or staged Railway changes.
- Do not modify payment, slip verification, stock reservation, account delivery, Facebook, or Telegram webhook behavior.
- Do not introduce a general event bus or configurable plugin framework.

## 4. Approaches Considered

### A. Refactor `ProcessLINEWebhook` first

Split the 957-line post-revert job and consolidate legacy/pipeline paths.

- **Benefit:** Largest structural cleanup.
- **Cost:** High effort and high production risk on the primary ingress path.
- **Decision:** Deferred. The current HTTP ingress is already fast, and production pipeline soak is not proven complete.

### B. Extract aggregation snapshot only

Move validation and state reads into a value object/builder while keeping delivery and plugins synchronous.

- **Benefit:** Cleaner job and a small reduction in repeated reads.
- **Cost:** Limited effect on the measured multi-second worker occupancy.
- **Decision:** Not selected as the first slice. It may be reconsidered after stage timing data shows a meaningful read-side cost.

### C. Shorten the post-response critical path — selected

Extract delivery selection and queue plugin processing after the response lock is released.

- **Benefit:** Removes an avoidable LLM/Telegram/DB workload from the locked customer-response path and gives the extracted delivery logic direct tests.
- **Cost:** Adds one focused job and one focused service.
- **Decision:** Recommended because it offers the best impact-to-risk ratio without changing AI or payment behavior.

## 5. Architecture

### Before

```text
ProcessAggregatedMessages
  validate aggregation
  acquire response lock
  generate AI response
  persist bot message
  select Flex / bubbles / plain text
  send LINE response
  run FlowPluginService synchronously
    optional OpenRouter call
    optional Telegram HTTP retries
    optional order recording
  update stats
  release response lock
  broadcast
```

### After

```text
ProcessAggregatedMessages
  validate aggregation
  acquire response lock
  generate AI response
  persist bot message
  AggregatedMessageDeliveryService::deliver(...)
  update stats
  release response lock
  dispatch ExecuteFlowPlugins job
  broadcast

ExecuteFlowPlugins (llm queue, best effort)
  reload Bot / Conversation / Message by ID
  verify that the message belongs to the conversation and bot
  FlowPluginService::executePlugins(...)
```

## 6. Components

### 6.1 `AggregatedMessageDeliveryService`

**New file:** `backend/app/Services/Chat/AggregatedMessageDeliveryService.php`

Responsibility: choose and execute exactly one existing LINE delivery mode for an already-persisted bot message.

Interface:

```php
public function deliver(
    Bot $bot,
    Conversation $conversation,
    Message $botMessage,
    string $externalUserId,
): void;
```

Dependencies are constructor-injected:

- `LINEService`
- `MultipleBubblesService`
- `PaymentFlexService`

Behavior remains verbatim from the current `ProcessAggregatedMessages::deliverToChannel()`:

1. `PaymentFlexService::tryConvertToFlex()`
2. Flex match → LINE push with generated retry key
3. Otherwise, bubbles enabled → `sendBubbles()`
4. Otherwise → plain-text LINE push with generated retry key

The service does not persist messages, run plugins, update stats, broadcast, or catch delivery failures.

### 6.2 `ExecuteFlowPlugins`

**New file:** `backend/app/Jobs/ExecuteFlowPlugins.php`

The job receives scalar IDs only:

```php
public function __construct(
    public int $botId,
    public int $conversationId,
    public int $messageId,
) {}
```

Execution rules:

- implements `ShouldQueue`;
- uses `QueueRouter::connection()` for Redis/database fallback;
- is explicitly routed to `QueueRouter::QUEUE_LLM`, because plugin evaluation can call OpenRouter;
- `$tries = 1`, preserving the current best-effort behavior and avoiding Telegram/order duplication from queue retries;
- `$timeout = 90` seconds, leaving headroom for the existing 15-second OpenRouter evaluation plus Telegram HTTP retries while staying below the 160-second LLM-worker ceiling;
- reloads the three records and returns safely when any is missing;
- validates `conversation.bot_id === bot.id` and `message.conversation_id === conversation.id` before executing plugins;
- delegates to `FlowPluginService::executePlugins()`;
- catches unexpected exceptions and logs IDs plus the error, never message content or credentials.

The service’s existing per-plugin 60-second cache guard remains unchanged.

### 6.3 `ProcessAggregatedMessages`

Changes are surgical:

- inject `AggregatedMessageDeliveryService` into `handle()` and pass it through the existing private methods;
- remove direct `LINEService`, `MultipleBubblesService`, `PaymentFlexService`, and `FlowPluginService` orchestration from the job where no longer needed;
- `generateAndDeliver()` persists the bot message and delegates delivery to the new service;
- it no longer executes plugins;
- after the `finally` block releases the response lock, dispatch `ExecuteFlowPlugins` for a successfully persisted bot message;
- retain the existing ordering of aggregation cleanup, statistics, and broadcasts unless a test proves an ordering dependency requires the dispatch to move one line later.

## 7. Data and Error Flow

### Successful response

1. AI result generated.
2. Bot message persisted.
3. LINE delivery succeeds.
4. Conversation/bot statistics update.
5. Response lock releases.
6. Plugin job is queued.
7. Realtime events broadcast.
8. Plugin job evaluates triggers independently.

### AI failure with friendly fallback

The existing friendly fallback message is persisted and delivered. Because the previous implementation also ran plugins for the fallback message, the plugin job is dispatched for it as well.

### LINE delivery failure

The existing exception behavior remains: the parent job fails before post-response work is dispatched. No plugin runs for a response that was not accepted by the delivery path.

### Plugin failure

The plugin job logs and completes without retry. The customer reply, message persistence, statistics, and broadcast are unaffected.

### Missing or mismatched records

The plugin job logs a warning and returns without calling the plugin service.

## 8. Testing Strategy

TDD is mandatory: every production change begins with a failing test and a verified RED result.

### Delivery service tests

Create `backend/tests/Unit/Services/Chat/AggregatedMessageDeliveryServiceTest.php`:

- Flex result sends exactly one LINE push and does not check bubbles.
- Bubble-enabled text calls `sendBubbles()` and does not push plain text.
- Plain text generates a retry key and calls LINE push.
- Delivery exceptions propagate to the parent job.

### Plugin job tests

Create `backend/tests/Unit/Jobs/ExecuteFlowPluginsTest.php`:

- valid IDs call `FlowPluginService::executePlugins()` once;
- missing bot, conversation, or message exits safely;
- cross-bot conversation is rejected;
- message from another conversation is rejected;
- the job declares one attempt, a 90-second timeout, and the `llm` queue.

### Aggregated job characterization

Add focused tests proving:

- successful and fallback bot messages dispatch `ExecuteFlowPlugins`;
- early exits and failed delivery do not dispatch it;
- `FlowPluginService` is not called synchronously by `ProcessAggregatedMessages`;
- existing message persistence, stats, and broadcast behavior remains intact.

### Verification commands

```bash
cd backend
php artisan test tests/Unit/Services/Chat/AggregatedMessageDeliveryServiceTest.php
php artisan test tests/Unit/Jobs/ExecuteFlowPluginsTest.php
php artisan test --filter=ProcessAggregatedMessages
vendor/bin/pint --test
composer test
```

Baseline on the PR #278 worktree before implementation:

```text
1311 passed, 16 skipped, 3434 assertions
```

## 9. Rollout and Measurement

This branch is stacked on PR #278 and must not merge before PR #278 is resolved. After PR #278 merges, rebase onto the resulting `main`, rerun the full suite, and open a separate PR.

Post-deploy checks:

- no increase in failed `ProcessAggregatedMessages` jobs;
- plugin-job failures and missing-record warnings remain zero or explained;
- no duplicate Telegram plugin notifications or duplicate plugin-created orders;
- compare LLM-worker busy time and response-lock contention against the pre-deploy window;
- verify customer reply content and LINE delivery mode are unchanged.

Rollback is a normal git revert of the refactor PR. No schema, data, prompt, or environment-variable changes are included.

## 10. Risks

1. **Plugin ordering changes:** plugins run after lock release and may complete after broadcasts. Mitigation: plugin inputs are persisted IDs, and no customer response depends on plugin completion.
2. **Queue outage:** `QueueRouter::connection()` preserves the existing Redis-to-database fallback. The customer reply remains successful even if plugin dispatch fails; log the dispatch failure.
3. **Duplicate side effects:** the job uses one attempt and the existing plugin cache guard. No automatic job retries are introduced.
4. **Stacked-branch drift:** PR #278 may change before merge. Mitigation: rebase and run the complete suite before opening the refactor PR.

## 11. Acceptance Criteria

- `ProcessAggregatedMessages` no longer calls `FlowPluginService::executePlugins()` directly.
- The response lock is released before `ExecuteFlowPlugins` is dispatched.
- Flex, bubble, and plain-text delivery behavior is unchanged and directly unit tested.
- Plugin processing uses scalar IDs, validates ownership boundaries, and runs on the `llm` queue with one attempt.
- No files outside the aggregated-response path, its focused tests, and this documentation are changed.
- Pint passes.
- Full backend suite passes with no new skips or warnings relative to the clean baseline.
