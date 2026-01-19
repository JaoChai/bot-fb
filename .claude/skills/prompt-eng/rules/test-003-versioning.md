---
id: test-003-versioning
title: Prompt Versioning
impact: MEDIUM
impactDescription: "Track prompt changes and enable rollback when needed"
category: test
tags: [versioning, history, rollback, audit]
relatedRules: [test-001-ab-testing, design-002-system-prompt]
---

## Why This Matters

Without versioning:
- Can't track what changed when
- No way to rollback bad changes
- Lost history of optimizations
- Can't correlate changes with metrics

## The Problem

Common versioning failures:
- Overwriting prompts without backup
- No audit trail of who changed what
- Can't compare versions
- No rollback mechanism

## Solution

### Prompt Version Model

```php
// PromptVersion.php
class PromptVersion extends Model
{
    protected $fillable = [
        'bot_id',
        'version_number',
        'system_prompt',
        'prompt_config', // JSON: temperature, model, etc.
        'created_by',
        'change_summary',
        'is_active',
        'activated_at',
        'deactivated_at',
        'performance_snapshot', // JSON: metrics at time of version
    ];

    protected $casts = [
        'prompt_config' => 'array',
        'is_active' => 'boolean',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'performance_snapshot' => 'array',
    ];

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Get diff from previous version
    public function getDiffFromPrevious(): ?array
    {
        $previous = PromptVersion::where('bot_id', $this->bot_id)
            ->where('version_number', $this->version_number - 1)
            ->first();

        if (!$previous) {
            return null;
        }

        return [
            'prompt_diff' => $this->calculateTextDiff(
                $previous->system_prompt,
                $this->system_prompt
            ),
            'config_diff' => $this->calculateConfigDiff(
                $previous->prompt_config,
                $this->prompt_config
            ),
        ];
    }

    private function calculateTextDiff(string $old, string $new): array
    {
        // Simple line-by-line diff
        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);

        $added = array_diff($newLines, $oldLines);
        $removed = array_diff($oldLines, $newLines);

        return [
            'added_lines' => count($added),
            'removed_lines' => count($removed),
            'added' => $added,
            'removed' => $removed,
        ];
    }
}
```

### Versioning Service

```php
// PromptVersioningService.php
class PromptVersioningService
{
    public function createVersion(
        Bot $bot,
        string $systemPrompt,
        array $config,
        User $user,
        string $changeSummary
    ): PromptVersion {
        $latestVersion = $this->getLatestVersion($bot);
        $nextNumber = $latestVersion ? $latestVersion->version_number + 1 : 1;

        // Capture current performance before making change
        $performanceSnapshot = $this->capturePerformance($bot);

        return PromptVersion::create([
            'bot_id' => $bot->id,
            'version_number' => $nextNumber,
            'system_prompt' => $systemPrompt,
            'prompt_config' => $config,
            'created_by' => $user->id,
            'change_summary' => $changeSummary,
            'is_active' => false, // Not active until explicitly activated
            'performance_snapshot' => $performanceSnapshot,
        ]);
    }

    public function activate(PromptVersion $version): void
    {
        DB::transaction(function () use ($version) {
            // Deactivate current active version
            PromptVersion::where('bot_id', $version->bot_id)
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'deactivated_at' => now(),
                ]);

            // Activate new version
            $version->update([
                'is_active' => true,
                'activated_at' => now(),
            ]);

            // Update bot settings
            $version->bot->settings->update([
                'system_prompt' => $version->system_prompt,
                'model' => $version->prompt_config['model'] ?? null,
                'temperature' => $version->prompt_config['temperature'] ?? null,
            ]);

            // Log the change
            activity()
                ->performedOn($version->bot)
                ->causedBy(auth()->user())
                ->withProperties([
                    'version' => $version->version_number,
                    'change_summary' => $version->change_summary,
                ])
                ->log('prompt_version_activated');
        });
    }

    public function rollback(Bot $bot, int $targetVersion): PromptVersion
    {
        $version = PromptVersion::where('bot_id', $bot->id)
            ->where('version_number', $targetVersion)
            ->firstOrFail();

        // Create a new version based on the old one
        $rollbackVersion = $this->createVersion(
            $bot,
            $version->system_prompt,
            $version->prompt_config,
            auth()->user(),
            "Rollback to version {$targetVersion}"
        );

        // Activate the rollback version
        $this->activate($rollbackVersion);

        return $rollbackVersion;
    }

    public function getVersionHistory(Bot $bot): Collection
    {
        return PromptVersion::where('bot_id', $bot->id)
            ->with('creator')
            ->orderBy('version_number', 'desc')
            ->get();
    }

    public function compareVersions(
        PromptVersion $version1,
        PromptVersion $version2
    ): array {
        return [
            'prompt_diff' => $this->diffText(
                $version1->system_prompt,
                $version2->system_prompt
            ),
            'config_diff' => $this->diffConfig(
                $version1->prompt_config,
                $version2->prompt_config
            ),
            'performance_comparison' => [
                'v1' => $version1->performance_snapshot,
                'v2' => $version2->performance_snapshot,
            ],
        ];
    }

    private function capturePerformance(Bot $bot): array
    {
        $metrics = new MetricsQueryService();

        return $metrics->getBotMetrics(
            $bot,
            now()->subDays(7),
            now()
        );
    }

    private function getLatestVersion(Bot $bot): ?PromptVersion
    {
        return PromptVersion::where('bot_id', $bot->id)
            ->orderBy('version_number', 'desc')
            ->first();
    }
}
```

### API Endpoints

```php
// PromptVersionController.php
class PromptVersionController extends Controller
{
    public function index(Bot $bot)
    {
        $this->authorize('manage', $bot);

        $versions = $this->versioningService->getVersionHistory($bot);

        return PromptVersionResource::collection($versions);
    }

    public function store(Bot $bot, CreatePromptVersionRequest $request)
    {
        $this->authorize('manage', $bot);

        $version = $this->versioningService->createVersion(
            $bot,
            $request->system_prompt,
            $request->config ?? [],
            $request->user(),
            $request->change_summary
        );

        return new PromptVersionResource($version);
    }

    public function activate(Bot $bot, PromptVersion $version)
    {
        $this->authorize('manage', $bot);

        $this->versioningService->activate($version);

        return response()->json(['message' => 'Version activated']);
    }

    public function rollback(Bot $bot, RollbackRequest $request)
    {
        $this->authorize('manage', $bot);

        $version = $this->versioningService->rollback(
            $bot,
            $request->target_version
        );

        return new PromptVersionResource($version);
    }

    public function compare(Bot $bot, CompareVersionsRequest $request)
    {
        $this->authorize('manage', $bot);

        $v1 = PromptVersion::findOrFail($request->version_1);
        $v2 = PromptVersion::findOrFail($request->version_2);

        $comparison = $this->versioningService->compareVersions($v1, $v2);

        return response()->json($comparison);
    }
}
```

## Version History UI

```
Version History for "Support Bot"

v5 (Active) - 2024-01-15
  "Added product recommendation examples"
  By: John Doe
  Performance: Rating 4.2, Resolution 75%

v4 - 2024-01-10
  "Improved Thai language support"
  By: Jane Smith
  Performance: Rating 4.0, Resolution 72%
  [Rollback] [Compare with Active]

v3 - 2024-01-05
  "Added constraints for pricing questions"
  By: John Doe
  Performance: Rating 3.8, Resolution 68%
  [Rollback] [Compare with Active]
```

## Testing

```php
public function test_version_number_increments(): void
{
    $bot = Bot::factory()->create();
    $service = new PromptVersioningService();

    $v1 = $service->createVersion($bot, 'Prompt 1', [], $this->user, 'Initial');
    $v2 = $service->createVersion($bot, 'Prompt 2', [], $this->user, 'Update');

    $this->assertEquals(1, $v1->version_number);
    $this->assertEquals(2, $v2->version_number);
}

public function test_rollback_creates_new_version(): void
{
    $bot = Bot::factory()->create();
    $service = new PromptVersioningService();

    $v1 = $service->createVersion($bot, 'Original', [], $this->user, 'Initial');
    $v2 = $service->createVersion($bot, 'Changed', [], $this->user, 'Change');
    $service->activate($v2);

    $rollback = $service->rollback($bot, 1);

    $this->assertEquals(3, $rollback->version_number);
    $this->assertEquals('Original', $rollback->system_prompt);
}
```

## Project-Specific Notes

**BotFacebook Context:**
- Versions stored in prompt_versions table
- Max 50 versions kept per bot (older archived)
- Performance snapshot captured weekly
- Rollback available in bot settings UI
