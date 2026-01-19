---
id: feature-003-response-format
title: Verify API Response Format
impact: HIGH
impactDescription: "Frontend breaks due to unexpected response structure"
category: feature
tags: [feature-test, api, response, json]
relatedRules: [feature-002-validation-testing, unit-003-assertion-quality]
---

## Why This Matters

Consistent API response format ensures frontend can reliably parse responses. Tests should verify structure, not just status codes.

## Bad Example

```php
public function test_returns_bots(): void
{
    $user = User::factory()->create();
    Bot::factory()->count(3)->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)
        ->getJson('/api/v1/bots');

    // Only checks status
    $response->assertOk();
}

public function test_creates_bot(): void
{
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/bots', [
            'name' => 'Test',
            'platform' => 'line',
        ]);

    // Only checks status
    $response->assertCreated();
}
```

**Why it's problematic:**
- Structure changes break frontend
- Missing fields not caught
- Wrong types not detected
- Inconsistent responses

## Good Example

```php
class BotControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_correct_structure(): void
    {
        $user = User::factory()->create();
        Bot::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/bots');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'platform',
                        'is_active',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'meta' => [
                    'timestamp',
                ],
            ])
            ->assertJsonCount(3, 'data');
    }

    public function test_show_returns_complete_bot_data(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test Bot',
            'platform' => 'line',
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/bots/{$bot->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'platform',
                    'is_active',
                    'settings',
                    'stats' => [
                        'conversations_count',
                        'messages_count',
                    ],
                    'created_at',
                    'updated_at',
                ],
                'meta' => ['timestamp'],
            ])
            ->assertJson([
                'data' => [
                    'id' => $bot->id,
                    'name' => 'Test Bot',
                    'platform' => 'line',
                ],
            ]);
    }

    public function test_store_returns_created_bot(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/bots', [
                'name' => 'New Bot',
                'platform' => 'telegram',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'platform',
                    'is_active',
                ],
                'meta' => ['timestamp'],
            ])
            ->assertJsonPath('data.name', 'New Bot')
            ->assertJsonPath('data.platform', 'telegram')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_paginated_response_has_correct_meta(): void
    {
        $user = User::factory()->create();
        Bot::factory()->count(15)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/bots?page=1&per_page=10');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                    'timestamp',
                ],
            ])
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonCount(10, 'data');
    }

    public function test_error_response_format(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/bots/99999');

        $response->assertNotFound()
            ->assertJsonStructure([
                'error' => [
                    'message',
                    'code',
                ],
                'meta' => ['timestamp'],
            ]);
    }
}
```

**Why it's better:**
- Verifies complete structure
- Checks specific values
- Tests pagination format
- Tests error format

## Test Coverage

| Response Type | Verify |
|--------------|--------|
| List | Structure, count, pagination |
| Single resource | All fields, relationships |
| Create | Returned data matches input |
| Error | Error format, code |

## Run Command

```bash
# Run response tests
php artisan test --filter response --filter Response
```

## Project-Specific Notes

**BotFacebook API Response Format:**

```php
// Standard envelope
{
    "data": { ... },
    "meta": {
        "timestamp": "2024-01-15T10:30:00Z"
    }
}

// Paginated
{
    "data": [ ... ],
    "links": {
        "first": "...",
        "last": "...",
        "prev": null,
        "next": "..."
    },
    "meta": {
        "current_page": 1,
        "last_page": 3,
        "per_page": 10,
        "total": 25,
        "timestamp": "..."
    }
}

// Error
{
    "error": {
        "message": "Bot not found",
        "code": "NOT_FOUND"
    },
    "meta": {
        "timestamp": "..."
    }
}

// Test helper
trait ApiAssertions
{
    protected function assertApiSuccess(TestResponse $response): void
    {
        $response->assertSuccessful()
            ->assertJsonStructure([
                'data',
                'meta' => ['timestamp'],
            ]);
    }

    protected function assertApiError(TestResponse $response, int $status): void
    {
        $response->assertStatus($status)
            ->assertJsonStructure([
                'error' => ['message', 'code'],
                'meta' => ['timestamp'],
            ]);
    }
}
```
