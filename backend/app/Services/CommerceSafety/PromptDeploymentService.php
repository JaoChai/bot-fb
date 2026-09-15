<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;
use App\Models\Flow;
use App\Models\PromptDeployment;
use App\Services\FlowCacheService;
use App\Services\SemanticCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PromptDeploymentService
{
    public function __construct(private FlowCacheService $flowCache, private SemanticCacheService $semanticCache) {}

    public function prepare(int $botId, int $flowId, string $path, string $expectedCurrentMd5, string $expectedCandidateSha256, string $actor): PromptDeployment
    {
        $this->topLevel();
        $this->actor($actor);
        $this->scope($botId, $flowId);
        $this->hashFormat($expectedCurrentMd5, 32);
        $this->hashFormat($expectedCandidateSha256, 64);

        return DB::transaction(function () use ($botId, $flowId, $path, $expectedCurrentMd5, $expectedCandidateSha256, $actor) {
            [$bot, $flow] = $this->lockServingRows($botId, $flowId);
            $artifact = $this->artifact($path, $expectedCurrentMd5, $expectedCandidateSha256);
            $this->preconditions($bot, $flow, $artifact['manifest']);
            $this->require(md5($flow->system_prompt) === $expectedCurrentMd5, 'source_mismatch');
            $existing = PromptDeployment::where('flow_id', $flowId)->where('candidate_sha256', $expectedCandidateSha256)->lockForUpdate()->first();
            if ($existing) {
                $this->require($existing->rolled_back_at === null, 'candidate_rolled_back');
                $this->require($existing->artifact_path === $artifact['path'] && $existing->manifest_sha256 === $artifact['manifest_sha256'] && $existing->previous_sha256 === hash('sha256', $flow->system_prompt), 'duplicate_mismatch');

                return $existing;
            }

            $deployment = new PromptDeployment;
            $deployment->forceFill([
                'bot_id' => $botId, 'flow_id' => $flowId,
                'version' => $artifact['manifest']['version'],
                'artifact_path' => $artifact['path'], 'manifest_sha256' => $artifact['manifest_sha256'],
                'previous_prompt' => $flow->system_prompt,
                'previous_md5' => md5($flow->system_prompt), 'previous_sha256' => hash('sha256', $flow->system_prompt),
                'candidate_sha256' => $expectedCandidateSha256,
                'serving_model' => $bot->resolvedChatModel(), 'reasoning_effort' => $bot->reasoning_effort ?: 'medium',
                'prepared_by' => $actor, 'status' => 'prepared',
            ])->save();

            return $deployment;
        });
    }

    public function apply(PromptDeployment $deployment, string $actor): void
    {
        $this->topLevel();
        $this->actor($actor);
        $id = $this->identifier($deployment);
        DB::transaction(function () use ($id, $actor) {
            [$bot, $flow, $row] = $this->lockDeployment($id);
            $this->require($row->rolled_back_at === null && $row->status !== 'rolled_back', 'candidate_rolled_back');
            if ($row->apply_cache_verified_at !== null) {
                return;
            }
            $this->require($row->status === 'prepared' || ($row->applied_at !== null && in_array($row->status, ['applied', 'failed']) && in_array($row->failure_stage, [null, 'apply_cache'])), 'invalid_state');
            $artifact = $this->artifact(dirname(base_path()).'/'.$row->artifact_path, $row->previous_md5, $row->candidate_sha256);
            $this->require($artifact['path'] === $row->artifact_path && $artifact['manifest_sha256'] === $row->manifest_sha256 && $artifact['manifest']['version'] === $row->version && $artifact['manifest']['model'] === $row->serving_model && $artifact['manifest']['reasoning'] === $row->reasoning_effort, 'manifest_changed');
            $this->preconditions($bot, $flow, $artifact['manifest'], $row->applied_at === null);
            $expected = $row->applied_at === null ? $row->previous_sha256 : $row->candidate_sha256;
            $this->require(hash('sha256', $flow->system_prompt) === $expected, 'current_prompt_mismatch');
            if ($row->applied_at === null) {
                Flow::withoutTimestamps(fn () => $flow->update(['system_prompt' => $artifact['bytes']]));
                $row->forceFill(['status' => 'applied', 'applied_by' => $actor, 'applied_at' => now(), 'apply_cache_verified_at' => null])->save();
            }
            DB::afterCommit(fn () => $this->verifyCaches($id, $bot->id, 'apply'));
        });
    }

    public function rollback(PromptDeployment $deployment, string $expectedCurrentSha256, string $actor): void
    {
        $this->topLevel();
        $this->actor($actor);
        $this->hashFormat($expectedCurrentSha256, 64);
        $id = $this->identifier($deployment);
        DB::transaction(function () use ($id, $expectedCurrentSha256, $actor) {
            [$bot, $flow, $row] = $this->lockDeployment($id);
            $this->require($expectedCurrentSha256 === $row->candidate_sha256, 'candidate_mismatch');
            if ($row->rollback_cache_verified_at !== null) {
                return;
            }
            $this->require($row->applied_at !== null, 'invalid_state');
            $this->servingPreconditions($bot, $flow, $row->serving_model, $row->reasoning_effort);
            if ($row->rolled_back_at === null) {
                $this->require(in_array($row->status, ['applied', 'failed']) && in_array($row->failure_stage, [null, 'apply_cache']), 'invalid_state');
                $this->require(hash('sha256', $flow->system_prompt) === $expectedCurrentSha256, 'current_prompt_mismatch');
                $backup = $row->previous_prompt;
                $this->require(hash('sha256', $backup) === $row->previous_sha256 && md5($backup) === $row->previous_md5, 'backup_mismatch');
                Flow::withoutTimestamps(fn () => $flow->update(['system_prompt' => $backup]));
                $row->forceFill(['status' => 'rolled_back', 'rolled_back_by' => $actor, 'rolled_back_at' => now(), 'rollback_cache_verified_at' => null, 'failure_stage' => null, 'last_error_code' => null])->save();
            } else {
                $this->require(in_array($row->status, ['rolled_back', 'failed']) && in_array($row->failure_stage, [null, 'rollback_cache']), 'invalid_state');
                $this->require(hash('sha256', $flow->system_prompt) === $row->previous_sha256, 'current_prompt_mismatch');
            }
            DB::afterCommit(fn () => $this->verifyCaches($id, $bot->id, 'rollback'));
        });
    }

    /** Safe hashes/counts only; never prompt text or cache query contents. */
    public function readBack(PromptDeployment $deployment): array
    {
        $this->topLevel();
        $row = PromptDeployment::find($this->identifier($deployment));
        $this->require($row !== null, 'deployment_missing');
        $this->scope($row->bot_id, $row->flow_id);
        $flow = Flow::where('bot_id', $row->bot_id)->find($row->flow_id);
        $cached = $this->flowCache->getDefaultFlow($row->bot_id);

        return [
            'id' => $row->id, 'bot_id' => $row->bot_id, 'flow_id' => $row->flow_id,
            'status' => $row->status, 'failure_stage' => $row->failure_stage, 'last_error_code' => $row->last_error_code,
            'previous_sha256' => $row->previous_sha256, 'candidate_sha256' => $row->candidate_sha256,
            'flow_md5' => $flow ? md5($flow->system_prompt) : null,
            'flow_sha256' => $flow ? hash('sha256', $flow->system_prompt) : null,
            'cached_flow_sha256' => $cached ? hash('sha256', $cached->system_prompt) : null,
            'semantic_count' => $this->semanticCache->countForBot($row->bot_id),
            'apply_cache_verified_at' => $row->apply_cache_verified_at?->toISOString(),
            'rollback_cache_verified_at' => $row->rollback_cache_verified_at?->toISOString(),
        ];
    }

    private function verifyCaches(string $id, int $botId, string $action): void
    {
        $failed = false;
        // Both attempts are mandatory even if either backend is unavailable.
        foreach ([fn () => $this->flowCache->invalidateBot($botId), fn () => $this->semanticCache->clearForBot($botId)] as $invalidate) {
            try {
                $invalidate();
            } catch (Throwable) {
                $failed = true;
            }
        }
        $readback = null;
        try {
            $reference = new PromptDeployment;
            $reference->id = $id;
            $readback = $this->readBack($reference);
        } catch (Throwable) {
            $failed = true;
        }
        $verified = DB::transaction(function () use ($id, $action, $failed, $readback) {
            [$bot, $flow, $row] = $this->lockDeployment($id, true);
            // A concurrent rollback must never be overwritten by an older apply callback.
            $this->require(($action === 'apply' && $row->rolled_back_at === null) || ($action === 'rollback' && $row->rolled_back_at !== null), 'verification_superseded');
            // A slower, failing callback cannot downgrade a concurrent verified retry.
            if ($row->{$action.'_cache_verified_at'} !== null) {
                return true;
            }
            $expected = $action === 'apply' ? $row->candidate_sha256 : $row->previous_sha256;
            $verified = ! $failed
                && $readback['flow_sha256'] === $expected
                && $readback['cached_flow_sha256'] === $expected
                && $readback['semantic_count'] === 0
                && hash('sha256', $flow->system_prompt) === $expected
                && ! $bot->trashed() && ! $flow->trashed()
                && $flow->bot_id === $bot->id
                && $flow->is_default && (int) $bot->default_flow_id === $flow->id
                && ($bot->system_prompt === null || $bot->system_prompt === '')
                && $bot->resolvedChatModel() === $row->serving_model
                && ($bot->reasoning_effort ?: 'medium') === $row->reasoning_effort;
            $row->forceFill($verified ? [
                'status' => $action === 'apply' ? 'applied' : 'rolled_back',
                $action.'_cache_verified_at' => now(), 'failure_stage' => null, 'last_error_code' => null,
            ] : [
                'status' => 'failed', 'failure_stage' => $action.'_cache', 'last_error_code' => 'cache_verification_failed', 'last_failure_at' => now(),
            ])->save();

            return $verified;
        });
        $this->require($verified, 'cache_verification_failed');
    }

    private function lockDeployment(string $id, bool $verifying = false): array
    {
        // All mutating operations use the same bot -> flow -> audit lock order.
        [$bot, $flow] = $this->lockServingRows((int) config('prompt_deployment.bot_id'), (int) config('prompt_deployment.flow_id'), $verifying);
        $row = PromptDeployment::whereKey($id)->lockForUpdate()->first();
        $this->require($row !== null, 'deployment_missing');
        $this->scope($row->bot_id, $row->flow_id);

        return [$bot, $flow, $row];
    }

    private function lockServingRows(int $botId, int $flowId, bool $verifying = false): array
    {
        // Verification must still record failure if a serving row was soft-deleted after commit.
        $bot = Bot::query()->when($verifying, fn ($query) => $query->withTrashed())->whereKey($botId)->lockForUpdate()->first();
        $this->require($bot !== null, 'bot_missing');
        $flow = Flow::query()->when($verifying, fn ($query) => $query->withTrashed())->whereKey($flowId)->lockForUpdate()->first();
        $this->require($flow !== null, 'flow_missing');
        $this->require($verifying || $flow->bot_id === $botId, 'ownership_mismatch');

        return [$bot, $flow];
    }

    private function servingPreconditions(Bot $bot, Flow $flow, string $model, string $reasoning): void
    {
        $this->require($flow->is_default && (int) $bot->default_flow_id === $flow->id, 'default_mismatch');
        $this->require($bot->system_prompt === null || $bot->system_prompt === '', 'bot_prompt_override');
        $this->require($bot->resolvedChatModel() === $model && $model === config('prompt_deployment.serving_model'), 'model_mismatch');
        $this->require(($bot->reasoning_effort ?: 'medium') === $reasoning && $reasoning === config('prompt_deployment.reasoning_effort'), 'reasoning_mismatch');
    }

    private function preconditions(Bot $bot, Flow $flow, array $manifest, bool $checkSource = true): void
    {
        $this->servingPreconditions($bot, $flow, $manifest['model'], $manifest['reasoning']);
        if ($checkSource) {
            $this->require(mb_check_encoding($flow->system_prompt, 'UTF-8') && mb_strlen($flow->system_prompt, 'UTF-8') === $manifest['source']['unicode_characters'], 'source_mismatch');
        }
    }

    /** Only the nested deployment manifest is accepted; evaluation inventories are not deployment manifests. */
    private function artifact(string $path, string $sourceMd5, string $candidateSha): array
    {
        $canonical = $this->containedPath($path);
        $this->require(str_ends_with($canonical, '.txt'), 'artifact_path_invalid');
        $manifestPath = $this->containedPath(substr($canonical, 0, -4).'.manifest.json');
        $bytes = @file_get_contents($canonical);
        $json = @file_get_contents($manifestPath);
        $this->require(is_string($bytes) && is_string($json), 'artifact_unreadable');
        $this->require(mb_check_encoding($bytes, 'UTF-8'), 'artifact_encoding_invalid');
        try {
            $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('manifest_invalid');
        }
        $this->require(is_array($manifest), 'manifest_invalid');
        $this->keys($manifest, ['bot_id', 'flow_id', 'version', 'model', 'reasoning', 'artifact', 'source']);
        $this->require(is_array($manifest['artifact']) && is_array($manifest['source']), 'manifest_invalid');
        $this->keys($manifest['artifact'], ['unicode_characters', 'bytes', 'sha256', 'md5', 'encoding', 'trailing_lf']);
        $this->keys($manifest['source'], ['unicode_characters', 'md5']);
        $this->require($manifest['bot_id'] === (int) config('prompt_deployment.bot_id') && $manifest['flow_id'] === (int) config('prompt_deployment.flow_id'), 'manifest_scope_invalid');
        $this->require(is_string($manifest['version']) && preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,31}\z/D', $manifest['version']) === 1 && basename($canonical, '.txt') === $manifest['version'], 'manifest_version_invalid');
        $this->require($manifest['model'] === config('prompt_deployment.serving_model') && $manifest['reasoning'] === config('prompt_deployment.reasoning_effort'), 'manifest_settings_invalid');
        $this->require($manifest['source']['md5'] === $sourceMd5 && is_int($manifest['source']['unicode_characters']) && $manifest['source']['unicode_characters'] >= 0, 'source_mismatch');
        $this->require($this->artifactMetadataMatches($manifest['artifact'], $bytes), 'artifact_metadata_mismatch');
        $this->require(hash('sha256', $bytes) === $candidateSha, 'candidate_mismatch');
        $relative = substr($canonical, strlen(realpath(dirname(base_path())) ?? '') + 1);
        $this->require(strlen($relative) <= 255, 'artifact_path_invalid');

        return ['bytes' => $bytes, 'manifest' => $manifest, 'manifest_sha256' => hash('sha256', $json), 'path' => $relative];
    }

    private function artifactMetadataMatches(array $metadata, string $bytes): bool
    {
        return $metadata['unicode_characters'] === mb_strlen($bytes, 'UTF-8') && $metadata['bytes'] === strlen($bytes) && $metadata['sha256'] === hash('sha256', $bytes) && $metadata['md5'] === md5($bytes) && $metadata['encoding'] === 'UTF-8' && $metadata['trailing_lf'] === str_ends_with($bytes, "\n");
    }

    private function containedPath(string $path): string
    {
        clearstatcache(true);
        $root = realpath(config('prompt_deployment.artifact_root'));
        $repository = realpath(dirname(base_path()));
        $canonical = realpath($path);
        $this->require($root !== false && $repository !== false && $canonical !== false && is_file($canonical) && str_starts_with($canonical, $root.DIRECTORY_SEPARATOR) && str_starts_with($canonical, $repository.DIRECTORY_SEPARATOR), 'artifact_path_invalid');

        return $canonical;
    }

    private function keys(array $data, array $expected): void
    {
        $keys = array_keys($data);
        sort($keys);
        sort($expected);
        $this->require($keys === $expected, 'manifest_invalid');
    }

    private function identifier(PromptDeployment $deployment): string
    {
        $id = $deployment->getKey();
        $this->require(is_string($id) && Str::isUuid($id), 'deployment_id_invalid');

        return $id;
    }

    private function topLevel(): void
    {
        $this->require(DB::transactionLevel() === 0, 'nested_transaction_forbidden');
    }

    private function scope(int $botId, int $flowId): void
    {
        $this->require($botId === (int) config('prompt_deployment.bot_id') && $flowId === (int) config('prompt_deployment.flow_id'), 'deployment_scope_invalid');
    }

    private function actor(string $actor): void
    {
        $this->require(trim($actor) !== '' && mb_strlen($actor) <= 255 && ! preg_match('/[\x00-\x1f\x7f]/', $actor), 'actor_invalid');
    }

    private function hashFormat(string $hash, int $length): void
    {
        $this->require(preg_match('/\A[0-9a-f]{'.$length.'}\z/D', $hash) === 1, 'hash_invalid');
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new RuntimeException($code);
        }
    }
}
