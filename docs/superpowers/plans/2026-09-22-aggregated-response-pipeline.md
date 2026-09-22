# Aggregated Response Pipeline Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Shorten the aggregated LINE response critical path by extracting delivery selection and running flow plugins in a best-effort queued job after the response lock is released.

**Architecture:** `ProcessAggregatedMessages` remains the orchestration entry point but delegates Flex/bubble/plain delivery to `AggregatedMessageDeliveryService`. After a persisted response is delivered, statistics are updated and the response lock is released; only then does the job dispatch `ExecuteFlowPlugins`, which reloads scoped records and runs the existing plugin service on the `llm` queue.

**Tech Stack:** PHP 8.4, Laravel 13, Pest/PHPUnit 12, Mockery, Laravel Queue/Cache fakes.

**Spec:** `docs/superpowers/specs/2026-09-22-aggregated-response-pipeline-design.md`

## Global Constraints

- Base all work on PR #278 head `4ed16df6`; do not merge or deploy PR #278.
- Use TDD for every production change: write the test, run it and observe the expected failure, then write minimal production code.
- Preserve response text, persistence, Flex/bubble/plain selection, fallback behavior, statistics, and broadcasts.
- Do not change RAG, prompts, OpenRouter selection, Railway variables, queue-split flags, payment, slip verification, delivery, Facebook, or Telegram webhook behavior.
- `ExecuteFlowPlugins` receives scalar IDs, uses one attempt, has a 90-second timeout, and runs on `QueueRouter::QUEUE_LLM`.
- Plugin execution must occur after the per-conversation response lock is released.
- Do not log message bodies, plugin credentials, tokens, or customer data.
- Keep changes confined to the aggregated-response job, one delivery service, one plugin job, focused tests, and this documentation.

---

### Task 1: Extract aggregated LINE delivery selection

**Files:**
- Create: `backend/tests/Unit/Services/Chat/AggregatedMessageDeliveryServiceTest.php`
- Create: `backend/app/Services/Chat/AggregatedMessageDeliveryService.php`

**Interfaces:**
- Consumes: `LINEService`, `MultipleBubblesService`, `PaymentFlexService`
- Produces:
  ```php
  public function deliver(
      Bot $bot,
      Conversation $conversation,
      Message $botMessage,
      string $externalUserId,
  ): void;
  ```

- [ ] **Step 1: Write failing delivery service tests**

Create the test class with four behaviors:

```php
<?php

namespace Tests\Unit\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\AggregatedMessageDeliveryService;
use App\Services\LINEService;
use App\Services\MultipleBubblesService;
use App\Services\PaymentFlexService;
use Mockery;
use Tests\TestCase;

class AggregatedMessageDeliveryServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function models(string $content = 'Hello'): array
    {
        $bot = Bot::factory()->make(['id' => 26, 'status' => 'active', 'channel_type' => 'line']);
        $conversation = Conversation::factory()->make([
            'id' => 100,
            'bot_id' => 26,
            'external_customer_id' => 'U_test',
            'channel_type' => 'line',
        ]);
        $message = Message::factory()->fromBot()->make([
            'id' => 200,
            'conversation_id' => 100,
            'content' => $content,
        ]);

        return [$bot, $conversation, $message];
    }

    public function test_flex_response_uses_one_line_push_and_skips_bubbles(): void
    {
        [$bot, $conversation, $message] = $this->models('Pay now');
        $flex = ['type' => 'flex', 'altText' => 'Payment'];

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->once()->andReturn('rk-flex');
        $line->shouldReceive('push')->once()->with($bot, 'U_test', [$flex], 'rk-flex');

        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldNotReceive('isEnabled');

        $payment = Mockery::mock(PaymentFlexService::class);
        $payment->shouldReceive('tryConvertToFlex')->once()->with('Pay now', $conversation)->andReturn($flex);

        (new AggregatedMessageDeliveryService($line, $bubbles, $payment))
            ->deliver($bot, $conversation, $message, 'U_test');
    }

    public function test_bubble_response_uses_bubble_service_and_skips_plain_push(): void
    {
        [$bot, $conversation, $message] = $this->models('Bubble reply');
        $parsed = [['type' => 'bubble', 'content' => 'Bubble reply']];

        $line = Mockery::mock(LINEService::class);
        $line->shouldNotReceive('push');

        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->once()->with($bot)->andReturn(true);
        $bubbles->shouldReceive('parseIntoBubbles')->once()->with('Bubble reply', $bot)->andReturn($parsed);
        $bubbles->shouldReceive('sendBubbles')->once()->with($bot, 'U_test', null, $parsed, $conversation);

        $payment = Mockery::mock(PaymentFlexService::class);
        $payment->shouldReceive('tryConvertToFlex')->once()->with('Bubble reply', $conversation)->andReturn('Bubble reply');

        (new AggregatedMessageDeliveryService($line, $bubbles, $payment))
            ->deliver($bot, $conversation, $message, 'U_test');
    }

    public function test_plain_response_generates_retry_key_and_pushes_text(): void
    {
        [$bot, $conversation, $message] = $this->models('Plain reply');

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->once()->andReturn('rk-text');
        $line->shouldReceive('push')->once()->with($bot, 'U_test', ['Plain reply'], 'rk-text');

        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->once()->with($bot)->andReturn(false);

        $payment = Mockery::mock(PaymentFlexService::class);
        $payment->shouldReceive('tryConvertToFlex')->once()->with('Plain reply', $conversation)->andReturn('Plain reply');

        (new AggregatedMessageDeliveryService($line, $bubbles, $payment))
            ->deliver($bot, $conversation, $message, 'U_test');
    }

    public function test_delivery_exception_is_not_swallowed(): void
    {
        [$bot, $conversation, $message] = $this->models('Plain reply');

        $line = Mockery::mock(LINEService::class);
        $line->shouldReceive('generateRetryKey')->once()->andReturn('rk-text');
        $line->shouldReceive('push')->once()->andThrow(new \RuntimeException('LINE unavailable'));

        $bubbles = Mockery::mock(MultipleBubblesService::class);
        $bubbles->shouldReceive('isEnabled')->once()->andReturn(false);

        $payment = Mockery::mock(PaymentFlexService::class);
        $payment->shouldReceive('tryConvertToFlex')->once()->andReturn('Plain reply');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LINE unavailable');

        (new AggregatedMessageDeliveryService($line, $bubbles, $payment))
            ->deliver($bot, $conversation, $message, 'U_test');
    }
}
```

- [ ] **Step 2: Run the tests and verify RED**

Run:

```bash
cd backend
php artisan test tests/Unit/Services/Chat/AggregatedMessageDeliveryServiceTest.php
```

Expected: FAIL because `App\Services\Chat\AggregatedMessageDeliveryService` does not exist.

- [ ] **Step 3: Implement the minimal delivery service**

```php
<?php

namespace App\Services\Chat;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\LINEService;
use App\Services\MultipleBubblesService;
use App\Services\PaymentFlexService;

class AggregatedMessageDeliveryService
{
    public function __construct(
        private LINEService $line,
        private MultipleBubblesService $bubbles,
        private PaymentFlexService $paymentFlex,
    ) {}

    public function deliver(
        Bot $bot,
        Conversation $conversation,
        Message $botMessage,
        string $externalUserId,
    ): void {
        $transformed = $this->paymentFlex->tryConvertToFlex($botMessage->content, $conversation);

        if (is_array($transformed)) {
            $retryKey = $this->line->generateRetryKey();
            $this->line->push($bot, $externalUserId, [$transformed], $retryKey);

            return;
        }

        if ($this->bubbles->isEnabled($bot)) {
            $parsed = $this->bubbles->parseIntoBubbles($botMessage->content, $bot);
            $this->bubbles->sendBubbles($bot, $externalUserId, null, $parsed, $conversation);

            return;
        }

        $retryKey = $this->line->generateRetryKey();
        $this->line->push($bot, $externalUserId, [$botMessage->content], $retryKey);
    }
}
```

- [ ] **Step 4: Verify GREEN and style**

Run:

```bash
php artisan test tests/Unit/Services/Chat/AggregatedMessageDeliveryServiceTest.php
vendor/bin/pint --test app/Services/Chat/AggregatedMessageDeliveryService.php tests/Unit/Services/Chat/AggregatedMessageDeliveryServiceTest.php
```

Expected: four tests pass and Pint exits 0.

- [ ] **Step 5: Commit Task 1**

```bash
git add backend/app/Services/Chat/AggregatedMessageDeliveryService.php \
  backend/tests/Unit/Services/Chat/AggregatedMessageDeliveryServiceTest.php
git commit -m "refactor(chat): extract aggregated message delivery"
```

---

### Task 2: Add best-effort queued flow-plugin execution

**Files:**
- Create: `backend/tests/Unit/Jobs/ExecuteFlowPluginsTest.php`
- Create: `backend/app/Jobs/ExecuteFlowPlugins.php`

**Interfaces:**
- Consumes: persisted `Bot`, `Conversation`, and `Message` IDs; `FlowPluginService`
- Produces: a queue job whose public configuration is `$tries = 1`, `$timeout = 90`, and `$queue = 'llm'`

- [ ] **Step 1: Write failing plugin job tests**

Create tests covering job configuration, valid execution, missing records, and ownership boundaries:

```php
<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ExecuteFlowPlugins;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\FlowPluginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ExecuteFlowPluginsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function records(): array
    {
        $bot = Bot::factory()->active()->line()->create();
        $conversation = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
        $message = Message::factory()->fromBot()->create(['conversation_id' => $conversation->id]);

        return [$bot, $conversation, $message];
    }

    public function test_job_configuration_is_best_effort_on_llm_queue(): void
    {
        $job = new ExecuteFlowPlugins(1, 2, 3);

        $this->assertSame(1, $job->tries);
        $this->assertSame(90, $job->timeout);
        $this->assertSame('llm', $job->queue);
    }

    public function test_valid_records_execute_plugins_once(): void
    {
        [$bot, $conversation, $message] = $this->records();
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldReceive('executePlugins')->once()->withArgs(
            fn (Bot $actualBot, Conversation $actualConversation, Message $actualMessage) =>
                $actualBot->is($bot) && $actualConversation->is($conversation) && $actualMessage->is($message)
        );

        (new ExecuteFlowPlugins($bot->id, $conversation->id, $message->id))->handle($plugins);
    }

    public function test_missing_record_is_a_noop(): void
    {
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldNotReceive('executePlugins');

        (new ExecuteFlowPlugins(999001, 999002, 999003))->handle($plugins);

        $this->assertTrue(true);
    }

    public function test_conversation_from_another_bot_is_rejected(): void
    {
        [$bot, $conversation, $message] = $this->records();
        $otherBot = Bot::factory()->active()->line()->create();
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldNotReceive('executePlugins');

        (new ExecuteFlowPlugins($otherBot->id, $conversation->id, $message->id))->handle($plugins);

        $this->assertNotSame($bot->id, $otherBot->id);
    }

    public function test_message_from_another_conversation_is_rejected(): void
    {
        [$bot, $conversation] = $this->records();
        $otherConversation = Conversation::factory()->line()->create(['bot_id' => $bot->id]);
        $otherMessage = Message::factory()->fromBot()->create(['conversation_id' => $otherConversation->id]);
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldNotReceive('executePlugins');

        (new ExecuteFlowPlugins($bot->id, $conversation->id, $otherMessage->id))->handle($plugins);

        $this->assertNotSame($conversation->id, $otherConversation->id);
    }
}
```

- [ ] **Step 2: Run the tests and verify RED**

Run:

```bash
php artisan test tests/Unit/Jobs/ExecuteFlowPluginsTest.php
```

Expected: FAIL because `App\Jobs\ExecuteFlowPlugins` does not exist.

- [ ] **Step 3: Implement the minimal job**

```php
<?php

namespace App\Jobs;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\FlowPluginService;
use App\Support\QueueRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteFlowPlugins implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(
        public int $botId,
        public int $conversationId,
        public int $messageId,
    ) {
        $this->onQueue(QueueRouter::QUEUE_LLM);
    }

    public function handle(FlowPluginService $plugins): void
    {
        $bot = Bot::find($this->botId);
        $conversation = Conversation::find($this->conversationId);
        $message = Message::find($this->messageId);

        if (! $bot || ! $conversation || ! $message
            || (int) $conversation->bot_id !== (int) $bot->id
            || (int) $message->conversation_id !== (int) $conversation->id) {
            Log::warning('ExecuteFlowPlugins skipped: records missing or out of scope', [
                'bot_id' => $this->botId,
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
            ]);

            return;
        }

        try {
            $plugins->executePlugins($bot, $conversation, $message);
        } catch (\Throwable $e) {
            Log::warning('ExecuteFlowPlugins failed', [
                'bot_id' => $this->botId,
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
```

- [ ] **Step 4: Verify GREEN and style**

Run:

```bash
php artisan test tests/Unit/Jobs/ExecuteFlowPluginsTest.php
vendor/bin/pint --test app/Jobs/ExecuteFlowPlugins.php tests/Unit/Jobs/ExecuteFlowPluginsTest.php
```

Expected: five tests pass and Pint exits 0.

- [ ] **Step 5: Commit Task 2**

```bash
git add backend/app/Jobs/ExecuteFlowPlugins.php backend/tests/Unit/Jobs/ExecuteFlowPluginsTest.php
git commit -m "feat(queue): process flow plugins after response"
```

---

### Task 3: Integrate the extracted components after response-lock release

**Files:**
- Create: `backend/tests/Unit/Jobs/ProcessAggregatedMessagesPluginDispatchTest.php`
- Modify: `backend/app/Jobs/ProcessAggregatedMessages.php`

**Interfaces:**
- Consumes: `AggregatedMessageDeliveryService::deliver()` and `ExecuteFlowPlugins`
- Produces: plugin job dispatch after lock release, with Redis/database fallback connection and fixed `llm` queue from the job constructor

- [ ] **Step 1: Write the complete failing integration test file**

Create `backend/tests/Unit/Jobs/ProcessAggregatedMessagesPluginDispatchTest.php` with the successful response, fallback response, failed delivery, and lock-order cases:

```php
<?php

namespace Tests\Unit\Jobs;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Exceptions\OpenRouterException;
use App\Jobs\ExecuteFlowPlugins;
use App\Jobs\ProcessAggregatedMessages;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\AIService;
use App\Services\Chat\AggregatedMessageDeliveryService;
use App\Services\FlowPluginService;
use App\Services\MessageAggregationService;
use App\Services\RedisHealthGate;
use App\Support\QueueRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProcessAggregatedMessagesPluginDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function context(): array
    {
        config(['cache.default' => 'array']);
        Cache::flush();

        $bot = Bot::factory()->active()->line()->create([
            'total_messages' => 0,
        ]);
        $conversation = Conversation::factory()->line()->create([
            'bot_id' => $bot->id,
            'external_customer_id' => 'U_test',
            'message_count' => 1,
            'unread_count' => 0,
            'is_handover' => false,
        ]);
        $userMessage = Message::factory()->fromUser()->create([
            'conversation_id' => $conversation->id,
            'content' => 'hello',
        ]);
        $aggregation = app(MessageAggregationService::class);
        $group = $aggregation->startOrContinueAggregation($conversation, $userMessage, 0);
        $this->assertNotNull($group);

        return [$bot, $conversation, $aggregation, $group['group_id']];
    }

    private function successfulAi(string $content = 'Bot reply'): AIService
    {
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->once()->andReturn([
            'content' => $content,
            'model' => 'test/model',
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            'cost' => 0.001,
            'rag_metadata' => [],
            'order_payload' => null,
        ]);

        return $ai;
    }

    public function test_successful_response_dispatches_plugins_and_preserves_side_effects(): void
    {
        Queue::fake([ExecuteFlowPlugins::class]);
        Event::fake([MessageSent::class, ConversationUpdated::class]);
        [$bot, $conversation, $aggregation, $groupId] = $this->context();

        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->once()->withArgs(
            fn (Bot $actualBot, Conversation $actualConversation, Message $message, string $externalUserId) =>
                $actualBot->is($bot)
                && $actualConversation->is($conversation)
                && $message->content === 'Bot reply'
                && $externalUserId === 'U_test'
        );

        $job = new ProcessAggregatedMessages($bot, $conversation, $groupId, 'U_test');
        $job->handle($aggregation, $this->successfulAi(), $delivery);

        $botMessage = Message::where('conversation_id', $conversation->id)
            ->where('sender', 'bot')
            ->sole();
        Queue::assertPushed(ExecuteFlowPlugins::class, fn (ExecuteFlowPlugins $queued) =>
            $queued->botId === $bot->id
            && $queued->conversationId === $conversation->id
            && $queued->messageId === $botMessage->id
            && $queued->queue === QueueRouter::QUEUE_LLM
        );
        $this->assertSame(2, $conversation->fresh()->message_count);
        $this->assertSame(1, $conversation->fresh()->unread_count);
        $this->assertSame(1, $bot->fresh()->total_messages);
        Event::assertDispatched(MessageSent::class);
        Event::assertDispatched(ConversationUpdated::class);
    }

    public function test_friendly_fallback_also_dispatches_plugins(): void
    {
        Queue::fake([ExecuteFlowPlugins::class]);
        Event::fake([MessageSent::class, ConversationUpdated::class]);
        [$bot, $conversation, $aggregation, $groupId] = $this->context();

        $failure = new OpenRouterException('upstream failed', 503);
        $ai = Mockery::mock(AIService::class);
        $ai->shouldReceive('generateResponse')->once()->andThrow($failure);
        $ai->shouldReceive('getErrorMessage')->once()->with($failure)->andReturn('ขออภัย ระบบขัดข้อง');

        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->once()->withArgs(
            fn (Bot $actualBot, Conversation $actualConversation, Message $message, string $externalUserId) =>
                $message->content === 'ขออภัย ระบบขัดข้อง' && $externalUserId === 'U_test'
        );

        (new ProcessAggregatedMessages($bot, $conversation, $groupId, 'U_test'))
            ->handle($aggregation, $ai, $delivery);

        Queue::assertPushed(ExecuteFlowPlugins::class, fn (ExecuteFlowPlugins $queued) =>
            Message::find($queued->messageId)?->content === 'ขออภัย ระบบขัดข้อง'
        );
    }

    public function test_failed_delivery_does_not_dispatch_plugins(): void
    {
        Queue::fake([ExecuteFlowPlugins::class]);
        Event::fake([MessageSent::class, ConversationUpdated::class]);
        [$bot, $conversation, $aggregation, $groupId] = $this->context();

        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->once()->andThrow(new \RuntimeException('LINE unavailable'));

        try {
            (new ProcessAggregatedMessages($bot, $conversation, $groupId, 'U_test'))
                ->handle($aggregation, $this->successfulAi(), $delivery);
            $this->fail('Expected delivery failure to be rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('LINE unavailable', $e->getMessage());
        }

        Queue::assertNotPushed(ExecuteFlowPlugins::class);
    }

    public function test_plugin_execution_can_acquire_lock_after_parent_releases_it(): void
    {
        config(['queue.default' => 'sync', 'cache.default' => 'array']);
        Event::fake([MessageSent::class, ConversationUpdated::class]);
        [$bot, $conversation, $aggregation, $groupId] = $this->context();

        $redisGate = Mockery::mock(RedisHealthGate::class);
        $redisGate->shouldReceive('isRedisUp')->andReturn(true);
        $this->app->instance(RedisHealthGate::class, $redisGate);

        $lockWasFree = false;
        $plugins = Mockery::mock(FlowPluginService::class);
        $plugins->shouldReceive('executePlugins')->once()->andReturnUsing(
            function () use ($conversation, &$lockWasFree): void {
                $probe = Cache::lock("ai_response:{$conversation->id}", 30);
                $lockWasFree = $probe->get();
                if ($lockWasFree) {
                    $probe->release();
                }
            }
        );
        $this->app->instance(FlowPluginService::class, $plugins);

        $delivery = Mockery::mock(AggregatedMessageDeliveryService::class);
        $delivery->shouldReceive('deliver')->once();

        (new ProcessAggregatedMessages($bot, $conversation, $groupId, 'U_test'))
            ->handle($aggregation, $this->successfulAi(), $delivery);

        $this->assertTrue($lockWasFree, 'response lock must be released before plugin execution');
    }
}
```

- [ ] **Step 2: Run the integration tests and verify RED**

Run:

```bash
php artisan test tests/Unit/Jobs/ProcessAggregatedMessagesPluginDispatchTest.php
```

Expected: FAIL because `ProcessAggregatedMessages::handle()` still expects `LINEService` and `MultipleBubblesService`, still executes plugins synchronously, and does not dispatch `ExecuteFlowPlugins`.

- [ ] **Step 3: Refactor `ProcessAggregatedMessages` minimally**

Apply these exact structural changes:

1. Replace imports of `FlowPluginService`, `LINEService`, `MultipleBubblesService`, and `PaymentFlexService` with:
   ```php
   use App\Services\Chat\AggregatedMessageDeliveryService;
   ```
2. Add the `ExecuteFlowPlugins` import only if required by the namespace layout.
3. Change `handle()` to accept:
   ```php
   MessageAggregationService $aggregationService,
   AIService $aiService,
   AggregatedMessageDeliveryService $delivery,
   ```
4. Pass `$delivery` through `processAggregatedMessages()` to `generateAndDeliver()`.
5. Replace both calls to `deliverToChannel()` with:
   ```php
   $delivery->deliver($this->bot, $this->conversation, $botMessage, $this->externalUserId);
   ```
6. Delete synchronous `FlowPluginService::executePlugins()` from `generateAndDeliver()`.
7. Delete the private `deliverToChannel()` method.
8. Immediately after the `finally` block releases `$responseLock`, dispatch the plugin job for a non-null `$botMessage`:
   ```php
   if (isset($botMessage) && $botMessage) {
       try {
           ExecuteFlowPlugins::dispatch(
               $this->bot->id,
               $this->conversation->id,
               $botMessage->id,
           )->onConnection(QueueRouter::connection());
       } catch (\Throwable $e) {
           Log::warning('Flow plugin dispatch failed after aggregation response', [
               'bot_id' => $this->bot->id,
               'conversation_id' => $this->conversation->id,
               'message_id' => $botMessage->id,
               'error' => $e->getMessage(),
           ]);
       }
   }
   ```
9. Keep aggregation cleanup and broadcasts in their current relative order after this dispatch.

- [ ] **Step 4: Verify GREEN for focused tests**

Run:

```bash
php artisan test tests/Unit/Services/Chat/AggregatedMessageDeliveryServiceTest.php \
  tests/Unit/Jobs/ExecuteFlowPluginsTest.php \
  tests/Unit/Jobs/ProcessAggregatedMessagesShouldGenerateTest.php \
  tests/Unit/Jobs/ProcessAggregatedMessagesPluginDispatchTest.php
```

Expected: all focused tests pass.

- [ ] **Step 5: Run full backend verification**

Run:

```bash
vendor/bin/pint --test
composer test
```

Expected:

```text
Pint: exit 0
Tests: 1311 baseline tests + new tests passed
Skipped: 16, with no new skips
```

The exact pass count is baseline `1311` plus the number of new tests added in Tasks 1–3.

- [ ] **Step 6: Commit Task 3**

```bash
git add backend/app/Jobs/ProcessAggregatedMessages.php \
  backend/tests/Unit/Jobs/ProcessAggregatedMessagesPluginDispatchTest.php
git commit -m "refactor(queue): release response lock before plugins"
```

---

## Final Verification

After all task reviews are clean, run from `backend/`:

```bash
vendor/bin/pint --test
composer test
```

Then verify the branch scope:

```bash
git diff --check origin/revert/bot26-commerce-safety...HEAD
git diff --stat origin/revert/bot26-commerce-safety...HEAD
git status --short --branch
```

Expected scope:

- design and implementation plan;
- `ProcessAggregatedMessages.php`;
- `AggregatedMessageDeliveryService.php`;
- `ExecuteFlowPlugins.php`;
- three focused test files.

No production deployment, Railway change, PR #278 merge, or push to a shared branch is part of this plan.
