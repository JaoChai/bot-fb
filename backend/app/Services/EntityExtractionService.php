<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

/**
 * Entity Extraction Service
 *
 * Extracts customer entities (name, phone, product interest, preferences)
 * from conversation messages using LLM and stores them in memory_notes.
 *
 * Runs asynchronously via ExtractEntitiesJob to avoid blocking responses.
 */
class EntityExtractionService
{
    public const ENTITY_TYPES = ['name', 'phone', 'product_interest', 'preference'];

    public const ENTITY_LABELS = [
        'name' => 'ชื่อลูกค้า',
        'phone' => 'เบอร์โทร',
        'product_interest' => 'สนใจสินค้า',
        'preference' => 'ความต้องการ',
    ];

    protected string $model;

    public function __construct(
        protected OpenRouterService $openRouter
    ) {
        $this->model = config('rag.query_enhancement.model', 'openai/gpt-4o-mini');
    }

    /**
     * Extract entities from recent messages and save to memory_notes.
     *
     * Uses batch write — all new entities saved in a single DB update.
     *
     * @param  Conversation  $conversation  The conversation to extract from
     * @param  int  $messageLimit  Number of recent messages to analyze
     * @return array{extracted: array, saved_count: int}
     */
    public function extractAndSave(Conversation $conversation, int $messageLimit = 5): array
    {
        $result = ['extracted' => [], 'saved_count' => 0];

        // Get recent messages
        $messages = $conversation->messages()
            ->whereIn('sender', ['user', 'bot'])
            ->latest()
            ->take($messageLimit)
            ->get()
            ->reverse()
            ->values();

        if ($messages->isEmpty()) {
            return $result;
        }

        // Format messages for LLM
        $messageText = $messages->map(function ($msg) {
            $role = $msg->sender === 'user' ? 'ลูกค้า' : 'บอท';

            return "{$role}: {$msg->content}";
        })->implode("\n");

        // Get existing memory notes to avoid duplicates
        $existingNotes = collect($conversation->memory_notes ?? [])
            ->where('type', 'memory')
            ->pluck('content')
            ->all();

        $existingContext = ! empty($existingNotes)
            ? "ข้อมูลที่จำไว้แล้ว:\n".implode("\n", $existingNotes)
            : 'ยังไม่มีข้อมูลที่จำไว้';

        // Extract entities via LLM
        $entities = $this->callLLM($messageText, $existingContext, $conversation);

        if (empty($entities)) {
            return $result;
        }

        $result['extracted'] = $entities;

        // Batch: collect all new notes, then write once
        $notes = $conversation->memory_notes ?? [];
        foreach ($entities as $entity) {
            $content = $this->formatEntity($entity);
            if (! empty($content) && ! $this->isDuplicate($content, $existingNotes)) {
                $notes[] = [
                    'type' => 'memory',
                    'content' => $content,
                    'extracted_at' => now()->toISOString(),
                    'source' => 'auto_entity_extraction',
                ];
                $result['saved_count']++;
            }
        }

        if ($result['saved_count'] > 0) {
            $conversation->update(['memory_notes' => $notes]);

            Log::debug('EntityExtraction: Saved memory notes', [
                'conversation_id' => $conversation->id,
                'saved_count' => $result['saved_count'],
            ]);
        }

        return $result;
    }

    /**
     * Call LLM to extract entities from messages.
     */
    protected function callLLM(string $messageText, string $existingContext, Conversation $conversation): array
    {
        try {
            $apiKey = $conversation->bot?->user?->settings?->getOpenRouterApiKey()
                ?? config('services.openrouter.api_key');

            $entityList = implode(', ', self::ENTITY_TYPES);

            $systemPrompt = <<<PROMPT
You extract customer information from chat messages. Return ONLY valid JSON.

Extract these entity types:
- "name": Customer's name (Thai or English)
- "phone": Phone number
- "product_interest": Products/services the customer is interested in
- "preference": Customer preferences (size, color, budget, etc.)

Rules:
1. Only extract information explicitly stated by the CUSTOMER (not the bot)
2. Skip entities that are already known (listed in existing memory)
3. If no new entities found, return {"entities": []}
4. Each entity: {"type": "{$entityList}", "value": "..."}

Return format: {"entities": [{"type": "...", "value": "..."}, ...]}
PROMPT;

            $userPrompt = "{$existingContext}\n\nบทสนทนาล่าสุด:\n{$messageText}\n\nExtract new entities:";

            $response = $this->openRouter->chat(
                messages: [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                model: $this->model,
                temperature: 0.1,
                maxTokens: 200,
                useFallback: false,
                apiKeyOverride: $apiKey
            );

            return $this->parseResponse($response['content'] ?? '');
        } catch (\Exception $e) {
            Log::warning('EntityExtraction: LLM call failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Parse LLM response to extract entities.
     */
    protected function parseResponse(string $content): array
    {
        $content = trim($content);

        // Handle markdown code blocks
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $content = $matches[1];
        }

        // Try to extract JSON
        if (preg_match('/\{[^{}]*"entities"[^{}]*\[.*?\][^{}]*\}/s', $content, $matches)) {
            $content = $matches[0];
        }

        try {
            $decoded = json_decode($content, true);

            if (is_array($decoded) && isset($decoded['entities']) && is_array($decoded['entities'])) {
                return array_filter($decoded['entities'], function ($entity) {
                    return is_array($entity)
                        && isset($entity['type'], $entity['value'])
                        && is_string($entity['value'])
                        && ! empty(trim($entity['value']))
                        && in_array($entity['type'], self::ENTITY_TYPES);
                });
            }
        } catch (\Exception) {
            Log::debug('EntityExtraction: JSON parse failed', [
                'content' => substr($content, 0, 200),
            ]);
        }

        return [];
    }

    /**
     * Format entity for storage as memory note content.
     */
    protected function formatEntity(array $entity): string
    {
        $label = self::ENTITY_LABELS[$entity['type']] ?? $entity['type'];

        return "{$label}: {$entity['value']}";
    }

    /**
     * Check if a note is essentially a duplicate of existing notes.
     */
    protected function isDuplicate(string $content, array $existingNotes): bool
    {
        $normalized = mb_strtolower(trim($content));

        foreach ($existingNotes as $note) {
            if (mb_strtolower(trim($note)) === $normalized) {
                return true;
            }
            // Check if the value part is already captured
            $parts = explode(': ', $content, 2);
            $noteParts = explode(': ', $note, 2);
            if (count($parts) === 2 && count($noteParts) === 2
                && mb_strtolower($parts[1]) === mb_strtolower($noteParts[1])) {
                return true;
            }
        }

        return false;
    }
}
