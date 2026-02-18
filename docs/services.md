# Key Services Reference

Backend services organized by domain.

---

## AI & RAG Services

| Service | Purpose | Location |
|---------|---------|----------|
| `RAGService` | Main AI orchestration | `app/Services/RAGService.php` |
| `OpenRouterService` | LLM API client with retry | `app/Services/OpenRouterService.php` |
| `EmbeddingService` | Vector generation | `app/Services/EmbeddingService.php` |
| `SemanticSearchService` | Vector similarity search | `app/Services/SemanticSearchService.php` |
| `HybridSearchService` | Semantic + keyword search | `app/Services/HybridSearchService.php` |

### RAGService
Main orchestrator for AI chat functionality:
- Retrieves context from knowledge base
- Sends to LLM via OpenRouterService
- Handles streaming responses
- Manages conversation history

### EmbeddingService
Generates vector embeddings for documents:
- Uses OpenRouter embedding models
- Handles chunking for large documents
- Stores vectors in pgvector

---

## Messaging Services

| Service | Purpose | Location |
|---------|---------|----------|
| `LINEService` | LINE Messaging API | `app/Services/LINEService.php` |
| `TelegramService` | Telegram Bot API | `app/Services/TelegramService.php` |

### LINEService
Handles LINE bot interactions:
- Webhook verification
- Message sending (text, flex, quick reply)
- Rich menu management
- User profile fetching

### TelegramService
Handles Telegram bot interactions:
- Webhook setup
- Message sending (text, buttons, inline)
- Command handling
- User management

---

## Infrastructure Services

| Service | Purpose | Location |
|---------|---------|----------|
| `CircuitBreakerService` | API resilience | `app/Services/CircuitBreakerService.php` |
| `CostTrackingService` | Token usage analytics | `app/Services/CostTrackingService.php` |

### CircuitBreakerService
Prevents cascading failures:
- Tracks API failure rates
- Opens circuit after threshold
- Half-open state for recovery
- Per-service configuration

### CostTrackingService
Tracks AI API costs:
- Token counting per request
- Model pricing lookup
- Usage aggregation
- Budget alerts

---

## Quality Services

| Service | Purpose | Location |
|---------|---------|----------|
| `AgentSafetyService` | Tool validation | `app/Services/AgentSafetyService.php` |

### AgentSafetyService
Validates agent tool usage:
- Permission checking
- Input sanitization
- Rate limiting
- Audit logging

---

## Service Patterns

### Dependency Injection
All services use Laravel's DI container:
```php
public function __construct(
    private RAGService $ragService,
    private EmbeddingService $embeddingService
) {}
```

### Error Handling
Services should:
- Throw domain-specific exceptions
- Log errors with context
- Return structured error responses

### Testing
Services should have:
- Unit tests for business logic
- Feature tests for integration
- Mocks for external APIs

---

## Configuration Files

| File | Purpose |
|------|---------|
| `config/llm-models.php` | 40+ AI models with pricing |
| `config/rag.php` | RAG settings (threshold, max results) |
| `config/tools.php` | Agent tool definitions |
| `config/services.php` | External service credentials |
