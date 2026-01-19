---
id: feature-002-validation-testing
title: Test Request Validation Thoroughly
impact: HIGH
impactDescription: "Invalid data accepted, causing bugs or security issues"
category: feature
tags: [feature-test, validation, formrequest, security]
relatedRules: [feature-001-auth-testing, feature-003-response-format]
---

## Why This Matters

Validation tests ensure bad data is rejected before it causes problems. Missing validation tests can lead to data corruption, security holes, and crashes.

## Bad Example

```php
public function test_creates_bot(): void
{
    $user = User::factory()->create();

    // Only tests happy path
    $response = $this->actingAs($user)
        ->postJson('/api/v1/bots', [
            'name' => 'Test Bot',
            'platform' => 'line',
        ]);

    $response->assertCreated();
}

// No validation tests at all!
```

**Why it's problematic:**
- Only happy path tested
- Invalid data might be accepted
- Missing fields not caught
- Type coercion bugs missed

## Good Example

```php
class BotControllerTest extends TestCase
{
    use RefreshDatabase;

    // Happy path
    public function test_creates_bot_with_valid_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/bots', [
                'name' => 'Test Bot',
                'platform' => 'line',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Test Bot');
    }

    // Required field validation
    public function test_validates_name_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/bots', [
                'platform' => 'line',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_validates_platform_is_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/bots', [
                'name' => 'Test Bot',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['platform']);
    }

    // Type validation
    public function test_validates_platform_is_valid_enum(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/bots', [
                'name' => 'Test Bot',
                'platform' => 'invalid_platform',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['platform']);
    }

    // Length validation
    public function test_validates_name_max_length(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/bots', [
                'name' => str_repeat('a', 256),  // Too long
                'platform' => 'line',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    // Format validation
    public function test_validates_webhook_url_format(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/bots', [
                'name' => 'Test Bot',
                'platform' => 'line',
                'webhook_url' => 'not-a-url',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['webhook_url']);
    }

    // Multiple validation errors
    public function test_returns_all_validation_errors(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/bots', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'platform']);
    }

    // Update validation (different rules)
    public function test_update_allows_partial_data(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->putJson("/api/v1/bots/{$bot->id}", [
                'name' => 'New Name',
                // platform not required on update
            ]);

        $response->assertOk();
    }
}
```

**Why it's better:**
- Tests all validation rules
- Tests required fields
- Tests formats and types
- Tests multiple errors

## Test Coverage

| Validation Type | Tests Needed |
|-----------------|-------------|
| Required fields | Each required field |
| Max/min length | Boundary values |
| Format (email, url) | Invalid formats |
| Enum/in | Invalid values |
| Unique | Duplicate values |
| Conditional | When rules apply |

## Run Command

```bash
# Run validation tests
php artisan test --filter validation --filter Validation
```

## Project-Specific Notes

**BotFacebook Validation Testing:**

```php
// Test knowledge document validation
class KnowledgeDocumentControllerTest extends TestCase
{
    public function test_validates_content_is_required(): void
    {
        $user = User::factory()->create();
        $knowledgeBase = KnowledgeBase::factory()->create([
            'bot_id' => Bot::factory()->create(['user_id' => $user->id])->id,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/knowledge-bases/{$knowledgeBase->id}/documents", [
                'title' => 'Test Document',
                // missing content
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);
    }

    public function test_validates_content_max_length(): void
    {
        $user = User::factory()->create();
        $knowledgeBase = KnowledgeBase::factory()->create([
            'bot_id' => Bot::factory()->create(['user_id' => $user->id])->id,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/knowledge-bases/{$knowledgeBase->id}/documents", [
                'title' => 'Test Document',
                'content' => str_repeat('a', 100001),  // Over 100k limit
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['content']);
    }
}
```
