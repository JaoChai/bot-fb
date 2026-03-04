<?php

namespace App\Services;

use App\Models\Flow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Tool Service for AI Agent
 *
 * Manages tool definitions and execution for agentic mode.
 * Tools allow the AI to perform actions like searching KB or calculating.
 */
class ToolService
{
    protected array $searchCache = [];

    public function __construct(
        protected HybridSearchService $hybridSearch
    ) {}

    /**
     * Reset search cache (call at start of each agent loop).
     */
    public function resetCache(): void
    {
        $this->searchCache = [];
    }

    /**
     * Get tool definitions in OpenRouter/OpenAI format.
     *
     * @param  array  $enabledTools  List of tool keys to enable
     * @return array Tool definitions for API
     */
    public function getToolDefinitions(array $enabledTools): array
    {
        $allTools = config('tools', []);
        $definitions = [];

        foreach ($enabledTools as $toolKey) {
            if (isset($allTools[$toolKey]) && $toolKey !== '_meta') {
                $definitions[] = $allTools[$toolKey];
            }
        }

        return $definitions;
    }

    /**
     * Get available tools with metadata for UI.
     *
     * @return array Tools with metadata
     */
    public function getAvailableTools(): array
    {
        $allTools = config('tools', []);
        $meta = $allTools['_meta'] ?? [];
        $tools = [];

        foreach ($allTools as $key => $tool) {
            if ($key === '_meta') {
                continue;
            }

            $toolMeta = $meta[$key] ?? [];
            $tools[] = [
                'key' => $key,
                'name' => $tool['function']['name'] ?? $key,
                'description' => $tool['function']['description'] ?? '',
                'icon' => $toolMeta['icon'] ?? 'wrench',
                'label' => $toolMeta['label'] ?? $key,
                'label_en' => $toolMeta['label_en'] ?? $key,
                'color' => $toolMeta['color'] ?? 'gray',
            ];
        }

        return $tools;
    }

    /**
     * Validate tool arguments against schema.
     *
     * @param  string  $toolName  The tool function name
     * @param  array  $arguments  Tool arguments
     *
     * @throws \InvalidArgumentException If validation fails
     */
    protected function validateArguments(string $toolName, array $arguments): void
    {
        // Tool-specific validation rules
        $validationRules = [
            'search_knowledge_base' => [
                'required' => ['query'],
                'maxLength' => ['query' => 1000],
            ],
            'calculate' => [
                'required' => ['expression'],
                'maxLength' => ['expression' => 500],
            ],
            'think' => [
                'required' => ['thought'],
                'maxLength' => ['thought' => 5000],
            ],
            'get_current_datetime' => [
                'required' => [],
            ],
            'escalate_to_human' => [
                'required' => ['reason'],
                'maxLength' => ['reason' => 1000],
            ],
        ];

        $rules = $validationRules[$toolName] ?? [];

        // Check required fields
        foreach ($rules['required'] ?? [] as $field) {
            if (! isset($arguments[$field]) || $arguments[$field] === '') {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        // Check max length
        foreach ($rules['maxLength'] ?? [] as $field => $maxLength) {
            if (isset($arguments[$field]) && is_string($arguments[$field])) {
                if (mb_strlen($arguments[$field]) > $maxLength) {
                    throw new \InvalidArgumentException(
                        "Field '{$field}' exceeds maximum length of {$maxLength} characters"
                    );
                }
            }
        }
    }

    /**
     * Execute a tool and return result.
     *
     * @param  string  $toolName  The tool function name
     * @param  array  $arguments  Tool arguments
     * @param  array  $context  Execution context (flow, bot, etc.)
     * @return array Result with status and data
     */
    public function executeTool(string $toolName, array $arguments, array $context = []): array
    {
        $startTime = microtime(true);

        try {
            // Validate arguments before execution
            $this->validateArguments($toolName, $arguments);

            $result = match ($toolName) {
                'search_knowledge_base' => $this->executeSearchKb($arguments, $context),
                'calculate' => $this->executeCalculate($arguments),
                'think' => $this->executeThink($arguments),
                'get_current_datetime' => $this->executeGetDatetime($arguments),
                'escalate_to_human' => $this->executeEscalateToHuman($arguments, $context),
                default => throw new \InvalidArgumentException("Unknown tool: {$toolName}"),
            };

            $timeMs = round((microtime(true) - $startTime) * 1000);

            Log::info('Tool executed successfully', [
                'tool' => $toolName,
                'time_ms' => $timeMs,
            ]);

            return [
                'status' => 'success',
                'result' => $result,
                'time_ms' => $timeMs,
            ];
        } catch (\Exception $e) {
            $timeMs = round((microtime(true) - $startTime) * 1000);

            Log::error('Tool execution failed', [
                'tool' => $toolName,
                'error' => $e->getMessage(),
                'time_ms' => $timeMs,
            ]);

            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'time_ms' => $timeMs,
            ];
        }
    }

    /**
     * Execute knowledge base search tool.
     *
     * @param  array  $args  Tool arguments
     * @param  array  $context  Execution context with flow info
     * @return string Search results formatted for AI
     */
    protected function executeSearchKb(array $args, array $context): string
    {
        $query = $args['query'] ?? '';

        if (empty($query)) {
            return 'ไม่มีคำค้นหา';
        }

        // Normalize query for cache lookup
        $normalizedQuery = mb_strtolower(trim(preg_replace('/\s+/', ' ', $query)));

        // Check exact cache match
        if (isset($this->searchCache[$normalizedQuery])) {
            Log::debug('ToolService: Search cache hit (exact)', ['query' => $query]);

            return $this->searchCache[$normalizedQuery];
        }

        // Check similarity cache match (>80%)
        foreach ($this->searchCache as $cachedQuery => $cachedResult) {
            similar_text($normalizedQuery, $cachedQuery, $percent);
            if ($percent > 80) {
                Log::debug('ToolService: Search cache hit (similar)', [
                    'query' => $query,
                    'cached_query' => $cachedQuery,
                    'similarity' => round($percent, 1),
                ]);

                return $cachedResult;
            }
        }

        /** @var Flow|null $flow */
        $flow = $context['flow'] ?? null;

        if (! $flow) {
            return 'ไม่พบการตั้งค่า Flow';
        }

        // Get bot from context (already loaded) or from flow relationship
        /** @var \App\Models\Bot|null $bot */
        $bot = $context['bot'] ?? $flow->bot;

        // Get knowledge bases from flow
        $knowledgeBases = $flow->knowledgeBases;

        if ($knowledgeBases->isEmpty()) {
            return 'ไม่มีฐานความรู้ที่เชื่อมต่อกับ Flow นี้';
        }

        // Get API key: User Settings > ENV (using safe getter to handle decryption errors)
        $apiKey = $bot?->user?->settings?->getOpenRouterApiKey()
            ?? config('services.openrouter.api_key');

        // Debug logging to trace API key source
        Log::debug('ToolService: API key resolution', [
            'flow_id' => $flow->id,
            'bot_id' => $bot?->id,
            'has_user_api_key' => ! empty($bot?->user?->settings?->getOpenRouterApiKey()),
            'has_env_api_key' => ! empty(config('services.openrouter.api_key')),
            'final_has_key' => ! empty($apiKey),
        ]);

        if (empty($apiKey)) {
            Log::warning('ToolService: No OpenRouter API key found', [
                'flow_id' => $flow->id,
                'bot_id' => $flow->bot_id,
            ]);

            return 'กรุณาตั้งค่า OpenRouter API Key ในหน้าตั้งค่าบอทหรือตั้งค่าผู้ใช้ก่อนใช้งานค้นหาฐานความรู้';
        }

        // Build KB configs
        $kbConfigs = $knowledgeBases->map(fn ($kb) => [
            'id' => $kb->id,
            'name' => $kb->name,
            'kb_top_k' => $kb->pivot->kb_top_k ?? 5,
            'kb_similarity_threshold' => $kb->pivot->kb_similarity_threshold ?? 0.5, // Lower default for Thai language
        ])->toArray();

        // Search with user's API key
        $results = $this->hybridSearch->searchMultiple(
            kbConfigs: $kbConfigs,
            query: $query,
            totalLimit: 5,
            apiKey: $apiKey
        );

        if ($results->isEmpty()) {
            $emptyResult = "ไม่พบข้อมูลที่เกี่ยวข้องกับ \"{$query}\"";
            if (count($this->searchCache) >= 20) {
                array_shift($this->searchCache);
            }
            $this->searchCache[$normalizedQuery] = $emptyResult;

            return $emptyResult;
        }

        // Format results for AI and cache (limit cache size)
        $formatted = $this->formatSearchResults($results, $query);
        if (count($this->searchCache) >= 20) {
            array_shift($this->searchCache);
        }
        $this->searchCache[$normalizedQuery] = $formatted;

        return $formatted;
    }

    /**
     * Format search results for AI consumption.
     *
     * @param  Collection  $results  Search results
     * @param  string  $query  Original query
     * @return string Formatted results
     */
    protected function formatSearchResults(Collection $results, string $query): string
    {
        $output = "ผลการค้นหา \"{$query}\":\n\n";

        foreach ($results as $i => $result) {
            $relevance = round(($result['similarity'] ?? 0) * 100);
            $content = $result['content'] ?? '';
            $docName = $result['document_name'] ?? 'Unknown';

            $output .= sprintf(
                "[%d] (ความเกี่ยวข้อง %d%%)\n%s\n---\n%s\n\n",
                $i + 1,
                $relevance,
                $docName,
                $this->truncateText($content, 500)
            );
        }

        return trim($output);
    }

    /**
     * Execute calculate tool with safe math evaluation (no eval).
     *
     * Uses a simple recursive descent parser for safety.
     *
     * @param  array  $args  Tool arguments
     * @return string Calculation result
     */
    protected function executeCalculate(array $args): string
    {
        $expression = $args['expression'] ?? '';

        if (empty($expression)) {
            return 'ไม่มีนิพจน์ที่ต้องการคำนวณ';
        }

        try {
            $calculator = new SafeMathCalculator;
            $result = $calculator->calculate($expression);

            if ($result === null) {
                return "ไม่สามารถคำนวณ \"{$expression}\" ได้";
            }

            // Format result nicely
            $formatted = $this->formatNumber($result);

            return "{$expression} = {$formatted}";
        } catch (\Exception $e) {
            Log::warning('Calculate tool error', [
                'expression' => $expression,
                'error' => $e->getMessage(),
            ]);

            return "ไม่สามารถคำนวณ \"{$expression}\" ได้: {$e->getMessage()}";
        }
    }

    /**
     * Execute think tool - internal reasoning scratchpad for AI.
     *
     * This tool allows the AI to pause and reflect before responding.
     * The thought is logged for debugging but not shown to end users.
     *
     * @param  array  $args  Tool arguments
     * @return string Confirmation message
     */
    protected function executeThink(array $args): string
    {
        $thought = $args['thought'] ?? '';

        if (empty($thought)) {
            return '[No thought provided]';
        }

        // Log for debugging (truncated for log size)
        Log::debug('AI Think Tool', [
            'thought' => mb_substr($thought, 0, 200),
        ]);

        // Return confirmation - this goes back to the AI, not to the user
        return "Thought recorded: {$thought}";
    }

    /**
     * Format a number nicely.
     *
     * @param  float  $number  Number to format
     * @return string Formatted number
     */
    protected function formatNumber(float $number): string
    {
        // Remove unnecessary decimals
        if ($number == (int) $number) {
            return number_format($number, 0, '.', ',');
        }

        // Limit decimals to 4
        $formatted = number_format($number, 4, '.', ',');

        // Remove trailing zeros
        return rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * Truncate text to max length.
     *
     * @param  string  $text  Text to truncate
     * @param  int  $maxLength  Maximum length
     * @return string Truncated text
     */
    protected function truncateText(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength).'...';
    }

    protected function executeGetDatetime(array $args): string
    {
        $timezone = $args['timezone'] ?? 'Asia/Bangkok';

        try {
            $now = \Carbon\Carbon::now($timezone);
        } catch (\Exception $e) {
            $now = \Carbon\Carbon::now('Asia/Bangkok');
            $timezone = 'Asia/Bangkok';
        }

        $dayNames = [
            'th' => ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'],
            'en' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        ];

        $thDay = $dayNames['th'][$now->dayOfWeek];
        $enDay = $dayNames['en'][$now->dayOfWeek];

        return sprintf(
            "Current Date/Time (%s):\n- Date: %s\n- Time: %s\n- Day: %s (%s)",
            $timezone,
            $now->format('Y-m-d'),
            $now->format('H:i:s'),
            $enDay,
            "วัน{$thDay}"
        );
    }

    protected function executeEscalateToHuman(array $args, array $context = []): string
    {
        $reason = $args['reason'] ?? 'No reason provided';
        $priority = $args['priority'] ?? 'medium';

        Log::info('Agent escalation to human requested', [
            'reason' => $reason,
            'priority' => $priority,
            'flow_id' => $context['flow']?->id ?? null,
            'bot_id' => $context['bot']?->id ?? null,
        ]);

        return "Escalation request recorded. Reason: {$reason}. Priority: {$priority}. A human agent will be notified.";
    }
}

/**
 * Safe Math Calculator
 *
 * A simple recursive descent parser for basic math expressions.
 * Supports: +, -, *, /, %, parentheses
 * NO eval() used - completely safe.
 */
class SafeMathCalculator
{
    private string $expression;

    private int $pos;

    private int $length;

    // Safety limits to prevent DoS attacks
    private const MAX_EXPRESSION_LENGTH = 500;

    private const MAX_PARENTHESES_DEPTH = 20;

    /**
     * Calculate a math expression safely.
     *
     * @param  string  $expression  Math expression
     * @return float|null Result or null on error
     *
     * @throws \Exception If expression exceeds safety limits
     */
    public function calculate(string $expression): ?float
    {
        // Safety check: expression length
        if (strlen($expression) > self::MAX_EXPRESSION_LENGTH) {
            throw new \Exception('Expression too long (max '.self::MAX_EXPRESSION_LENGTH.' characters)');
        }

        // Safety check: parentheses nesting depth
        $depth = 0;
        $maxDepth = 0;
        for ($i = 0; $i < strlen($expression); $i++) {
            if ($expression[$i] === '(') {
                $depth++;
                $maxDepth = max($maxDepth, $depth);
            } elseif ($expression[$i] === ')') {
                $depth--;
            }
            if ($maxDepth > self::MAX_PARENTHESES_DEPTH) {
                throw new \Exception('Parentheses nesting too deep (max '.self::MAX_PARENTHESES_DEPTH.')');
            }
        }

        // Normalize expression
        $this->expression = str_replace(' ', '', $expression);

        // Handle percentage notation (e.g., 20% -> 0.20)
        $this->expression = preg_replace_callback('/(\d+(?:\.\d+)?)\s*%/', function ($m) {
            return (float) $m[1] / 100;
        }, $this->expression);

        $this->pos = 0;
        $this->length = strlen($this->expression);

        if ($this->length === 0) {
            return null;
        }

        try {
            $result = $this->parseExpression();

            // Ensure we consumed entire expression
            if ($this->pos < $this->length) {
                return null;
            }

            return $result;
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Parse addition and subtraction (lowest precedence).
     */
    private function parseExpression(): float
    {
        $result = $this->parseTerm();

        while ($this->pos < $this->length) {
            $char = $this->expression[$this->pos];

            if ($char === '+') {
                $this->pos++;
                $result += $this->parseTerm();
            } elseif ($char === '-') {
                $this->pos++;
                $result -= $this->parseTerm();
            } else {
                break;
            }
        }

        return $result;
    }

    /**
     * Parse multiplication and division (higher precedence).
     */
    private function parseTerm(): float
    {
        $result = $this->parseFactor();

        while ($this->pos < $this->length) {
            $char = $this->expression[$this->pos];

            if ($char === '*') {
                $this->pos++;
                $result *= $this->parseFactor();
            } elseif ($char === '/') {
                $this->pos++;
                $divisor = $this->parseFactor();
                if ($divisor == 0) {
                    throw new \Exception('Division by zero');
                }
                $result /= $divisor;
            } else {
                break;
            }
        }

        return $result;
    }

    /**
     * Parse numbers and parenthesized expressions (highest precedence).
     */
    private function parseFactor(): float
    {
        // Handle unary minus
        if ($this->pos < $this->length && $this->expression[$this->pos] === '-') {
            $this->pos++;

            return -$this->parseFactor();
        }

        // Handle unary plus
        if ($this->pos < $this->length && $this->expression[$this->pos] === '+') {
            $this->pos++;

            return $this->parseFactor();
        }

        // Handle parentheses
        if ($this->pos < $this->length && $this->expression[$this->pos] === '(') {
            $this->pos++; // skip '('
            $result = $this->parseExpression();

            if ($this->pos >= $this->length || $this->expression[$this->pos] !== ')') {
                throw new \Exception('Missing closing parenthesis');
            }
            $this->pos++; // skip ')'

            return $result;
        }

        // Parse number
        return $this->parseNumber();
    }

    /**
     * Parse a numeric value.
     */
    private function parseNumber(): float
    {
        $start = $this->pos;

        // Match digits and decimal point
        while ($this->pos < $this->length &&
               (ctype_digit($this->expression[$this->pos]) || $this->expression[$this->pos] === '.')) {
            $this->pos++;
        }

        if ($start === $this->pos) {
            throw new \Exception('Expected number at position '.$this->pos);
        }

        $numStr = substr($this->expression, $start, $this->pos - $start);

        return (float) $numStr;
    }
}
