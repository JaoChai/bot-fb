<?php

namespace App\Console\Commands;

use App\Models\PromptDeployment;
use App\Services\CommerceSafety\PromptDeploymentService;
use Illuminate\Console\Command;
use Throwable;

class DeployBotPrompt extends Command
{
    protected $signature = 'bot:deploy-prompt
        {bot?} {flow?} {path?}
        {--prepare} {--apply=} {--rollback=} {--status=}
        {--expected-current-md5=} {--expected-candidate-sha256=} {--expected-current-sha256=}
        {--actor=} {--force}';

    protected $description = 'Prepare, apply, inspect or roll back an audited protected-bot prompt';

    public function handle(PromptDeploymentService $service): int
    {
        $actions = array_filter(['prepare', 'apply', 'rollback', 'status'], fn ($action) => $this->option($action) !== null && $this->option($action) !== false);
        if (count($actions) !== 1) {
            $this->error('exactly_one_action_required');

            return self::FAILURE;
        }
        $action = reset($actions);
        $actor = (string) $this->option('actor');
        if ($action !== 'status' && trim($actor) === '') {
            $this->error('actor_required');

            return self::FAILURE;
        }
        if (in_array($action, ['apply', 'rollback']) && app()->environment('production') && ! $this->option('force')) {
            $this->error('production_force_required');

            return self::FAILURE;
        }
        if ($action === 'prepare') {
            if (! ctype_digit((string) $this->argument('bot')) || ! ctype_digit((string) $this->argument('flow')) || ! $this->argument('path') || $this->option('expected-current-sha256') !== null) {
                $this->error('prepare_arguments_invalid');

                return self::FAILURE;
            }
        } elseif ($this->argument('bot') !== null || $this->argument('flow') !== null || $this->argument('path') !== null || $this->option('expected-current-md5') !== null || $this->option('expected-candidate-sha256') !== null || ($action !== 'rollback' && $this->option('expected-current-sha256') !== null)) {
            $this->error('action_arguments_invalid');

            return self::FAILURE;
        }

        try {
            if ($action === 'prepare') {
                $row = $service->prepare((int) $this->argument('bot'), (int) $this->argument('flow'), $this->argument('path'), (string) $this->option('expected-current-md5'), (string) $this->option('expected-candidate-sha256'), $actor);
                // Prepare must not populate or invalidate either cache, even for output.
                $this->line(json_encode(['id' => $row->id, 'status' => $row->status, 'previous_sha256' => $row->previous_sha256, 'candidate_sha256' => $row->candidate_sha256], JSON_THROW_ON_ERROR));
            } else {
                $row = new PromptDeployment;
                $row->id = $this->option($action);
                if ($action === 'apply') {
                    $service->apply($row, $actor);
                } elseif ($action === 'rollback') {
                    $service->rollback($row, (string) $this->option('expected-current-sha256'), $actor);
                }
                $this->line(json_encode($service->readBack($row), JSON_THROW_ON_ERROR));
            }
        } catch (Throwable) {
            // DB/crypto/filesystem exceptions may contain prompt bytes or secrets.
            $this->error('prompt_deployment_failed');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
