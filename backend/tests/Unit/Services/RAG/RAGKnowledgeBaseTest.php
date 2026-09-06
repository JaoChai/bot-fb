<?php

namespace Tests\Unit\Services\RAG;

use App\Models\Bot;
use App\Services\FlowCacheService;
use App\Services\HybridSearchService;
use App\Services\RAG\RAGKnowledgeBase;
use Tests\TestCase;

class RAGKnowledgeBaseTest extends TestCase
{
    private RAGKnowledgeBase $kb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kb = new RAGKnowledgeBase(
            $this->createMock(HybridSearchService::class),
            $this->createMock(FlowCacheService::class),
            null,
        );
    }

    public function test_empty_results_format_to_empty_string(): void
    {
        $this->assertSame('', $this->kb->formatKnowledgeBaseContext(collect()));
    }

    public function test_thai_template_lists_each_chunk_with_relevance(): void
    {
        config(['rag.context_template' => 'thai']);
        $results = collect([
            ['similarity' => 0.876, 'document_name' => 'price.pdf', 'content' => 'ราคา 100 บาท'],
        ]);

        $context = $this->kb->formatKnowledgeBaseContext($results);

        $this->assertStringContainsString('ความเกี่ยวข้อง 88%', $context);
        $this->assertStringContainsString('📄 price.pdf', $context);
        $this->assertStringContainsString('ราคา 100 บาท', $context);
    }

    public function test_api_key_falls_back_to_config_when_bot_has_no_user_settings(): void
    {
        config(['services.openrouter.api_key' => 'cfg-key']);

        $this->assertSame('cfg-key', $this->kb->getApiKeyForBot(new Bot));
    }
}
