---
id: backend-005-service-dependencies
title: Service Dependencies Management
impact: MEDIUM
impactDescription: "Too many dependencies indicate a service doing too much"
category: backend
tags: [laravel, service, dependency-injection, solid]
relatedRules: [backend-002-service-layer]
---

## Why This Matters

Services with many dependencies (>5) often violate Single Responsibility Principle. They're harder to test, maintain, and understand.

## Bad Example

```php
class BotService
{
    public function __construct(
        private UserService $userService,
        private ConversationService $conversationService,
        private MessageService $messageService,
        private EmbeddingService $embeddingService,
        private SearchService $searchService,
        private CacheService $cacheService,
        private NotificationService $notificationService,
        private AnalyticsService $analyticsService,
        private BillingService $billingService,
        private QueueService $queueService
    ) {}

    // God service with too many responsibilities
}
```

**Why it's wrong:**
- Too many responsibilities
- Hard to test (10 mocks!)
- Changes ripple everywhere
- Likely doing too much

## Good Example

```php
// Split into focused services
class BotService
{
    public function __construct(
        private CacheService $cacheService
    ) {}

    public function create(User $user, array $data): Bot
    {
        // Only CRUD operations
    }
}

class BotSearchService
{
    public function __construct(
        private EmbeddingService $embeddingService,
        private SearchService $searchService
    ) {}

    public function search(Bot $bot, string $query): array
    {
        // Only search-related operations
    }
}

class BotAnalyticsService
{
    public function __construct(
        private AnalyticsService $analyticsService,
        private CacheService $cacheService
    ) {}

    public function getStats(Bot $bot): array
    {
        // Only analytics operations
    }
}
```

**Why it's better:**
- Single responsibility
- Fewer dependencies each
- Easier to test
- Clear purpose

## Review Checklist

- [ ] Services have ≤5 constructor dependencies
- [ ] Each service has single responsibility
- [ ] Services named by what they do
- [ ] No circular dependencies
- [ ] Services don't depend on controllers

## Detection

```bash
# Count constructor parameters
grep -A 15 "public function __construct" app/Services/*.php | grep "private\|protected" | wc -l

# Services with many deps
awk '/public function __construct/,/\)/{count++} count>10{print FILENAME}' app/Services/*.php
```

## Project-Specific Notes

**BotFacebook Service Organization:**

```php
// RAGService - orchestrates but doesn't do everything
class RAGService
{
    public function __construct(
        private OpenRouterService $llm,
        private HybridSearchService $search,
        private PromptBuilder $promptBuilder,
        private CostTrackingService $costs
    ) {}

    // Orchestrates AI flow, delegates details
}

// Specialized services
class EmbeddingService { /* Only embeddings */ }
class SemanticSearchService { /* Only vector search */ }
class HybridSearchService { /* Combines search types */ }

// Each service 3-4 dependencies max
```
