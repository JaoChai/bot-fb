<?php

namespace Tests\Unit\Services\Streaming;

use PHPUnit\Framework\TestCase;

/**
 * The flow emulator shares rag_cache with real customers. An answer produced
 * while an admin tries out a draft prompt must never be stored there, or a
 * customer asking something similar on LINE would be served the draft.
 *
 * The orchestrator builds its own streaming HTTP client, so the run cannot be
 * faked end to end; this pins the rule at the source level instead.
 */
class EmulatorCacheIsolationTest extends TestCase
{
    public function test_the_emulator_never_writes_to_the_semantic_cache(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/Services/Streaming/StreamingResponseOrchestrator.php');

        $this->assertStringNotContainsString('semanticCache->put(', $source);
    }
}
