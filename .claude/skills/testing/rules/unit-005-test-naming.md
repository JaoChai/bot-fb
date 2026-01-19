---
id: unit-005-test-naming
title: Use Descriptive Test Names
impact: MEDIUM
impactDescription: "Unclear test names make failures hard to diagnose"
category: unit
tags: [unit-test, naming, phpunit, conventions]
relatedRules: [unit-003-assertion-quality, unit-006-arrange-act-assert]
---

## Why This Matters

When tests fail, the name is the first clue to what's wrong. Descriptive names explain what behavior is being tested without reading the code.

## Bad Example

```php
class BotServiceTest extends TestCase
{
    public function test1(): void { ... }

    public function testBot(): void { ... }

    public function test_it_works(): void { ... }

    public function testCreateBotMethod(): void { ... }

    public function test_bot_creation(): void
    {
        // What aspect of creation? Happy path? Error case?
    }
}

// When this fails: "test_bot_creation failed"
// What went wrong? No idea.
```

**Why it's problematic:**
- No context in failure message
- Must read code to understand
- Hard to find related tests
- No documentation value

## Good Example

```php
class BotServiceTest extends TestCase
{
    // Pattern: test_{action}_{scenario}_{expected_outcome}

    public function test_create_bot_with_valid_data_returns_bot_instance(): void
    {
        // Happy path test
    }

    public function test_create_bot_without_name_throws_validation_exception(): void
    {
        // Validation failure test
    }

    public function test_create_bot_with_invalid_platform_throws_validation_exception(): void
    {
        // Another validation test
    }

    public function test_create_bot_sets_default_active_status(): void
    {
        // Default value test
    }

    public function test_create_bot_assigns_user_as_owner(): void
    {
        // Relationship test
    }

    // Alternative: PHPUnit attributes
    #[Test]
    public function creating_bot_with_valid_data_returns_instance(): void
    {
        // Using #[Test] attribute
    }

    // Feature-focused naming
    public function test_user_can_create_line_bot(): void { }
    public function test_user_can_create_telegram_bot(): void { }
    public function test_user_cannot_create_bot_without_subscription(): void { }
}
```

**Why it's better:**
- Clear failure messages
- Self-documenting
- Easy to find tests
- Groups related tests

## Test Coverage

| Naming Pattern | Example |
|----------------|---------|
| Happy path | `test_creates_bot_with_valid_data` |
| Validation | `test_rejects_invalid_platform` |
| Edge case | `test_handles_empty_name` |
| Error handling | `test_throws_exception_on_api_failure` |
| State change | `test_deactivates_existing_bot` |

## Run Command

```bash
# List all tests to review naming
php artisan test --list-tests

# Run with verbose to see names
php artisan test --filter BotServiceTest -v
```

## Project-Specific Notes

**BotFacebook Test Naming Conventions:**

```php
// Service tests
class RAGServiceTest extends TestCase
{
    // Context retrieval
    public function test_retrieves_relevant_context_for_query(): void { }
    public function test_returns_empty_when_no_knowledge_base(): void { }
    public function test_filters_context_below_threshold(): void { }

    // Response generation
    public function test_generates_response_with_context(): void { }
    public function test_generates_response_without_context(): void { }
    public function test_includes_sources_in_response(): void { }

    // Error handling
    public function test_throws_exception_when_llm_unavailable(): void { }
    public function test_falls_back_on_embedding_error(): void { }
}

// Controller tests
class BotControllerTest extends TestCase
{
    // CRUD operations
    public function test_index_returns_user_bots_only(): void { }
    public function test_store_creates_bot_for_authenticated_user(): void { }
    public function test_show_returns_404_for_other_users_bot(): void { }
    public function test_update_modifies_bot_settings(): void { }
    public function test_destroy_soft_deletes_bot(): void { }

    // Authorization
    public function test_guest_cannot_access_bots(): void { }
    public function test_user_cannot_access_other_users_bots(): void { }
}
```

**Test Grouping with Attributes:**

```php
#[Group('unit')]
#[Group('rag')]
class RAGServiceTest extends TestCase
{
    #[Test]
    #[Group('critical')]
    public function retrieves_context_successfully(): void { }
}

// Run grouped tests
// php artisan test --group=rag
// php artisan test --group=critical
```
