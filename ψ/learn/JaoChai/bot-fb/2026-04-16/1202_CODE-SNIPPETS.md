# bot-fb Code Snippets Reference — April 16, 2026

> Deep-dive learning session: RAG orchestration, stock management, VIP detection, caching patterns, frontend state

---

## 1. RAG Orchestration Flow — Main Chat Pipeline

**backend/app/Services/RAGService.php:54-277**

The core RAG response generation pipeline. Demonstrates multi-layer orchestration: semantic cache check → intent analysis → KB retrieval → smart model routing → agentic mode branching → response generation → cache storage.

```php
/**
 * Generate a response using multi-model architecture.
 *
 * Flow:
 * 1. Analyze intent using Decision Model
 * 2. Detect question complexity for Chain-of-Thought
 * 3. Get KB context if intent is 'knowledge' and KB enabled
 * 4. Generate response using Chat Model (with CoT if complex)
 *
 * @return array Response with content, usage stats, intent, and RAG metadata
 */
public function generateResponse(
    Bot $bot,
    string $userMessage,
    array $conversationHistory = [],
    ?Conversation $conversation = null,
    ?Flow $flow = null,
    ?string $apiKeyOverride = null
): array {
    // Get API key first (used for both decision and chat models)
    $apiKey = $apiKeyOverride ?? $this->getApiKeyForBot($bot);
    $bot->loadMissing(['defaultFlow.knowledgeBases']);

    // Step 0: Check Semantic Cache first (fastest path)
    // Skip cache for context-dependent messages to prevent cross-conversation contamination
    $skipCache = $this->shouldSkipCache($userMessage, $conversation, $conversationHistory);

    if (! $skipCache && $this->semanticCache?->isEnabled()) {
        $cachedResponse = $this->semanticCache->get($bot, $userMessage, $apiKey);
        if ($cachedResponse) {
            Log::debug('RAGService: Cache hit, returning cached response', [
                'bot_id' => $bot->id,
                'cache_match_type' => $cachedResponse['cache_match_type'],
                'cache_similarity' => $cachedResponse['cache_similarity'] ?? null,
            ]);

            return [
                'content' => $cachedResponse['content'],
                'from_cache' => true,
                'cache_match_type' => $cachedResponse['cache_match_type'],
                'cache_similarity' => $cachedResponse['cache_similarity'],
                'intent' => $cachedResponse['metadata']['intent'] ?? ['intent' => 'cached', 'confidence' => 1.0],
                'rag' => $cachedResponse['metadata']['rag'] ?? [],
                'complexity' => $cachedResponse['metadata']['complexity'] ?? [],
                'models_used' => $cachedResponse['metadata']['models_used'] ?? [],
                'model' => $cachedResponse['metadata']['models_used']['chat'] ?? 'cached',
                'usage' => [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                ],
            ];
        }
    }

    // Step 1: Analyze intent using Decision Model
    $intent = $this->intentAnalysis->analyzeIntent($bot, $userMessage, [
        'validIntents' => ['chat', 'knowledge', 'flow'],
        'includeExamples' => true,
        'apiKey' => $apiKey,
    ]);

    // Step 2: Detect complexity for Chain-of-Thought
    $complexity = $this->detectComplexity($userMessage);

    // Step 3: Initialize KB metadata
    $kbContext = '';
    $kbMetadata = [
        'enabled' => false,
        'results_count' => 0,
        'chunks_used' => [],
    ];

    $isSimpleMessage = mb_strlen($userMessage) <= 30 && preg_match(self::SIMPLE_MESSAGE_PATTERN, trim($userMessage));

    // Step 4: Get KB context if intent is 'knowledge' and KB enabled
    $shouldUseKB = ! $isSimpleMessage
        && ($intent['intent'] === 'knowledge' || isset($intent['skipped']))
        && $this->shouldUseKnowledgeBase($bot);

    if ($shouldUseKB) {
        $searchQuery = $intent['search_query'] ?? $userMessage;
        $kbContext = $this->getKnowledgeBaseContext(
            $bot,
            $searchQuery,
            $kbMetadata
        );
    }

    // Step 5: Extract memory notes (type='memory' only) from conversation
    $memoryNotes = [];
    if ($conversation) {
        $memoryNotes = collect($conversation->memory_notes ?? [])
            ->where('type', 'memory')
            ->pluck('content')
            ->all();
    }

    // Step 6: Build enhanced system prompt with memory notes, KB context, and multiple bubbles
    // Priority: Bot system_prompt > Flow system_prompt > Default
    $systemPrompt = $this->buildEnhancedPrompt(
        $this->getSystemPromptForBot($bot),
        $kbContext,
        $bot,
        $memoryNotes
    );

    // Step 7: Add Chain-of-Thought instruction if question is complex
    if ($complexity['is_complex'] && config('rag.chain_of_thought.enabled', true)) {
        $language = $this->detectLanguage($userMessage);
        $systemPrompt .= $this->buildChainOfThoughtInstruction($language);

        Log::debug('Chain-of-Thought activated', [
            'bot_id' => $bot->id,
            'complexity_score' => $complexity['score'],
            'reasons' => $complexity['reasons'],
            'language' => $language,
        ]);
    }

    // Step 8: Get chat models (Smart Routing if enabled)
    $chatModel = $this->resolveSmartChatModel($bot, $intent, $complexity);
    $fallbackChatModel = $this->getFallbackChatModelForBot($bot);

    // Step 9: Resolve flow (used for agentic check + LLM params)
    $resolvedFlow = $flow ?? $this->flowCacheService->getDefaultFlow($bot->id);

    // Step 9b: Calculate max tokens — Flow takes priority, Bot as fallback
    $maxTokens = $resolvedFlow?->max_tokens ?? $bot->llm_max_tokens;
    if ($complexity['is_complex']) {
        $multiplier = config('rag.chain_of_thought.max_tokens_multiplier', 1.5);
        $maxTokens = (int) min($maxTokens * $multiplier, 4096);
    }

    // Step 9c: Adaptive temperature based on intent
    $baseTemp = $resolvedFlow?->temperature ?? $bot->llm_temperature;
    $tempConfig = config('rag.adaptive_temperature');
    if ($tempConfig['enabled'] ?? true) {
        $temperature = match ($intent['intent']) {
            'knowledge' => min($baseTemp, $tempConfig['knowledge_max'] ?? 0.3),
            'chat' => max($baseTemp, $tempConfig['chat_min'] ?? 0.6),
            default => $baseTemp,
        };
    } else {
        $temperature = $baseTemp;
    }

    // Step 10: Generate response — Agentic or Standard
    $isAgentic = $resolvedFlow
        && $resolvedFlow->agentic_mode
        && ! empty($resolvedFlow->enabled_tools)
        && $this->toolService;

    if ($isAgentic) {
        // Delegate to AgentLoopService (handles prompt, tools, safety)
        $agentConfig = new \App\Services\Agent\AgentLoopConfig(
            bot: $bot,
            flow: $resolvedFlow,
            userMessage: $userMessage,
            conversationHistory: $conversationHistory,
            apiKey: $apiKey,
            autoRejectHitl: true,
            kbContext: $kbContext,
            memoryNotes: $memoryNotes,
        );
        $agentCallbacks = new \App\Services\Agent\SyncAgentCallbacks;
        $agentResult = $this->getAgentLoopService()->run($agentConfig, $agentCallbacks);

        $result = [
            'content' => $agentResult->content,
            'model' => $agentResult->model,
            'usage' => $agentResult->usage,
            'cost' => $agentResult->cost,
            'agentic' => $agentResult->agentic,
        ];
    } else {
        $result = $this->openRouter->generateBotResponse(
            userMessage: $userMessage,
            systemPrompt: $systemPrompt,
            conversationHistory: $conversationHistory,
            model: $chatModel,
            fallbackModel: $fallbackChatModel,
            temperature: $temperature,
            maxTokens: $maxTokens,
            apiKeyOverride: $apiKey
        );
    }

    // Add metadata to result
    $result['intent'] = $intent;
    $result['rag'] = $kbMetadata;
    $result['complexity'] = $complexity;
    $result['models_used'] = [
        'decision' => $intent['model_used'] ?? null,
        'chat' => $result['model'] ?? $chatModel,
    ];
    $result['smart_routing'] = [
        'enabled' => (bool) $bot->use_confidence_cascade,
        'routed_model' => $chatModel,
        'complexity_source' => isset($intent['complexity']) ? 'enhanced_decision' : 'heuristic',
        'complexity_result' => $intent['complexity'] ?? ($complexity['is_complex'] ? 'complex' : 'simple'),
    ];
    $result['from_cache'] = false;

    // Step 10: Save to Semantic Cache for future similar queries
    // Skip saving context-dependent responses to prevent cross-conversation contamination
    if (! $skipCache && $this->semanticCache?->isEnabled() && ! empty($result['content'])) {
        try {
            $this->semanticCache->put(
                $bot,
                $userMessage,
                $result['content'],
                [
                    'intent' => $intent,
                    'rag' => $kbMetadata,
                    'complexity' => $complexity,
                    'models_used' => $result['models_used'],
                ],
                $apiKey
            );
        } catch (\Exception $e) {
            // Cache save failure should not break the response
            Log::warning('RAGService: Failed to save to cache', [
                'bot_id' => $bot->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    return $result;
}
```

**Key Design:**
- **Semantic cache first**: Fast-path for repeated questions avoids expensive LLM calls.
- **Intent analysis**: Decides KB usage route (chat/knowledge/flow intent).
- **Complexity detection**: Triggers Chain-of-Thought for multi-step reasoning.
- **Memory notes extraction**: Filters `type='memory'` from conversation notes (see VIP section).
- **Adaptive temperature**: Knowledge queries run cooler (0.3) for precision; chat warmer (0.6) for personality.
- **Agentic branching**: If flow has tools enabled, delegates to AgentLoopService instead of simple LLM call.
- **Stock injection**: Always applied (see section 3 below).

---

## 2. Stock Management — Double Injection Pattern

**backend/app/Services/StockInjectionService.php:12-67**

Two-point injection to prevent LLM hallucination of out-of-stock product sales. Called by RAGService::buildEnhancedPrompt (line 404).

```php
class StockInjectionService
{
    public function getStockStatus(): Collection
    {
        return Cache::remember(ProductStock::STOCK_CACHE_KEY, 300, function () {
            return ProductStock::orderBy('display_order')->get();
        });
    }

    public function getOutOfStockProducts(): Collection
    {
        return $this->getStockStatus()->where('in_stock', false);
    }

    /**
     * Build header injection for LLM prompt.
     * Lists out-of-stock and in-stock products with explicit "DO NOT SELL" instruction.
     */
    public function buildStockInjection(Collection $stocks): string
    {
        if ($stocks->isEmpty()) {
            return '';
        }

        $outOfStock = $stocks->where('in_stock', false);
        $inStock = $stocks->where('in_stock', true);

        $lines = ['⛔⛔⛔ STOCK STATUS (ข้อมูลล่าสุดจากระบบ — ยึดข้อมูลนี้เหนือทุกอย่าง):'];

        if ($outOfStock->isNotEmpty()) {
            $items = $outOfStock->map(function ($p) {
                $aliases = implode(', ', $p->aliases ?? []);
                return $aliases ? "{$p->name} (รวม: {$aliases})" : $p->name;
            })->implode(', ');
            $lines[] = "[สินค้าที่หมดชั่วคราว]: {$items}";
        }

        if ($inStock->isNotEmpty()) {
            $items = $inStock->map(fn ($p) => $p->name)->implode(', ');
            $lines[] = "[สินค้าที่มีพร้อมส่ง]: {$items}";
        }

        $lines[] = 'ห้ามขาย/เพิ่มตะกร้า/สร้างออเดอร์สินค้าที่หมด stock เด็ดขาด! (ตอบราคาและรายละเอียดได้ถ้าลูกค้าถาม แต่ต้องแจ้งว่าหมดชั่วคราว)';

        return implode("\n", $lines);
    }

    /**
     * Build footer reminder (highest LLM attention = right before user msg).
     * Double-checks stock refusal even if prompt missed it earlier.
     */
    public function buildStockReminder(Collection $stocks): string
    {
        $outOfStock = $stocks->where('in_stock', false);

        if ($outOfStock->isEmpty()) {
            return '';
        }

        $names = $outOfStock->map(fn ($p) => $p->name)->implode(', ');

        return "⛔ STOCK REMINDER: สินค้าหมด stock → {$names} — ห้ามขาย/เพิ่มตะกร้า/สร้างออเดอร์เด็ดขาด! ตอบราคา/รายละเอียดได้ถ้าลูกค้าถาม + ต้องแจ้งว่าหมดชั่วคราว";
    }

    /**
     * Wrap a prompt with stock header + reminder. Used by test/emulator endpoints.
     */
    public function injectStockStatus(string $prompt): string
    {
        $stocks = $this->getStockStatus();

        $result = '';
        $stockInjection = $this->buildStockInjection($stocks);
        if (! empty($stockInjection)) {
            $result .= $stockInjection."\n---\n\n";
        }

        $result .= $prompt;

        $stockReminder = $this->buildStockReminder($stocks);
        if (! empty($stockReminder)) {
            $result .= "\n\n".$stockReminder;
        }

        return $result;
    }
}
```

**Double-Injection Design (from RAGService::buildEnhancedPrompt:403-434):**

```php
// Always inject stock — conditional injection caused sales of out-of-stock products
$stocks = $this->stockInjectionService->getStockStatus();
$hasOutOfStock = $stocks->where('in_stock', false)->isNotEmpty();

if ($hasOutOfStock) {
    $stockInjection = $this->stockInjectionService->buildStockInjection($stocks);
    if (! empty($stockInjection)) {
        $prompt .= $stockInjection."\n---\n\n";  // ← HEADER INJECTION
    }
}

$prompt .= $basePrompt;

if (! empty($kbContext)) {
    $prompt .= "\n\n".$kbContext;
}

if ($bot) {
    $bubblesService = app(MultipleBubblesService::class);
    $instruction = $bubblesService->buildPromptInstruction($bot);
    if (! empty($instruction)) {
        $prompt .= "\n".$instruction;
    }
}

// Stock reminder at END of prompt — closest to user message = highest LLM attention
if ($hasOutOfStock) {
    $stockReminder = $this->stockInjectionService->buildStockReminder($stocks);
    if (! empty($stockReminder)) {
        $prompt .= "\n\n".$stockReminder;  // ← FOOTER REMINDER
    }
}
```

**Key Design:**
- **Header injection**: Lists all products with stock status (highest authority position).
- **Footer reminder**: Right before user message = highest recency bias in transformer attention.
- **Aliases mapping**: Includes product aliases (e.g., "BM", "Battery Module") to catch abbreviations.
- **Cache 5-min TTL**: Updates stock status frequently without DB thrashing.
- **Conditional always-on**: Even if flow/bot has `kb_disabled`, stock is ALWAYS injected (learned from #124 incident).

---

## 3. Flow Cache Pattern — 30-Min TTL with Invalidation

**backend/app/Services/FlowCacheService.php:1-79**

Caching layer for Flow queries, reducing DB hits for frequently accessed flow configs (default flow lookup happens on every message).

```php
class FlowCacheService
{
    /**
     * Cache TTL in seconds (30 minutes).
     */
    private const CACHE_TTL = 1800;

    /**
     * Get the default flow for a bot with caching.
     */
    public function getDefaultFlow(int $botId): ?Flow
    {
        return Cache::remember(
            $this->getDefaultFlowKey($botId),
            self::CACHE_TTL,
            fn () => Flow::where('bot_id', $botId)
                ->where('is_default', true)
                ->first()
        );
    }

    /**
     * Check if bot has any flows (cached).
     */
    public function hasFlows(int $botId): bool
    {
        return Cache::remember(
            $this->getHasFlowsKey($botId),
            self::CACHE_TTL,
            fn () => Flow::where('bot_id', $botId)->exists()
        );
    }

    /**
     * Invalidate all cache for a specific bot.
     * Call this when flows are created, updated, or deleted.
     */
    public function invalidateBot(int $botId): void
    {
        Cache::forget($this->getDefaultFlowKey($botId));
        Cache::forget($this->getHasFlowsKey($botId));
    }

    /**
     * Invalidate only the default flow cache.
     * Call this when default flow status changes.
     */
    public function invalidateDefaultFlow(int $botId): void
    {
        Cache::forget($this->getDefaultFlowKey($botId));
    }

    /**
     * Get cache key for default flow.
     */
    private function getDefaultFlowKey(int $botId): string
    {
        return "bot:{$botId}:default_flow";
    }

    /**
     * Get cache key for has-flows check.
     */
    private function getHasFlowsKey(int $botId): string
    {
        return "bot:{$botId}:has_flows";
    }
}
```

**Key Design:**
- **Remember() pattern**: Cache miss triggers closure (DB query), then caches 30 min.
- **Granular invalidation**: Separate methods for full invalidate vs. default-only.
- **Cache keys namespaced**: `bot:{id}:default_flow` format prevents collision.
- **Flow determines**: max_tokens, temperature, agentic_mode, enabled_tools, KB attachments.

---

## 4. VIP Detection — Memory Notes + Dynamic Badge

**backend/app/Services/PaymentFlexService.php:88-102**

VIP detection scans conversation memory_notes for VIP indicator, used to style Flex Messages (gold color for VIP vs. green for normal).

```php
/**
 * Check if conversation belongs to a VIP customer.
 * Looks for "VIP" keyword in memory_notes.
 * 
 * Memory notes can be:
 * - Array of {type, content} objects: [{type:'memory', content:'VIP'}]
 * - Object with vip flag: {vip: true}
 * 
 * This service checks both patterns for backward compatibility.
 */
public function isVipConversation(?Conversation $conversation): bool
{
    if ($conversation === null) {
        return false;
    }

    $memoryNotes = $conversation->memory_notes;
    if (empty($memoryNotes)) {
        return false;
    }

    // Pattern 1: Array of note objects with type + content
    if (is_array($memoryNotes)) {
        foreach ($memoryNotes as $note) {
            if (is_array($note) && isset($note['content'])) {
                if (stripos($note['content'], 'VIP') !== false) {
                    return true;
                }
            }
        }
    }

    // Pattern 2: Object with vip flag (legacy)
    if (is_object($memoryNotes) && isset($memoryNotes->vip)) {
        return (bool) $memoryNotes->vip;
    }

    // Pattern 3: String contains VIP keyword
    if (is_string($memoryNotes) && stripos($memoryNotes, 'VIP') !== false) {
        return true;
    }

    return false;
}
```

**Integration in Flex Builder:**

```php
// From PaymentFlexService::tryConvertToFlex:42
$isVip = $this->isVipConversation($conversation);

// Step 4: Payment message (existing)
if ($this->isPaymentMessage($text)) {
    $data = $this->parsePaymentData($text);
    if ($data !== null) {
        return $this->safeBuildFlex($text, $this->buildFlexMessage($data, $isVip));
        // ↑ Uses $isVip to pick color: #D4A017 (gold) vs #1DB446 (green)
    }
}
```

**Key Design:**
- **Backward compatibility**: Handles both array and object memory_notes (see CLAUDE.md gotcha).
- **Case-insensitive**: `stripos()` catches "VIP", "vip", "Vip".
- **Flex color theme**: VIP=gold (#D4A017), Normal=green (#1DB446), Confirm=orange (#FF6B00).

---

## 5. Payment Flex Detection — Keyword-Based Routing

**backend/app/Services/PaymentFlexService.php:36-86**

Converts payment/order text responses into LINE Flex Messages with order summaries, bank account details, and QR codes. Five detection routes:

```php
/**
 * Try to convert payment text to LINE Flex Message.
 * Returns Flex array if payment detected, or original text as fallback.
 *
 * Five routes:
 * 1. isPaymentMessage()        → Order summary + bank details
 * 2. isSupportDelayMessage()   → Support notifying customer wait time
 * 3. isTermsMessage()          → Terms/conditions link
 * 4. isVerifySuccessMessage()  → Payment receipt confirmation
 * 5. isConfirmMessage()        → Order confirmation (least specific)
 *
 * @return string|array Original text or Flex message array
 */
public function tryConvertToFlex(string $text, ?Conversation $conversation = null): string|array
{
    // Strip markdown bold before regex parsing (LINE doesn't render markdown)
    $text = str_replace('**', '', $text);

    try {
        $isVip = $this->isVipConversation($conversation);

        // Step 4: Payment message (existing)
        if ($this->isPaymentMessage($text)) {
            $data = $this->parsePaymentData($text);
            if ($data !== null) {
                return $this->safeBuildFlex($text, $this->buildFlexMessage($data, $isVip));
            }
        }

        // Step 2.5: Support delay warning
        if ($this->isSupportDelayMessage($text)) {
            return $this->safeBuildFlex($text, $this->buildSupportDelayFlexMessage($isVip));
        }

        // Step 3: Terms message (always uses fixed TERMS_URL)
        if ($this->isTermsMessage($text)) {
            return $this->safeBuildFlex($text, $this->buildTermsFlexMessage());
        }

        // Step 5: Verify success message
        if ($this->isVerifySuccessMessage($text)) {
            $data = $this->parseVerifyData($text);
            if ($data !== null) {
                return $this->safeBuildFlex($text, $this->buildVerifyFlexMessage($data, $isVip));
            }
        }

        // Step 2: Confirm message (least specific — check last)
        if ($this->isConfirmMessage($text)) {
            $data = $this->parseConfirmData($text);
            if ($data !== null) {
                return $this->safeBuildFlex($text, $this->buildConfirmFlexMessage($data, $isVip));
            }
        }

        return $text;
    } catch (\Throwable $e) {
        Log::warning('PaymentFlexService: fallback to text', [
            'error' => $e->getMessage(),
        ]);

        return $text;
    }
}
```

**Key Design:**
- **Route ordering**: Most-specific (payment) first, least-specific (confirm) last to avoid false positives.
- **Fallback to text**: If Flex JSON exceeds MAX_FLEX_SIZE (30KB) or parsing fails, returns original text.
- **VIP styling**: Gold color (#D4A017) for VIP; green (#1DB446) for normal customers.
- **Markdown strip**: LINE Flex doesn't render markdown bold (`**`), so pre-strip to prevent confusion.

---

## 6. StockGuard 3-Layer Defense — Post-Generation Validation

**backend/app/Services/StockGuardService.php:10-75**

Three-layer post-generation guard that catches LLM hallucinations about out-of-stock products. Called after response generation but before sending to user.

```php
/**
 * Post-generation guard that validates LLM responses against current stock status.
 *
 * If the LLM tries to sell an out-of-stock product (despite prompt instructions),
 * this guard replaces the response with a stock-out message.
 * 
 * LAYER 1: Detect if response MENTIONS + SELLS out-of-stock product
 * LAYER 2: Distinguish between selling intent vs. informational mention
 * LAYER 3: Upsell stripping (if only upsell violates stock, keep main product)
 */
public function validate(string $response, string $userMessage = ''): array
{
    if (! config('rag.stock_guard.enabled', true)) {
        return ['content' => $response, 'blocked' => false, 'blocked_products' => []];
    }

    $outOfStock = $this->stockInjection->getOutOfStockProducts();
    if ($outOfStock->isEmpty()) {
        return ['content' => $response, 'blocked' => false, 'blocked_products' => []];
    }

    // Cap response length for regex to prevent ReDoS on very long LLM output.
    // Selling keywords (price, cart, payment) always appear in the first portion.
    $responseToCheck = mb_substr($response, 0, 2000);

    // LAYER 1: Detect violations (product mentioned + selling context detected)
    $violations = $this->detectViolations($responseToCheck, $outOfStock);

    if (empty($violations)) {
        return ['content' => $response, 'blocked' => false, 'blocked_products' => []];
    }

    // LAYER 2: Check if ALL violations are upsell-only (not main product being sold)
    // Upsell = product mentioned as addon/suggestion, not primary sale
    $allUpsell = $this->areAllViolationsUpsell($responseToCheck, $violations, $outOfStock);

    if ($allUpsell) {
        // LAYER 3: Strip upsell block, keep main product
        Log::info('StockGuard: stripped upsell for out-of-stock products', [
            'violations' => $violations,
            'user_message' => mb_substr(str_replace(["\n", "\r"], ' ', $userMessage), 0, 200),
        ]);

        $stripped = $this->stripUpsellBlock($response, $violations, $outOfStock);

        return ['content' => $stripped, 'blocked' => false, 'blocked_products' => []];
    }

    // Full block: LLM tried to SELL out-of-stock product as primary item
    Log::warning('StockGuard: blocked out-of-stock sale', [
        'violations' => $violations,
        'original_response_preview' => mb_substr($response, 0, 300),
        'user_message' => mb_substr(str_replace(["\n", "\r"], ' ', $userMessage), 0, 200),
    ]);

    $allStocks = $this->stockInjection->getStockStatus();
    $replacement = $this->buildReplacementResponse($violations, $allStocks);

    return [
        'content' => $replacement,
        'blocked' => true,
        'blocked_products' => $violations,
    ];
}

/**
 * LAYER 1: Detect if response is SELLING (not just mentioning) out-of-stock products.
 * 
 * Returns list of out-of-stock product names that were found in selling context.
 */
protected function detectViolations(string $response, Collection $outOfStock): array
{
    $violations = [];

    // Skip guard for PAYMENT INSTRUCTIONS (they list products as order line items)
    $isPayment = $this->isPaymentInstruction($response);

    foreach ($outOfStock as $product) {
        $names = array_merge([$product->name], $product->aliases ?? []);

        foreach ($names as $name) {
            if (mb_strlen($name) < 2) {
                continue;
            }

            if (mb_stripos($response, $name) === false) {
                continue;  // Product name not found, skip
            }

            // Product name found — check both refusal and selling contexts
            $isRefused = $this->isRefusingContext($response, $name);
            $isSelling = $this->isSellingContext($response, $name);

            // LAYER 2a: Payment line items don't count as violations
            // E.g., "Payment: BM x1 @ 1100฿" in an order confirmation is allowed
            if ($isSelling && $isPayment && $this->isOrderLineItem($response, $name)) {
                continue;
            }

            // LAYER 2b: Informational + refusal = allowed
            // E.g., "BM ราคา 1,100 บาท แต่หมดชั่วคราว" (mentions price but refuses)
            // UNLESS there are active selling keywords (cart/order/recommendation)
            if ($isSelling && $isRefused) {
                if ($this->isActivelySelling($response, $name)) {
                    $violations[] = $product->name;
                    break;
                }

                continue;
            }

            // Selling without refusal → violation
            if ($isSelling) {
                $violations[] = $product->name;
                break;
            }

            // Refusal without selling → skip
            if ($isRefused) {
                continue;
            }
        }
    }

    return array_unique($violations);
}

/**
 * LAYER 2: Check if response is correctly REFUSING to sell a SPECIFIC product.
 * 
 * Refusal keywords must appear within proximity of the product name,
 * not just anywhere in the response. This prevents bypass when LLM refuses
 * one product but sells another in the same message.
 */
protected function isRefusingContext(string $response, string $productName): bool
{
    $quotedName = preg_quote($productName, '/');

    $refusalPatterns = [
        // Refusal keyword near product name (within 40 chars)
        "/{$quotedName}.{0,40}(หมด|ไม่มี|ไม่สามารถ|ปิดการขาย|out.of.stock)/iu",
    ];

    // ... (more patterns)
}
```

**Three Layers Explained:**
1. **Detect violations**: Product name + selling context found in response.
2. **Distinguish intent**: Is it informational (answering price question)? Or selling?
3. **Upsell stripping**: If only upsell violates stock, remove that block, keep main response.

---

## 7. Webhook Receiver → Job Dispatch Pipeline

**backend/app/Http/Controllers/Webhook/LINEWebhookController.php:20-100**

Entry point for LINE webhooks. Validates signature, parses events, dispatches async ProcessLINEWebhook job per event.

```php
/**
 * Handle incoming LINE webhook.
 * 
 * Flow:
 * 1. Find bot by webhook token (token = bot identifier)
 * 2. Validate LINE signature (HMAC-SHA256)
 * 3. Parse events from request body
 * 4. Dispatch ProcessLINEWebhook job for each event (async)
 */
public function handle(Request $request, string $token): JsonResponse
{
    // Find bot by webhook token (only LINE bots)
    $bot = $this->findBotByToken($token);

    if (! $bot) {
        Log::warning('LINE webhook: Invalid token', ['token' => substr($token, 0, 8).'...']);

        return response()->json(['message' => 'Invalid webhook token'], 404);
    }

    // Validate LINE signature
    $signature = $request->header('X-Line-Signature');
    if (! $signature) {
        Log::warning('LINE webhook: Missing signature', ['bot_id' => $bot->id]);

        return response()->json(['message' => 'Missing X-Line-Signature header'], 401);
    }

    // Check if channel_secret is configured
    if (empty($bot->channel_secret)) {
        Log::warning('LINE webhook: Channel secret not configured', ['bot_id' => $bot->id]);

        return response()->json(['message' => 'Bot channel secret not configured'], 500);
    }

    try {
        // HMAC-SHA256 validation against LINE's signature header
        $this->lineService->validateSignature(
            $request->getContent(),
            $signature,
            $bot->channel_secret
        );
    } catch (\Exception $e) {
        Log::warning('LINE webhook: Invalid signature', [
            'bot_id' => $bot->id,
            'error' => $e->getMessage(),
        ]);

        return response()->json(['message' => 'Invalid signature'], 401);
    }

    // Parse events from webhook body
    $body = $request->json()->all();
    $events = $this->lineService->parseEvents($body);

    if (empty($events)) {
        // This can happen with verification requests - just return OK
        return response()->json(['message' => 'OK']);
    }

    // Log webhook received with detailed event info for debugging
    for ($index = 0; $index < count($events); $index++) {
        $event = $events[$index];
        Log::info('LINE webhook event received', [
            'bot_id' => $bot->id,
            'event_index' => $index + 1,
            'event_count' => count($events),
            'event_type' => $event['type'] ?? 'unknown',
            'user_id' => $event['source']['userId'] ?? null,
            'message_type' => $event['message']['type'] ?? null,
            'message_id' => $event['message']['id'] ?? null,
            'webhook_event_id' => $event['webhookEventId'] ?? null,
            'timestamp' => $event['timestamp'] ?? null,
            'is_redelivery' => $event['deliveryContext']['isRedelivery'] ?? false,
        ]);
    }

    // Dispatch job for each event (async on 'webhooks' queue)
    foreach ($events as $event) {
        ProcessLINEWebhook::dispatch($bot, $event)
            ->onQueue('webhooks');

        Log::debug('LINE webhook job dispatched', [
            'bot_id' => $bot->id,
            'event_type' => $event['type'] ?? 'unknown',
            'message_id' => $event['message']['id'] ?? null,
        ]);
    }

    // Return 200 OK immediately - LINE requires fast response
    return response()->json(['message' => 'OK']);
}
```

**Key Design:**
- **Fast webhook return**: 200 OK sent immediately (async processing in queue).
- **HMAC validation**: Ensures webhook came from LINE, not attacker.
- **Token-based routing**: Webhook token maps to bot ID.
- **Per-event dispatch**: Multiple events in single webhook each get their own job.
- **Detailed logging**: Event type, user ID, message ID, redelivery flag for debugging.

---

## 8. ProcessLINEWebhook Job — Message Processing

**backend/app/Jobs/ProcessLINEWebhook.php:37-100**

Async job that processes each LINE webhook event: extract user message → check rate limits → call AIService → format response → send back to LINE.

```php
/**
 * Process a single LINE webhook event asynchronously.
 * 
 * Flow:
 * 1. Rate limit check (prevent spam abuse)
 * 2. Circuit breaker (prevent cascading DB failures)
 * 3. Message aggregation (smart grouping of rapid-fire messages)
 * 4. AIService call (generate bot response)
 * 5. Format & send response (Flex vs. text via LINE SDK)
 */
class ProcessLINEWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Keywords indicating a pending order in conversation history.
     * Used by vision analysis to detect when a slip image is expected.
     */
    private const ORDER_CONTEXT_KEYWORDS = ['รวมยอดโอน', 'สรุปรายการ', 'เลขบัญชี', 'รวมทั้งหมด', 'โอนเข้าบัญชี', 'ส่งสลิป'];

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     * Uses exponential backoff: 5s, 15s, 45s
     */
    public array $backoff = [5, 15, 45];

    /**
     * Smart aggregation state (used to pass data outside transaction).
     */
    protected ?string $aggregationGroupId = null;

    protected bool $dispatchAggregation = false;

    protected ?int $adaptiveWaitTimeMs = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Bot $bot,
        public array $event
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        LINEService $lineService,
        AIService $aiService,
        RateLimitService $rateLimitService,
        MessageAggregationService $aggregationService,
        ResponseHoursService $responseHoursService,
        CircuitBreakerService $circuitBreaker
    ): void {
        try {
            // Use circuit breaker to protect against DB failures
            // If circuit open: send fallback "we're having issues" message
            $circuitBreaker->execute(
                'database',
                fn () => $this->processEvent($lineService, $aiService, $rateLimitService, $aggregationService, $responseHoursService),
                fn () => $this->sendFallbackMessage($lineService)
            );
        } catch (CircuitOpenException $e) {
            // Circuit is open - send fallback and don't retry
            Log::warning('Circuit breaker open for LINE webhook', [
                'bot_id' => $this->bot->id,
                'service' => $e->getService(),
            ]);
            $this->sendFallbackMessage($lineService);
        } catch (\Exception $e) {
            // General exception - retry via queue backoff
            Log::error('ProcessLINEWebhook failed', [
                'bot_id' => $this->bot->id,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            // After 3 attempts, don't retry
            if ($this->attempts() >= $this->tries) {
                $this->fail($e);
            }
        }
    }
}
```

**Key Design:**
- **Retry strategy**: 3 attempts with exponential backoff (5s, 15s, 45s).
- **Circuit breaker**: Protects DB from cascading failures; sends fallback message if open.
- **Aggregation**: Smart message grouping (user sends 5 msgs in 1s → group into 1 bot response).
- **Rate limiting**: Prevent spam abuse and API quota exhaustion.
- **Vision support**: Detects order context to enable slip image analysis.

---

## 9. FormRequest Validation — StoreBotRequest

**backend/app/Http/Requests/Bot/StoreBotRequest.php:1-82**

Form validation for bot creation/update. Demonstrates nested api_keys merging pattern.

```php
/**
 * Validate bot creation/update request.
 * 
 * Key patterns:
 * 1. Nested api_keys unwrapping (prepareForValidation)
 * 2. Conditional rules per channel_type
 * 3. Custom messages in Thai/English
 */
class StoreBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'channel_type' => ['required', Rule::in(['line', 'facebook', 'telegram', 'testing', 'demo'])],
            'channel_access_token' => ['nullable', 'string'],
            'channel_secret' => ['nullable', 'string'],
            'page_id' => ['nullable', 'string'],

            // Multi-model LLM configuration (API key now in User Settings)
            'primary_chat_model' => ['nullable', 'string', 'max:100'],
            'fallback_chat_model' => ['nullable', 'string', 'max:100'],
            'decision_model' => ['nullable', 'string', 'max:100'],
            'fallback_decision_model' => ['nullable', 'string', 'max:100'],

            // Webhook forwarder
            'webhook_forwarder_enabled' => ['nullable', 'boolean'],

            // Auto handover
            'auto_handover' => ['nullable', 'boolean'],

            // Support nested api_keys format
            'api_keys' => ['nullable', 'array'],
            'api_keys.channel_access_token' => ['nullable', 'string'],
            'api_keys.channel_secret' => ['nullable', 'string'],
        ];
    }

    /**
     * Prepare the data for validation.
     * Extract api_keys into top-level fields if provided.
     * 
     * Handles both formats:
     * - {channel_access_token: '...', channel_secret: '...'}
     * - {api_keys: {channel_access_token: '...', channel_secret: '...'}}
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('api_keys') && is_array($this->api_keys)) {
            $apiKeys = $this->api_keys;

            // Merge api_keys values into top-level if not already set
            $this->merge([
                'channel_access_token' => $this->channel_access_token ?? ($apiKeys['channel_access_token'] ?? null),
                'channel_secret' => $this->channel_secret ?? ($apiKeys['channel_secret'] ?? null),
            ]);
        }
    }

    /**
     * Get the validated data, excluding api_keys wrapper.
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated($key, $default);

        if (is_array($validated)) {
            unset($validated['api_keys']);
        }

        return $validated;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Bot name is required',
            'channel_type.required' => 'Channel type is required',
            'channel_type.in' => 'Channel type must be line, facebook, or telegram',
        ];
    }
}
```

**Key Design:**
- **Nested unwrapping**: prepareForValidation() flattens api_keys into top-level for validation.
- **Custom messages**: Localized error messages for frontend.
- **Nullable channels**: Supports multi-channel (LINE + Facebook + Telegram) on same bot.

---

## 10. API Resource — MessageResource

**backend/app/Http/Resources/MessageResource.php:1-42**

Transforms Message model into API response. Includes usage tracking: prompt_tokens, cached_tokens, reasoning_tokens.

```php
/**
 * Transform Message model into JSON response.
 * 
 * Includes:
 * - Message content + metadata
 * - Token usage (prompt, completion, cached, reasoning)
 * - Model used (for cost tracking)
 * - Cost calculation (for dashboard)
 */
class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender' => $this->sender,  // 'user' | 'bot' | 'assistant'
            'content' => $this->content,
            'type' => $this->type,  // 'text' | 'flex' | 'image' | ...
            'media_url' => $this->media_url,
            'media_type' => $this->media_type,
            'media_metadata' => $this->media_metadata,  // vision analysis results
            'model_used' => $this->model_used,  // "gpt-4-turbo", "claude-3.5-sonnet", ...
            'prompt_tokens' => $this->prompt_tokens,
            'completion_tokens' => $this->completion_tokens,
            'cost' => $this->cost ? (float) $this->cost : null,  // USD
            'external_message_id' => $this->external_message_id,  // LINE message ID
            'reply_to_message_id' => $this->reply_to_message_id,
            'sentiment' => $this->sentiment,  // 'positive' | 'negative' | 'neutral'
            'intents' => $this->intents,  // Intent analysis results
            // Enhanced usage tracking (OpenRouter Best Practice)
            'cached_tokens' => $this->cached_tokens,  // From prompt caching
            'reasoning_tokens' => $this->reasoning_tokens,  // From o1/o3 models
            'reasoning_content' => $this->reasoning_content,  // Internal reasoning (if available)
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
```

**Key Design:**
- **Token tracking**: prompt_tokens + completion_tokens + cached_tokens for accurate cost calc.
- **Cost field**: USD cost computed server-side based on model + token usage.
- **Sentiment + intents**: Analytics fields for conversation insights.
- **media_metadata**: Vision analysis results (product detection, receipt extraction, etc.).

---

## 11. Frontend Zustand Store — Chat UI State

**frontend/src/stores/chatStore.ts:1-77**

Zustand store for chat UI state. Server state handled by React Query, this store only manages local UI (selected conversation, panels, search).

```typescript
/**
 * T025: Chat Store - UI state only
 * Server state handled by React Query
 * 
 * Pattern:
 * - UI state: selected conversation, panel visibility, search query
 * - localStorage persistence: only selectedConversationId (not full state)
 * - Actions: select, toggle panels, update search
 */
import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

interface ChatState {
  // Selected conversation
  selectedConversationId: number | null;

  // UI state
  isCustomerPanelOpen: boolean;
  showMobileChat: boolean;

  // Filters
  searchQuery: string;
}

interface ChatActions {
  // Selection
  selectConversation: (id: number | null) => void;

  // UI controls
  setCustomerPanelOpen: (open: boolean) => void;
  toggleCustomerPanel: () => void;
  setShowMobileChat: (show: boolean) => void;

  // Search
  setSearchQuery: (query: string) => void;

  // Reset
  reset: () => void;
}

type ChatStore = ChatState & ChatActions;

const initialState: ChatState = {
  selectedConversationId: null,
  isCustomerPanelOpen: false, // Default to closed - user can open via button
  showMobileChat: false,
  searchQuery: '',
};

export const useChatStore = create<ChatStore>()(
  persist(
    (set) => ({
      ...initialState,

      selectConversation: (id) =>
        set({
          selectedConversationId: id,
          showMobileChat: id !== null, // Auto-show chat on mobile when selecting
        }),

      setCustomerPanelOpen: (isCustomerPanelOpen) => set({ isCustomerPanelOpen }),

      toggleCustomerPanel: () =>
        set((state) => ({ isCustomerPanelOpen: !state.isCustomerPanelOpen })),

      setShowMobileChat: (showMobileChat) => set({ showMobileChat }),

      setSearchQuery: (searchQuery) => set({ searchQuery }),

      reset: () => set(initialState),
    }),
    {
      name: 'chat-store',
      storage: createJSONStorage(() => localStorage),
      // Only persist selectedConversationId
      partialize: (state) => ({
        selectedConversationId: state.selectedConversationId,
      }),
    }
  )
);
```

**Key Design:**
- **UI state only**: Panel visibility, selected conversation (not messages).
- **React Query handles server state**: Messages, conversations, metadata.
- **Selective persistence**: Only selectedConversationId saved to localStorage (auto-restore on refresh).
- **Auto-show on mobile**: Selecting conversation auto-opens chat panel on mobile.

---

## 12. Frontend Streaming Hook — Real-Time Message UI

**frontend/src/hooks/useStreamingChat.ts:1-120**

Custom hook for real-time streaming chat. Uses useReducer to batch state updates, streaming from API via StreamController (WebSocket or SSE).

```typescript
/**
 * Hook for real-time streaming chat responses.
 * 
 * Features:
 * - SSE/WebSocket streaming (flow test endpoint)
 * - Process logs (intent, KB search, tool calls)
 * - Abort controller (user can stop streaming)
 * - Max message cap (prevent memory leak on long sessions)
 * 
 * State shape:
 * {
 *   messages: [{id, role, content, processLogs?, summary?, isStreaming?}],
 *   isStreaming: boolean
 * }
 */
import { useReducer, useRef, useCallback } from 'react';
import { streamFlowTest, createStreamAbortController, type ProcessLog, type DoneSummary } from '@/lib/stream';

export interface StreamingMessage {
  id: string;
  role: 'user' | 'assistant';
  content: string;
  processLogs?: ProcessLog[];
  summary?: DoneSummary;
  isStreaming?: boolean;
}

interface UseStreamingChatOptions {
  botId: number | null;
  flowId: number | null;
  conversationId?: number | null;
  onApprovalRequired?: (data: {
    approval_id: string;
    tool_name: string;
    tool_args: Record<string, unknown>;
    timeout_seconds: number;
  }) => void;
}

// State shape
interface ChatState {
  messages: StreamingMessage[];
  isStreaming: boolean;
}

// Action types for reducer
type ChatAction =
  | { type: 'ADD_USER_MESSAGE'; payload: { id: string; content: string } }
  | { type: 'ADD_ASSISTANT_PLACEHOLDER'; payload: { id: string } }
  | { type: 'APPEND_PROCESS_LOG'; payload: { messageId: string; log: ProcessLog } }
  | { type: 'APPEND_CONTENT'; payload: { messageId: string; text: string } }
  | { type: 'REPLACE_CONTENT'; payload: { messageId: string; content: string } }
  | { type: 'FLUSH_STREAMING_CONTENT'; payload: { messageId: string; content: string } }
  | { type: 'SET_ERROR'; payload: { messageId: string; error: string } }
  | { type: 'SET_DONE'; payload: { messageId: string; summary?: DoneSummary } }
  | { type: 'SET_ABORTED'; payload: { messageId: string } }
  | { type: 'SET_STREAMING'; payload: boolean }
  | { type: 'CLEAR_MESSAGES' };

// Maximum messages to keep in memory (F4: prevent memory leak)
const MAX_MESSAGES = 100;

// Initial state
const initialState: ChatState = {
  messages: [],
  isStreaming: false,
};

// Reducer function - batches all state updates
function chatReducer(state: ChatState, action: ChatAction): ChatState {
  switch (action.type) {
    case 'ADD_USER_MESSAGE': {
      const newMessages = [
        ...state.messages,
        { id: action.payload.id, role: 'user' as const, content: action.payload.content }
      ];
      return {
        ...state,
        messages: newMessages.length > MAX_MESSAGES ? newMessages.slice(-MAX_MESSAGES) : newMessages,
      };
    }

    case 'ADD_ASSISTANT_PLACEHOLDER': {
      const newMessages = [
        ...state.messages,
        {
          id: action.payload.id,
          role: 'assistant' as const,
          content: '',
          processLogs: [],
          isStreaming: true,
        }
      ];
      return {
        ...state,
        messages: newMessages.length > MAX_MESSAGES ? newMessages.slice(-MAX_MESSAGES) : newMessages,
        isStreaming: true,
      };
    }

    case 'APPEND_PROCESS_LOG':
      return {
        ...state,
        messages: state.messages.map(m =>
          m.id === action.payload.messageId
            ? { ...m, processLogs: [...(m.processLogs || []), action.payload.log] }
            : m
        ),
      };

    case 'APPEND_CONTENT':
      return {
        ...state,
        messages: state.messages.map(m =>
          m.id === action.payload.messageId
            ? { ...m, content: m.content + action.payload.text }
            : m
        ),
      };

    case 'REPLACE_CONTENT':
      return {
        ...state,
        messages: state.messages.map(m =>
          m.id === action.payload.messageId
            ? { ...m, content: action.payload.content }
            : m
        ),
      };

    case 'FLUSH_STREAMING_CONTENT':
      return {
        ...state,
        messages: state.messages.map(m =>
          m.id === action.payload.messageId
            ? { ...m, content: action.payload.content, isStreaming: false }
            : m
        ),
      };
  }

  return state;
}
```

**Key Design:**
- **useReducer pattern**: Batches streaming updates into single re-render per reducer action.
- **Process logs**: Real-time debug info (intent: "knowledge", KB results, tool calls).
- **MAX_MESSAGES cap**: Slice old messages to prevent memory leak on long sessions.
- **Abort support**: User can stop streaming mid-response via AbortController.

---

## 13. Frontend Query Hook — useFlows

**frontend/src/hooks/useFlows.ts:1-100**

React Query hooks for Flow CRUD. Demonstrates optimistic updates pattern + query key strategies.

```typescript
/**
 * React Query hooks for Flow management.
 * 
 * Patterns:
 * - useQuery: fetch flows list, single flow, templates
 * - useMutation: create, update flows
 * - Optimistic updates: update local cache before server response
 * - Invalidation: auto-refetch after mutations
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiGet, apiPost, apiPut, apiDelete } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import { useMutationWithToast } from './useMutationWithToast';
import type {
  ApiResponse,
  CreateFlowData,
  Flow,
  FlowTemplate,
  PaginatedResponse,
  UpdateFlowData,
} from '@/types/api';

// Fetch all flows for a bot
export function useFlows(botId: number | null) {
  return useQuery({
    queryKey: queryKeys.flows.list(botId ?? 0),
    queryFn: async () => {
      const response = await apiGet<PaginatedResponse<Flow>>(`/bots/${botId}/flows`);
      return response;
    },
    enabled: !!botId,  // Only run query if botId is set
  });
}

// Fetch a single flow
export function useFlow(botId: number | null, flowId: number | null) {
  return useQuery({
    queryKey: queryKeys.flows.detail(botId ?? 0, flowId ?? 0),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<Flow>>(`/bots/${botId}/flows/${flowId}`);
      return response.data;
    },
    enabled: !!botId && !!flowId,
  });
}

// Fetch flow templates
export function useFlowTemplates(enabled = true) {
  return useQuery({
    queryKey: queryKeys.flows.templates(),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<FlowTemplate[]>>('/flow-templates');
      return response.data;
    },
    enabled,
  });
}

// Create flow mutation
export function useCreateFlow(botId: number | null) {
  return useMutationWithToast({
    mutationFn: async (data: CreateFlowData) => {
      if (!botId) throw new Error('Bot ID is required');
      const response = await apiPost<ApiResponse<Flow>>(`/bots/${botId}/flows`, data);
      return response.data;
    },
    successMessage: (flow) => `สร้าง Flow "${flow.name}" สำเร็จ`,
    invalidateKeys: botId ? [queryKeys.flows.list(botId)] : [],
  });
}

// Update flow mutation with optimistic update
export function useUpdateFlow(botId: number | null, flowId: number | null) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: UpdateFlowData) => {
      if (!botId || !flowId) throw new Error('Bot ID and Flow ID are required');
      const response = await apiPut<ApiResponse<Flow>>(`/bots/${botId}/flows/${flowId}`, data);
      return response.data;
    },
    onMutate: async (data) => {
      if (!botId || !flowId) return;

      // Cancel outgoing refetches
      await queryClient.cancelQueries({ queryKey: queryKeys.flows.list(botId) });
      await queryClient.cancelQueries({ queryKey: queryKeys.flows.detail(botId, flowId) });

      // Snapshot previous values
      const previousFlows = queryClient.getQueryData<PaginatedResponse<Flow>>(
        queryKeys.flows.list(botId)
      );
      const previousFlow = queryClient.getQueryData<Flow>(
        queryKeys.flows.detail(botId, flowId)
      );

      // Extract only safe fields to update (exclude knowledge_bases which has different type)
      const { knowledge_bases: _kb, ...safeData } = data; // eslint-disable-line @typescript-eslint/no-unused-vars
      const partialUpdate = safeData as Partial<Flow>;

      // Optimistically update detail cache
      queryClient.setQueryData<Flow | undefined>(
        queryKeys.flows.detail(botId, flowId),
        (oldData) => oldData ? { ...oldData, ...partialUpdate } : oldData
      );

      // Optimistically update list cache
      queryClient.setQueryData<PaginatedResponse<Flow> | undefined>(
        queryKeys.flows.list(botId),
        (oldData) => {
          if (!oldData) return oldData;
          return {
            ...oldData,
            data: oldData.data.map(flow =>
              flow.id === flowId ? { ...flow, ...partialUpdate } : flow
            ),
          };
        }
      );

      // Return previous values for rollback on error
      return { previousFlows, previousFlow };
    },
    onError: (_error, _data, context) => {
      // Rollback on error
      if (context?.previousFlows) {
        queryClient.setQueryData(queryKeys.flows.list(botId), context.previousFlows);
      }
      if (context?.previousFlow) {
        queryClient.setQueryData(queryKeys.flows.detail(botId, flowId), context.previousFlow);
      }
    },
    onSuccess: (updatedFlow) => {
      // Ensure both caches are in sync after success
      queryClient.setQueryData(queryKeys.flows.detail(botId, flowId), updatedFlow);
      queryClient.invalidateQueries({ queryKey: queryKeys.flows.list(botId) });
    },
  });
}
```

**Key Design:**
- **Optimistic updates**: Update UI immediately before server response, rollback on error.
- **Query key strategy**: Hierarchical keys (`flows.list(botId)`, `flows.detail(botId, flowId)`).
- **Enabled flag**: Only run query if botId is set (prevent unnecessary requests).
- **Toast integration**: Success/error messages auto-shown via useMutationWithToast.

---

## Summary — RAG Architecture Patterns

| Component | Purpose | Cache/TTL |
|-----------|---------|-----------|
| **RAGService** | Main orchestrator (intent → KB → LLM) | Semantic cache (dynamic) |
| **SemanticCache** | Repeated question shortcut | 24-48 hours |
| **FlowCacheService** | Bot config + KB attachments | 30 minutes |
| **StockInjectionService** | Header + footer prompt injection | 5 minutes |
| **StockGuardService** | Post-generation violation detection | None (real-time) |
| **PaymentFlexService** | Response → LINE Flex Message | None (per-response) |
| **ProcessLINEWebhook Job** | Async message handler + aggregation | None (async) |

**Key Learnings:**
1. **Stock management requires dual injection** (header + footer) to overcome LLM hallucination.
2. **Flow cache TTL (30 min)** balances freshness vs. DB load for high-traffic bots.
3. **StockGuard 3-layer design**: detect → distinguish intent → strip upsell maintains UX while blocking hard violations.
4. **Semantic cache** speeds up repeated questions 10-100x (no LLM call, no KB search).
5. **Frontend state separation**: Zustand for UI state, React Query for server state prevents coupling.
