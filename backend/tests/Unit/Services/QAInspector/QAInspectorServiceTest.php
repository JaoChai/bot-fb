<?php

namespace Tests\Unit\Services\QAInspector;

use App\Models\Bot;
use App\Models\User;
use App\Services\Evaluation\LLMJudgeService;
use App\Services\OpenRouterService;
use App\Services\QAInspector\QAInspectorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class QAInspectorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected QAInspectorService $service;
    protected Bot $bot;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $llmJudgeService = Mockery::mock(LLMJudgeService::class);
        $openRouterService = Mockery::mock(OpenRouterService::class);

        $this->service = new QAInspectorService($llmJudgeService, $openRouterService);

        $this->user = User::factory()->create();
        $this->bot = Bot::factory()->create([
            'user_id' => $this->user->id,
            'qa_inspector_enabled' => true,
            'qa_sampling_rate' => 100,
            'qa_score_threshold' => 0.70,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================
    // isEnabled Tests
    // ========================================

    public function test_is_enabled_returns_true_when_qa_inspector_enabled(): void
    {
        $this->bot->qa_inspector_enabled = true;
        $this->bot->save();

        $result = $this->service->isEnabled($this->bot);

        $this->assertTrue($result);
    }

    public function test_is_enabled_returns_false_when_disabled(): void
    {
        $this->bot->qa_inspector_enabled = false;
        $this->bot->save();

        $result = $this->service->isEnabled($this->bot);

        $this->assertFalse($result);
    }

    // ========================================
    // shouldEvaluate Tests
    // ========================================

    public function test_should_evaluate_returns_false_when_disabled(): void
    {
        $this->bot->qa_inspector_enabled = false;
        $this->bot->save();

        $result = $this->service->shouldEvaluate($this->bot);

        $this->assertFalse($result);
    }

    public function test_should_evaluate_respects_sampling_rate_always_when_100(): void
    {
        $this->bot->qa_inspector_enabled = true;
        $this->bot->qa_sampling_rate = 100;
        $this->bot->save();

        // With 100% sampling, should always return true
        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $results[] = $this->service->shouldEvaluate($this->bot);
        }

        $this->assertNotContains(false, $results);
    }

    public function test_should_evaluate_respects_sampling_rate_never_when_0(): void
    {
        $this->bot->qa_inspector_enabled = true;
        $this->bot->qa_sampling_rate = 0;
        $this->bot->save();

        // With 0% sampling, should always return false
        $results = [];
        for ($i = 0; $i < 10; $i++) {
            $results[] = $this->service->shouldEvaluate($this->bot);
        }

        $this->assertNotContains(true, $results);
    }

    public function test_should_evaluate_respects_sampling_rate_probabilistic(): void
    {
        $this->bot->qa_inspector_enabled = true;
        $this->bot->qa_sampling_rate = 50;
        $this->bot->save();

        // With 50% sampling, we should get a mix over many iterations
        $trueCount = 0;
        $iterations = 100;

        for ($i = 0; $i < $iterations; $i++) {
            if ($this->service->shouldEvaluate($this->bot)) {
                $trueCount++;
            }
        }

        // Should be roughly 50% (allow 20-80% range for randomness)
        $this->assertGreaterThan(20, $trueCount);
        $this->assertLessThan(80, $trueCount);
    }

    // ========================================
    // getThreshold Tests
    // ========================================

    public function test_get_threshold_returns_custom_value(): void
    {
        $this->bot->qa_score_threshold = 0.85;
        $this->bot->save();

        $result = $this->service->getThreshold($this->bot);

        $this->assertEquals(0.85, $result);
    }

    public function test_get_threshold_returns_default_when_not_set(): void
    {
        // Create a bot without qa_score_threshold to test default
        // Instead of setting to null (which violates NOT NULL), we test the service logic
        // by creating a fresh bot and checking the service's default fallback
        $freshBot = new Bot();
        $freshBot->qa_score_threshold = null;

        // The service should return 0.70 when qa_score_threshold is null
        $result = $this->service->getThreshold($freshBot);

        $this->assertEquals(0.70, $result);
    }

    // ========================================
    // calculateOverallScore Tests
    // ========================================

    public function test_calculate_overall_score_with_weights(): void
    {
        $scores = [
            'answer_relevancy' => 0.80,    // weight: 0.25
            'faithfulness' => 0.90,         // weight: 0.25
            'role_adherence' => 0.70,       // weight: 0.20
            'context_precision' => 0.60,    // weight: 0.15
            'task_completion' => 0.80,      // weight: 0.15
        ];

        // Expected: (0.80*0.25 + 0.90*0.25 + 0.70*0.20 + 0.60*0.15 + 0.80*0.15) / 1.0
        // = (0.20 + 0.225 + 0.14 + 0.09 + 0.12) / 1.0
        // = 0.775
        $expected = 0.78; // Rounded to 2 decimal places

        $result = $this->service->calculateOverallScore($scores);

        $this->assertEquals($expected, $result);
    }

    public function test_calculate_overall_score_with_partial_metrics(): void
    {
        $scores = [
            'answer_relevancy' => 0.80,
            'faithfulness' => 0.90,
            // Other metrics missing
        ];

        // Should calculate weighted average of available metrics
        // (0.80*0.25 + 0.90*0.25) / (0.25 + 0.25) = 0.425 / 0.5 = 0.85
        $result = $this->service->calculateOverallScore($scores);

        $this->assertEquals(0.85, $result);
    }

    public function test_calculate_overall_score_with_empty_scores(): void
    {
        $scores = [];

        $result = $this->service->calculateOverallScore($scores);

        $this->assertEquals(0, $result);
    }

    // ========================================
    // shouldFlag Tests
    // ========================================

    public function test_should_flag_returns_true_when_below_threshold(): void
    {
        $this->bot->qa_score_threshold = 0.70;
        $this->bot->save();

        $result = $this->service->shouldFlag(0.65, $this->bot);

        $this->assertTrue($result);
    }

    public function test_should_flag_returns_false_when_above_threshold(): void
    {
        $this->bot->qa_score_threshold = 0.70;
        $this->bot->save();

        $result = $this->service->shouldFlag(0.75, $this->bot);

        $this->assertFalse($result);
    }

    public function test_should_flag_returns_false_when_equal_to_threshold(): void
    {
        $this->bot->qa_score_threshold = 0.70;
        $this->bot->save();

        $result = $this->service->shouldFlag(0.70, $this->bot);

        $this->assertFalse($result);
    }

    // ========================================
    // categorizeIssue Tests
    // ========================================

    public function test_categorize_issue_for_low_faithfulness(): void
    {
        $scores = [
            'faithfulness' => 0.50,
            'answer_relevancy' => 0.80,
            'role_adherence' => 0.75,
            'context_precision' => 0.72,
            'task_completion' => 0.78,
        ];

        $result = $this->service->categorizeIssue($scores, 0.70);

        $this->assertEquals('hallucination', $result);
    }

    public function test_categorize_issue_for_low_relevancy(): void
    {
        $scores = [
            'faithfulness' => 0.80,
            'answer_relevancy' => 0.40,
            'role_adherence' => 0.75,
            'context_precision' => 0.72,
            'task_completion' => 0.78,
        ];

        $result = $this->service->categorizeIssue($scores, 0.70);

        $this->assertEquals('off_topic', $result);
    }

    public function test_categorize_issue_for_low_role_adherence(): void
    {
        $scores = [
            'faithfulness' => 0.80,
            'answer_relevancy' => 0.75,
            'role_adherence' => 0.45,
            'context_precision' => 0.72,
            'task_completion' => 0.78,
        ];

        $result = $this->service->categorizeIssue($scores, 0.70);

        $this->assertEquals('wrong_tone', $result);
    }

    public function test_categorize_issue_for_low_context_precision(): void
    {
        $scores = [
            'faithfulness' => 0.80,
            'answer_relevancy' => 0.75,
            'role_adherence' => 0.75,
            'context_precision' => 0.40,
            'task_completion' => 0.78,
        ];

        $result = $this->service->categorizeIssue($scores, 0.70);

        $this->assertEquals('missing_info', $result);
    }

    public function test_categorize_issue_for_low_task_completion(): void
    {
        $scores = [
            'faithfulness' => 0.80,
            'answer_relevancy' => 0.75,
            'role_adherence' => 0.75,
            'context_precision' => 0.72,
            'task_completion' => 0.35,
        ];

        $result = $this->service->categorizeIssue($scores, 0.70);

        $this->assertEquals('unanswered', $result);
    }

    public function test_categorize_issue_returns_null_when_all_passing(): void
    {
        $scores = [
            'faithfulness' => 0.80,
            'answer_relevancy' => 0.75,
            'role_adherence' => 0.75,
            'context_precision' => 0.72,
            'task_completion' => 0.78,
        ];

        $result = $this->service->categorizeIssue($scores, 0.70);

        $this->assertNull($result);
    }

    public function test_categorize_issue_returns_worst_issue(): void
    {
        // Multiple issues - should return the one with lowest score
        $scores = [
            'faithfulness' => 0.30,        // Worst
            'answer_relevancy' => 0.50,
            'role_adherence' => 0.60,
            'context_precision' => 0.55,
            'task_completion' => 0.65,
        ];

        $result = $this->service->categorizeIssue($scores, 0.70);

        $this->assertEquals('hallucination', $result);
    }

    // ========================================
    // isValidModelFormat Tests
    // ========================================

    public function test_is_valid_model_format_validates_correctly(): void
    {
        // Valid formats
        $this->assertTrue($this->service->isValidModelFormat('openai/gpt-4o'));
        $this->assertTrue($this->service->isValidModelFormat('anthropic/claude-3.5-sonnet'));
        $this->assertTrue($this->service->isValidModelFormat('google/gemini-2.5-flash-preview'));
        $this->assertTrue($this->service->isValidModelFormat('meta-llama/llama-3.1-70b'));

        // Invalid formats
        $this->assertFalse($this->service->isValidModelFormat('gpt-4o'));
        $this->assertFalse($this->service->isValidModelFormat('openai'));
        $this->assertFalse($this->service->isValidModelFormat(''));
        $this->assertFalse($this->service->isValidModelFormat('openai//gpt-4o'));
        $this->assertFalse($this->service->isValidModelFormat('open ai/gpt 4o'));
    }

    // ========================================
    // Model Layer Tests
    // ========================================

    public function test_get_models_for_layer_returns_defaults_when_not_set(): void
    {
        $realtimeModels = $this->service->getModelsForLayer($this->bot, 'realtime');
        $analysisModels = $this->service->getModelsForLayer($this->bot, 'analysis');
        $reportModels = $this->service->getModelsForLayer($this->bot, 'report');

        $this->assertEquals('google/gemini-2.5-flash-preview', $realtimeModels['primary']);
        $this->assertEquals('openai/gpt-4o-mini', $realtimeModels['fallback']);
        $this->assertEquals('anthropic/claude-sonnet-4', $analysisModels['primary']);
        $this->assertEquals('openai/gpt-4o', $analysisModels['fallback']);
        $this->assertEquals('anthropic/claude-opus-4-5', $reportModels['primary']);
        $this->assertEquals('anthropic/claude-sonnet-4', $reportModels['fallback']);
    }

    public function test_get_models_for_layer_returns_custom_when_set(): void
    {
        $this->bot->qa_realtime_model = 'openai/gpt-4o-mini';
        $this->bot->qa_realtime_fallback_model = 'google/gemini-flash';
        $this->bot->save();

        $models = $this->service->getModelsForLayer($this->bot, 'realtime');

        $this->assertEquals('openai/gpt-4o-mini', $models['primary']);
        $this->assertEquals('google/gemini-flash', $models['fallback']);
    }

    // ========================================
    // Schedule Tests
    // ========================================

    public function test_is_schedule_matching_returns_true_for_matching_schedule(): void
    {
        // Use a schedule that exists in getAvailableSchedules()
        $this->bot->qa_report_schedule = 'monday_00:00';
        $this->bot->save();

        // Monday at midnight (hour 0)
        $mondayAt0 = Carbon::create(2026, 1, 12, 0, 30, 0); // Monday

        $result = $this->service->isScheduleMatching($this->bot, $mondayAt0);

        $this->assertTrue($result);
    }

    public function test_is_schedule_matching_returns_false_for_wrong_day(): void
    {
        $this->bot->qa_report_schedule = 'monday_00:00';
        $this->bot->save();

        $tuesdayAt0 = Carbon::create(2026, 1, 13, 0, 30, 0); // Tuesday

        $result = $this->service->isScheduleMatching($this->bot, $tuesdayAt0);

        $this->assertFalse($result);
    }

    public function test_is_schedule_matching_returns_false_for_wrong_hour(): void
    {
        $this->bot->qa_report_schedule = 'monday_00:00';
        $this->bot->save();

        $mondayAt10 = Carbon::create(2026, 1, 12, 10, 30, 0); // Monday at 10am

        $result = $this->service->isScheduleMatching($this->bot, $mondayAt10);

        $this->assertFalse($result);
    }

    public function test_is_schedule_matching_friday_evening(): void
    {
        $this->bot->qa_report_schedule = 'friday_18:00';
        $this->bot->save();

        $fridayAt18 = Carbon::create(2026, 1, 16, 18, 30, 0); // Friday at 6pm

        $result = $this->service->isScheduleMatching($this->bot, $fridayAt18);

        $this->assertTrue($result);
    }

    // ========================================
    // Helper Method Tests
    // ========================================

    public function test_get_notification_settings_returns_custom(): void
    {
        $this->bot->qa_notifications = [
            'email' => false,
            'alert' => true,
            'slack' => true,
        ];
        $this->bot->save();

        $result = $this->service->getNotificationSettings($this->bot);

        $this->assertFalse($result['email']);
        $this->assertTrue($result['alert']);
        $this->assertTrue($result['slack']);
    }

    public function test_get_notification_settings_returns_defaults(): void
    {
        // Test with a fresh bot object that has null qa_notifications
        $freshBot = new Bot();
        $freshBot->qa_notifications = null;

        $result = $this->service->getNotificationSettings($freshBot);

        $this->assertTrue($result['email']);
        $this->assertTrue($result['alert']);
        $this->assertFalse($result['slack']);
    }

    public function test_get_available_schedules_returns_options(): void
    {
        $schedules = $this->service->getAvailableSchedules();

        $this->assertArrayHasKey('monday_00:00', $schedules);
        $this->assertArrayHasKey('monday_06:00', $schedules);
        $this->assertArrayHasKey('friday_18:00', $schedules);
        $this->assertArrayHasKey('sunday_00:00', $schedules);
    }

    public function test_get_known_providers_returns_array(): void
    {
        $providers = $this->service->getKnownProviders();

        $this->assertContains('openai', $providers);
        $this->assertContains('anthropic', $providers);
        $this->assertContains('google', $providers);
    }

    public function test_extract_provider_from_model_name(): void
    {
        $this->assertEquals('openai', $this->service->extractProvider('openai/gpt-4o'));
        $this->assertEquals('anthropic', $this->service->extractProvider('anthropic/claude-3.5-sonnet'));
        $this->assertEquals('google', $this->service->extractProvider('google/gemini-2.5-flash'));
    }

    public function test_get_model_cost_estimate_returns_correct_values(): void
    {
        $this->assertEquals(0.15, $this->service->getModelCostEstimate('google/gemini-2.5-flash-preview'));
        $this->assertEquals(0.30, $this->service->getModelCostEstimate('openai/gpt-4o-mini'));
        $this->assertEquals(5.00, $this->service->getModelCostEstimate('openai/gpt-4o'));
        $this->assertEquals(3.00, $this->service->getModelCostEstimate('anthropic/claude-sonnet-4'));
        $this->assertEquals(15.00, $this->service->getModelCostEstimate('anthropic/claude-opus-4'));
    }
}
