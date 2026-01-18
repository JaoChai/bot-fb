<?php

namespace App\Console\Commands;

use App\Services\ModelCapabilityService;
use Illuminate\Console\Command;

class WarmModelCapabilityCache extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'model:warm-cache
                            {--all : Warm cache for all models in config}
                            {--model= : Specific model ID to warm}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warm the model capability cache from OpenRouter API';

    public function __construct(
        protected ModelCapabilityService $capabilityService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔄 Warming model capability cache...');
        $this->newLine();

        if ($model = $this->option('model')) {
            return $this->warmSingleModel($model);
        }

        return $this->warmAllModels();
    }

    /**
     * Warm cache for a single model.
     */
    protected function warmSingleModel(string $modelId): int
    {
        $this->info("Fetching capabilities for: {$modelId}");

        try {
            $capabilities = $this->capabilityService->getCapabilities($modelId);

            $this->table(
                ['Property', 'Value'],
                [
                    ['Model ID', $capabilities['model_id']],
                    ['Name', $capabilities['name']],
                    ['Supports Vision', $capabilities['supports_vision'] ? '✅ Yes' : '❌ No'],
                    ['Context Length', number_format($capabilities['context_length'])],
                    ['Max Output Tokens', number_format($capabilities['max_output_tokens'])],
                    ['Pricing (Prompt)', '$' . number_format($capabilities['pricing_prompt'], 4) . '/1M'],
                    ['Pricing (Completion)', '$' . number_format($capabilities['pricing_completion'], 4) . '/1M'],
                    ['Source', $capabilities['source']],
                ]
            );

            $this->newLine();
            $this->info('✅ Cache warmed successfully!');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to fetch capabilities: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }

    /**
     * Warm cache for all models in config.
     */
    protected function warmAllModels(): int
    {
        $models = array_keys(config('llm-models.models', []));

        if (empty($models)) {
            $this->warn('No models found in llm-models.php config.');
            return Command::SUCCESS;
        }

        $this->info('Found ' . count($models) . ' models in config.');
        $this->newLine();

        $results = $this->capabilityService->warmCache();

        // Display summary table
        $tableData = [];
        foreach ($models as $modelId) {
            try {
                $caps = $this->capabilityService->getCapabilities($modelId);
                $tableData[] = [
                    $modelId,
                    $caps['supports_vision'] ? '✅' : '❌',
                    number_format($caps['context_length']),
                    $caps['source'],
                ];
            } catch (\Throwable $e) {
                $tableData[] = [
                    $modelId,
                    '❓',
                    'N/A',
                    'error',
                ];
            }
        }

        $this->table(
            ['Model ID', 'Vision', 'Context', 'Source'],
            $tableData
        );

        $this->newLine();
        $this->info("✅ Success: {$results['success']} | ❌ Failed: {$results['failed']}");

        return $results['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
