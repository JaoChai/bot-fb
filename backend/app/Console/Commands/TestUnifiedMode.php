<?php

namespace App\Console\Commands;

use App\Models\Bot;
use App\Models\Flow;
use App\Services\SecondAI\SecondAIService;
use Illuminate\Console\Command;

class TestUnifiedMode extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:unified-mode
                            {--flow-id= : Flow ID to test with (optional)}
                            {--message=How many users do you have? : Test message}
                            {--response=We have 1M users! : Test response}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Second AI unified mode with a sample flow';

    /**
     * Execute the console command.
     */
    public function handle(SecondAIService $secondAI): int
    {
        $this->info('🧪 Testing Second AI Unified Mode');
        $this->newLine();

        // Get or create test flow
        $flowId = $this->option('flow-id');

        if ($flowId) {
            $flow = Flow::find($flowId);
            if (! $flow) {
                $this->error("Flow {$flowId} not found");

                return 1;
            }
        } else {
            // Create test flow
            $bot = Bot::first();
            if (! $bot) {
                $this->error('No bots found. Please create a bot first.');

                return 1;
            }

            $flow = Flow::firstOrCreate([
                'bot_id' => $bot->id,
                'name' => 'Test Unified Mode',
            ], [
                'system_prompt' => 'You are a helpful assistant.',
                'model' => 'anthropic/claude-3.5-sonnet',
                'second_ai_enabled' => true,
                'second_ai_options' => [
                    'fact_check' => true,
                    'policy' => true,
                    'personality' => true,
                ],
            ]);

            $this->info("✅ Using flow: {$flow->name} (ID: {$flow->id})");
        }

        // Test parameters
        $userMessage = $this->option('message');
        $response = $this->option('response');

        $this->info('📝 Test Input:');
        $this->line("   User Message: {$userMessage}");
        $this->line("   AI Response: {$response}");
        $this->newLine();

        // Run Second AI process
        $this->info('🚀 Running Second AI process...');
        $startTime = microtime(true);

        try {
            $result = $secondAI->process(
                response: $response,
                flow: $flow,
                userMessage: $userMessage
            );

            $elapsed = round((microtime(true) - $startTime) * 1000);

            // Display results
            $this->newLine();
            $this->info('✅ Second AI Process Completed');
            $this->newLine();

            $this->line('📊 Results:');
            $this->line('   Applied: '.($result['second_ai_applied'] ? 'Yes' : 'No'));
            $this->line("   Elapsed: {$elapsed}ms");

            if (isset($result['second_ai']['checks_applied'])) {
                $this->line('   Checks Applied: '.implode(', ', $result['second_ai']['checks_applied']));
            }

            if (isset($result['second_ai']['elapsed_ms'])) {
                $this->line("   Service Elapsed: {$result['second_ai']['elapsed_ms']}ms");
            }

            $this->newLine();
            $this->line('📝 Final Response:');
            $this->line("   {$result['content']}");

            // Performance check
            $this->newLine();
            $targetLatency = 1500;
            if ($elapsed <= $targetLatency) {
                $this->info("✅ Performance: {$elapsed}ms ≤ {$targetLatency}ms (PASS)");
            } else {
                $this->warn("⚠️  Performance: {$elapsed}ms > {$targetLatency}ms (FAIL)");
            }

            // Check unified mode usage
            if (isset($result['second_ai']['checks_applied']) && count($result['second_ai']['checks_applied']) >= 2) {
                $this->info('✅ Unified mode likely used (multiple checks applied)');
            }

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Test failed: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return 1;
        }
    }
}
