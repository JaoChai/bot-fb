<?php

namespace Tests\Unit\Services\Agent;

use App\Models\Bot;
use App\Models\Flow;
use App\Models\User;
use App\Services\Agent\AgentLoopCallbacks;
use App\Services\Agent\AgentLoopConfig;
use App\Services\Agent\AgentLoopService;
use App\Services\AgentSafetyService;
use App\Services\CostTrackingService;
use App\Services\MultipleBubblesService;
use App\Services\OpenRouterService;
use App\Services\ToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentLoopServiceTest extends TestCase
{
    use RefreshDatabase;

    private AgentLoopService $service;

    private OpenRouterService $openRouter;

    private ToolService $toolService;

    private AgentSafetyService $agentSafety;

    private CostTrackingService $costTracking;

    private MultipleBubblesService $multipleBubbles;

    private User $user;

    private Bot $bot;

    private Flow $flow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->openRouter = $this->createMock(OpenRouterService::class);
        $this->toolService = $this->createMock(ToolService::class);
        $this->agentSafety = $this->createMock(AgentSafetyService::class);
        $this->costTracking = $this->createMock(CostTrackingService::class);
        $this->multipleBubbles = $this->createMock(MultipleBubblesService::class);

        $this->service = new AgentLoopService(
            $this->openRouter,
            $this->toolService,
            $this->agentSafety,
            $this->costTracking,
            $this->multipleBubbles,
        );

        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->create(['user_id' => $this->user->id]);
        $this->flow = Flow::factory()->create([
            'bot_id' => $this->bot->id,
            'enabled_tools' => ['search_kb', 'calculate'],
            'agentic_mode' => true,
        ]);

        // Default mock behaviors
        $this->costTracking->method('startRequest')->willReturn('test-request-id');
        $this->costTracking->method('getRunningCost')->willReturn(0.0);
        $this->agentSafety->method('getSafetyConfig')->willReturn([
            'timeout_seconds' => 120,
            'max_cost_per_request' => null,
            'hitl_enabled' => false,
        ]);
        $this->agentSafety->method('checkLimits')->willReturn(null);
        $this->toolService->method('getToolDefinitions')->willReturn([
            ['function' => ['name' => 'search_knowledge_base']],
            ['function' => ['name' => 'calculate']],
        ]);
        $this->multipleBubbles->method('isEnabled')->willReturn(false);
    }

    private function makeConfig(array $overrides = []): AgentLoopConfig
    {
        return new AgentLoopConfig(
            bot: $overrides['bot'] ?? $this->bot,
            flow: $overrides['flow'] ?? $this->flow,
            userMessage: $overrides['userMessage'] ?? 'Hello',
            conversationHistory: $overrides['conversationHistory'] ?? [],
            apiKey: $overrides['apiKey'] ?? 'test-key',
            userId: $overrides['userId'] ?? $this->user->id,
            kbContext: $overrides['kbContext'] ?? '',
            memoryNotes: $overrides['memoryNotes'] ?? [],
            autoRejectHitl: $overrides['autoRejectHitl'] ?? false,
        );
    }

    private function makeCallbacks(): MockAgentCallbacks
    {
        return new MockAgentCallbacks;
    }

    public function test_happy_path_no_tools(): void
    {
        $this->openRouter->method('chatWithTools')->willReturn([
            'content' => 'Hello! How can I help?',
            'model' => 'openai/gpt-4o',
            'finish_reason' => 'stop',
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
        ]);

        $callbacks = $this->makeCallbacks();
        $result = $this->service->run($this->makeConfig(), $callbacks);

        $this->assertEquals('Hello! How can I help?', $result->content);
        $this->assertEquals('openai/gpt-4o', $result->model);
        $this->assertEquals(1, $result->agentic['iterations']);
        $this->assertEquals(0, $result->agentic['tool_calls']);
        $this->assertTrue($callbacks->hasEvent('agent_start'));
        $this->assertTrue($callbacks->hasEvent('agent_thinking'));
        $this->assertTrue($callbacks->hasEvent('agent_done'));
        $this->assertTrue($callbacks->hasEvent('content'));
    }

    public function test_single_tool_call(): void
    {
        $this->openRouter->method('chatWithTools')
            ->willReturnOnConsecutiveCalls(
                // First call: LLM wants to use search_kb
                [
                    'content' => null,
                    'model' => 'openai/gpt-4o',
                    'finish_reason' => 'tool_calls',
                    'tool_calls' => [[
                        'id' => 'call_123',
                        'function' => [
                            'name' => 'search_knowledge_base',
                            'arguments' => '{"query":"test"}',
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                ],
                // Second call: LLM generates final response
                [
                    'content' => 'Based on the search, here is your answer.',
                    'model' => 'openai/gpt-4o',
                    'finish_reason' => 'stop',
                    'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 30],
                ]
            );

        $this->toolService->method('executeTool')->willReturn([
            'status' => 'success',
            'result' => 'Search result data',
            'time_ms' => 150,
        ]);

        $this->agentSafety->method('requiresApproval')->willReturn(false);

        $callbacks = $this->makeCallbacks();
        $result = $this->service->run($this->makeConfig(), $callbacks);

        $this->assertEquals('Based on the search, here is your answer.', $result->content);
        $this->assertEquals(2, $result->agentic['iterations']);
        $this->assertEquals(1, $result->agentic['tool_calls']);
        $this->assertTrue($callbacks->hasEvent('tool_call'));
        $this->assertTrue($callbacks->hasEvent('tool_result'));
    }

    public function test_multiple_tool_calls_in_batch(): void
    {
        $this->openRouter->method('chatWithTools')
            ->willReturnOnConsecutiveCalls(
                // First: LLM calls both tools
                [
                    'content' => null,
                    'model' => 'openai/gpt-4o',
                    'finish_reason' => 'tool_calls',
                    'tool_calls' => [
                        [
                            'id' => 'call_1',
                            'function' => ['name' => 'search_knowledge_base', 'arguments' => '{"query":"price"}'],
                        ],
                        [
                            'id' => 'call_2',
                            'function' => ['name' => 'calculate', 'arguments' => '{"expression":"100*3"}'],
                        ],
                    ],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 30],
                ],
                // Second: final text
                [
                    'content' => 'The total is 300 baht.',
                    'model' => 'openai/gpt-4o',
                    'finish_reason' => 'stop',
                    'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 15],
                ]
            );

        $this->toolService->method('executeTool')->willReturn([
            'status' => 'success',
            'result' => 'Tool result',
            'time_ms' => 50,
        ]);

        $this->agentSafety->method('requiresApproval')->willReturn(false);

        $callbacks = $this->makeCallbacks();
        $result = $this->service->run($this->makeConfig(), $callbacks);

        $this->assertEquals('The total is 300 baht.', $result->content);
        $this->assertEquals(2, $result->agentic['tool_calls']);
        $this->assertEquals(2, count($callbacks->getEventsOfType('tool_call')));
        $this->assertEquals(2, count($callbacks->getEventsOfType('tool_result')));
    }

    public function test_max_iterations_reached(): void
    {
        $flow = Flow::factory()->create([
            'bot_id' => $this->bot->id,
            'enabled_tools' => ['search_kb'],
            'agentic_mode' => true,
            'max_tool_calls' => 2,
        ]);

        // Always return tool calls (never stops)
        $this->openRouter->method('chatWithTools')
            ->willReturnOnConsecutiveCalls(
                [
                    'content' => null,
                    'finish_reason' => 'tool_calls',
                    'tool_calls' => [['id' => 'c1', 'function' => ['name' => 'search_knowledge_base', 'arguments' => '{"query":"a"}']]],
                    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10],
                ],
                [
                    'content' => null,
                    'finish_reason' => 'tool_calls',
                    'tool_calls' => [['id' => 'c2', 'function' => ['name' => 'search_knowledge_base', 'arguments' => '{"query":"b"}']]],
                    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10],
                ],
                // Max iterations fallback (toolChoice='none')
                [
                    'content' => 'Fallback answer after max iterations.',
                    'model' => 'openai/gpt-4o',
                    'finish_reason' => 'stop',
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                ]
            );

        $this->toolService->method('executeTool')->willReturn([
            'status' => 'success',
            'result' => 'data',
            'time_ms' => 10,
        ]);
        $this->agentSafety->method('requiresApproval')->willReturn(false);

        $callbacks = $this->makeCallbacks();
        $result = $this->service->run($this->makeConfig(['flow' => $flow]), $callbacks);

        $this->assertEquals('Fallback answer after max iterations.', $result->content);
        $this->assertEquals('max_iterations', $result->agentic['status']);
        $this->assertTrue($callbacks->hasEvent('max_iterations'));
    }

    public function test_timeout_stops_loop(): void
    {
        // Safety check returns timeout violation on first iteration
        $this->agentSafety = $this->createMock(AgentSafetyService::class);
        $this->agentSafety->method('getSafetyConfig')->willReturn([
            'timeout_seconds' => 1,
            'max_cost_per_request' => null,
            'hitl_enabled' => false,
        ]);
        $this->agentSafety->method('checkLimits')->willReturn([
            'type' => 'timeout',
            'limit' => 1,
            'elapsed' => 1.5,
        ]);

        $service = new AgentLoopService(
            $this->openRouter,
            $this->toolService,
            $this->agentSafety,
            $this->costTracking,
            $this->multipleBubbles,
        );

        $callbacks = $this->makeCallbacks();
        $result = $service->run($this->makeConfig(), $callbacks);

        $this->assertEquals('timeout', $result->agentic['status']);
        $this->assertTrue($callbacks->hasEvent('safety_stop'));
    }

    public function test_cost_limit_stops_loop(): void
    {
        $this->agentSafety = $this->createMock(AgentSafetyService::class);
        $this->agentSafety->method('getSafetyConfig')->willReturn([
            'timeout_seconds' => 120,
            'max_cost_per_request' => 0.01,
            'hitl_enabled' => false,
        ]);
        $this->agentSafety->method('checkLimits')->willReturn([
            'type' => 'cost_limit',
            'limit' => 0.01,
            'current' => 0.015,
            'scope' => 'request',
        ]);

        $service = new AgentLoopService(
            $this->openRouter,
            $this->toolService,
            $this->agentSafety,
            $this->costTracking,
            $this->multipleBubbles,
        );

        $callbacks = $this->makeCallbacks();
        $result = $service->run($this->makeConfig(), $callbacks);

        $this->assertEquals('cost_limit', $result->agentic['status']);
        $this->assertTrue($callbacks->hasEvent('safety_stop'));
    }

    public function test_hitl_auto_reject_webhook(): void
    {
        $this->agentSafety->method('requiresApproval')->willReturn(true);

        $this->openRouter->method('chatWithTools')
            ->willReturnOnConsecutiveCalls(
                [
                    'content' => null,
                    'finish_reason' => 'tool_calls',
                    'tool_calls' => [[
                        'id' => 'call_del',
                        'function' => ['name' => 'delete_record', 'arguments' => '{"id":1}'],
                    ]],
                    'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 10],
                ],
                // After auto-reject, LLM generates response
                [
                    'content' => 'I cannot perform that action.',
                    'model' => 'openai/gpt-4o',
                    'finish_reason' => 'stop',
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 15],
                ]
            );

        $callbacks = $this->makeCallbacks();
        $result = $this->service->run(
            $this->makeConfig(['autoRejectHitl' => true]),
            $callbacks
        );

        $this->assertEquals('I cannot perform that action.', $result->content);
        // HITL callbacks should NOT have been called (auto-rejected before approval flow)
        $this->assertFalse($callbacks->hasEvent('approval_required'));
    }

    public function test_daily_cost_limit(): void
    {
        $this->costTracking = $this->createMock(CostTrackingService::class);
        $this->costTracking->method('startRequest')->willReturn('test-request-id');
        $this->costTracking->method('getRunningCost')->willReturn(0.0);
        $this->costTracking->method('exceedsDailyLimit')->willReturn(true);

        $service = new AgentLoopService(
            $this->openRouter,
            $this->toolService,
            $this->agentSafety,
            $this->costTracking,
            $this->multipleBubbles,
        );

        $callbacks = $this->makeCallbacks();
        $result = $service->run($this->makeConfig(), $callbacks);

        $this->assertEquals('cost_limit', $result->agentic['status']);
        $this->assertTrue($callbacks->hasEvent('safety_stop'));
        // Verify the safety_stop event has type 'daily_limit'
        $safetyEvents = $callbacks->getEventsOfType('safety_stop');
        $this->assertEquals('daily_limit', array_values($safetyEvents)[0]['data']['type']);
    }

    public function test_hitl_approve(): void
    {
        $flow = Flow::factory()->create([
            'bot_id' => $this->bot->id,
            'enabled_tools' => ['delete_record'],
            'agentic_mode' => true,
            'hitl_enabled' => true,
        ]);

        $this->agentSafety = $this->createMock(AgentSafetyService::class);
        $this->agentSafety->method('getSafetyConfig')->willReturn([
            'timeout_seconds' => 120,
            'max_cost_per_request' => null,
            'hitl_enabled' => true,
        ]);
        $this->agentSafety->method('checkLimits')->willReturn(null);
        $this->agentSafety->method('requiresApproval')->willReturn(true);
        $this->agentSafety->method('requestApproval')->willReturn('approval-123');
        $this->agentSafety->method('waitForApproval')->willReturn([
            'approved' => true,
            'reason' => null,
        ]);

        $this->openRouter->method('chatWithTools')
            ->willReturnOnConsecutiveCalls(
                [
                    'content' => null,
                    'finish_reason' => 'tool_calls',
                    'tool_calls' => [[
                        'id' => 'call_del',
                        'function' => ['name' => 'delete_record', 'arguments' => '{"id":42}'],
                    ]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                ],
                [
                    'content' => 'Record deleted successfully.',
                    'model' => 'openai/gpt-4o',
                    'finish_reason' => 'stop',
                    'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 15],
                ]
            );

        $this->toolService->expects($this->once())
            ->method('executeTool')
            ->with('delete_record', ['id' => 42], $this->anything())
            ->willReturn([
                'status' => 'success',
                'result' => 'Deleted record 42',
                'time_ms' => 80,
            ]);

        $service = new AgentLoopService(
            $this->openRouter,
            $this->toolService,
            $this->agentSafety,
            $this->costTracking,
            $this->multipleBubbles,
        );

        $callbacks = $this->makeCallbacks();
        $result = $service->run($this->makeConfig(['flow' => $flow]), $callbacks);

        $this->assertEquals('Record deleted successfully.', $result->content);
        $this->assertEquals('completed', $result->agentic['status']);
        $this->assertEquals(1, $result->agentic['tool_calls']);
        $this->assertTrue($callbacks->hasEvent('approval_required'));
        $this->assertTrue($callbacks->hasEvent('approval_response'));
        $this->assertTrue($callbacks->hasEvent('tool_call'));
    }

    public function test_hitl_reject(): void
    {
        $flow = Flow::factory()->create([
            'bot_id' => $this->bot->id,
            'enabled_tools' => ['delete_record'],
            'agentic_mode' => true,
            'hitl_enabled' => true,
        ]);

        $this->agentSafety = $this->createMock(AgentSafetyService::class);
        $this->agentSafety->method('getSafetyConfig')->willReturn([
            'timeout_seconds' => 120,
            'max_cost_per_request' => null,
            'hitl_enabled' => true,
        ]);
        $this->agentSafety->method('checkLimits')->willReturn(null);
        $this->agentSafety->method('requiresApproval')->willReturn(true);
        $this->agentSafety->method('requestApproval')->willReturn('approval-456');
        $this->agentSafety->method('waitForApproval')->willReturn([
            'approved' => false,
            'reason' => 'Too dangerous',
        ]);

        $this->openRouter->method('chatWithTools')
            ->willReturnOnConsecutiveCalls(
                [
                    'content' => null,
                    'finish_reason' => 'tool_calls',
                    'tool_calls' => [[
                        'id' => 'call_del',
                        'function' => ['name' => 'delete_record', 'arguments' => '{"id":42}'],
                    ]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
                ],
                [
                    'content' => 'I was unable to delete the record as it was rejected.',
                    'model' => 'openai/gpt-4o',
                    'finish_reason' => 'stop',
                    'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 20],
                ]
            );

        // executeTool should NOT be called since action was rejected
        $this->toolService->expects($this->never())->method('executeTool');

        $service = new AgentLoopService(
            $this->openRouter,
            $this->toolService,
            $this->agentSafety,
            $this->costTracking,
            $this->multipleBubbles,
        );

        $callbacks = $this->makeCallbacks();
        $result = $service->run($this->makeConfig(['flow' => $flow]), $callbacks);

        $this->assertEquals('I was unable to delete the record as it was rejected.', $result->content);
        $this->assertEquals('completed', $result->agentic['status']);
        $this->assertTrue($callbacks->hasEvent('approval_required'));
        $this->assertTrue($callbacks->hasEvent('approval_response'));
        // Tool call event should NOT have been emitted (rejected before execution)
        $this->assertFalse($callbacks->hasEvent('tool_call'));
    }

    public function test_message_truncation(): void
    {
        // Test the public truncateMessagesIfNeeded method directly
        $messages = [
            ['role' => 'system', 'content' => 'System prompt'],
        ];

        // Add 35 user/assistant messages to exceed maxMessages=30
        for ($i = 0; $i < 35; $i++) {
            $messages[] = [
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ];
        }

        $truncated = $this->service->truncateMessagesIfNeeded($messages, 30);

        // Should be at most 32 (system + context note + 30 messages)
        $this->assertLessThanOrEqual(32, count($truncated));
        // System message preserved at index 0
        $this->assertEquals('system', $truncated[0]['role']);
        $this->assertEquals('System prompt', $truncated[0]['content']);
        // Context note about dropped messages
        $this->assertStringContainsString('ข้อความก่อนหน้า', $truncated[1]['content']);
        // Last message preserved
        $lastOriginal = end($messages);
        $lastTruncated = end($truncated);
        $this->assertEquals($lastOriginal['content'], $lastTruncated['content']);
    }

    public function test_error_fallback(): void
    {
        $this->openRouter->method('chatWithTools')
            ->willThrowException(new \RuntimeException('API connection failed'));

        $callbacks = $this->makeCallbacks();
        $result = $this->service->run($this->makeConfig(), $callbacks);

        $this->assertEquals('error', $result->agentic['status']);
        $this->assertNotEmpty($result->agentic['error']);
        $this->assertTrue($callbacks->hasEvent('agent_error'));
    }

    public function test_build_memory_prefix(): void
    {
        $prefix = $this->service->buildMemoryPrefix(['Note 1', 'Note 2']);

        $this->assertStringContainsString('## Memory:', $prefix);
        $this->assertStringContainsString('- Note 1', $prefix);
        $this->assertStringContainsString('- Note 2', $prefix);
    }

    public function test_build_memory_prefix_empty(): void
    {
        $this->assertEquals('', $this->service->buildMemoryPrefix([]));
    }

    public function test_compress_old_tool_results(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'system prompt'],
            ['role' => 'tool', 'content' => str_repeat('x', 200)],
            ['role' => 'tool', 'content' => str_repeat('y', 200)],
            ['role' => 'tool', 'content' => 'recent tool 1'],
            ['role' => 'tool', 'content' => 'recent tool 2'],
        ];

        $result = $this->service->compressOldToolResults($messages);

        // First two tool results should be compressed
        $this->assertStringContainsString('[compressed]', $result[1]['content']);
        $this->assertStringContainsString('[compressed]', $result[2]['content']);
        // Last two should be untouched
        $this->assertEquals('recent tool 1', $result[3]['content']);
        $this->assertEquals('recent tool 2', $result[4]['content']);
    }
}

/**
 * Mock callback implementation for testing.
 */
class MockAgentCallbacks implements AgentLoopCallbacks
{
    private array $events = [];

    public function onAgentStart(array $data): void
    {
        $this->events[] = ['type' => 'agent_start', 'data' => $data];
    }

    public function onThinking(array $data): void
    {
        $this->events[] = ['type' => 'agent_thinking', 'data' => $data];
    }

    public function onToolCall(array $data): void
    {
        $this->events[] = ['type' => 'tool_call', 'data' => $data];
    }

    public function onToolResult(array $data): void
    {
        $this->events[] = ['type' => 'tool_result', 'data' => $data];
    }

    public function onApprovalRequired(array $data): void
    {
        $this->events[] = ['type' => 'approval_required', 'data' => $data];
    }

    public function onApprovalWaiting(array $data): void
    {
        $this->events[] = ['type' => 'approval_waiting', 'data' => $data];
    }

    public function onApprovalResponse(array $data): void
    {
        $this->events[] = ['type' => 'approval_response', 'data' => $data];
    }

    public function onSafetyStop(array $data): void
    {
        $this->events[] = ['type' => 'safety_stop', 'data' => $data];
    }

    public function onAgentDone(array $data): void
    {
        $this->events[] = ['type' => 'agent_done', 'data' => $data];
    }

    public function onAgentError(array $data): void
    {
        $this->events[] = ['type' => 'agent_error', 'data' => $data];
    }

    public function onMaxIterations(array $data): void
    {
        $this->events[] = ['type' => 'max_iterations', 'data' => $data];
    }

    public function onContent(string $content, string $model, string $source): void
    {
        $this->events[] = ['type' => 'content', 'content' => $content, 'model' => $model, 'source' => $source];
    }

    public function hasEvent(string $type): bool
    {
        return ! empty($this->getEventsOfType($type));
    }

    public function getEventsOfType(string $type): array
    {
        return array_filter($this->events, fn ($e) => $e['type'] === $type);
    }

    public function getAllEvents(): array
    {
        return $this->events;
    }
}
