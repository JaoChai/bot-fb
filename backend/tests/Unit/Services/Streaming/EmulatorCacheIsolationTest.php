<?php

namespace Tests\Unit\Services\Streaming;

use PHPUnit\Framework\TestCase;

/**
 * The flow emulator shares rag_cache with real customers, keyed on bot and
 * query text only. Writing to it would serve an admin's draft answer to a
 * customer on LINE; reading from it would serve the admin a customer's old
 * answer instead of the prompt they are trying out. It does neither.
 *
 * The orchestrator builds its own streaming HTTP client, so the run cannot be
 * faked end to end; this pins the rule at the source level instead.
 */
class EmulatorCacheIsolationTest extends TestCase
{
    public function test_the_emulator_never_touches_the_semantic_cache(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/Services/Streaming/StreamingResponseOrchestrator.php');

        // Matches the property and the SemanticCacheService class alike. Prose in that file
        // writes "semantic cache" as two words so that comments do not trip this.
        $this->assertStringNotContainsStringIgnoringCase('semanticCache', $source);
    }
}
