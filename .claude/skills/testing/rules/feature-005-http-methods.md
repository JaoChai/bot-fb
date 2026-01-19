---
id: feature-005-http-methods
title: Test All HTTP Methods Properly
impact: MEDIUM
impactDescription: "Wrong HTTP method accepted or rejected incorrectly"
category: feature
tags: [feature-test, http, methods, rest]
relatedRules: [feature-003-response-format, feature-001-auth-testing]
---

## Why This Matters

RESTful APIs should only accept the correct HTTP methods. Tests verify that endpoints reject wrong methods and behave correctly for correct ones.

## Bad Example

```php
class BotControllerTest extends TestCase
{
    public function test_bots_endpoint(): void
    {
        // Only tests GET
        $response = $this->getJson('/api/v1/bots');
        $response->assertOk();
    }

    // Missing tests for other methods
}
```

**Why it's problematic:**
- POST to GET endpoint might work
- DELETE without auth might work
- PATCH vs PUT not distinguished
- Method vulnerabilities missed

## Good Example

```php
class BotControllerTest extends TestCase
{
    use RefreshDatabase;

    // Index - GET only
    public function test_index_accepts_get(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/bots');

        $response->assertOk();
    }

    public function test_index_rejects_post(): void
    {
        $user = User::factory()->create();

        // POST to index should go to store, but without data fails validation
        // or use wrong endpoint pattern
        $response = $this->actingAs($user)
            ->postJson('/api/v1/bots/index');  // Wrong endpoint

        $response->assertNotFound();
    }

    // Store - POST only
    public function test_store_accepts_post(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/bots', [
                'name' => 'Test',
                'platform' => 'line',
            ]);

        $response->assertCreated();
    }

    public function test_store_rejects_get(): void
    {
        $user = User::factory()->create();

        // GET with query params shouldn't create
        $response = $this->actingAs($user)
            ->getJson('/api/v1/bots?name=Test&platform=line');

        // Returns list, doesn't create
        $response->assertOk();
        $this->assertEquals(0, Bot::count());
    }

    // Update - PUT/PATCH
    public function test_update_accepts_put(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->putJson("/api/v1/bots/{$bot->id}", [
                'name' => 'Updated',
                'platform' => 'line',
            ]);

        $response->assertOk();
    }

    public function test_update_accepts_patch(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->patchJson("/api/v1/bots/{$bot->id}", [
                'name' => 'Patched',
            ]);

        $response->assertOk();
    }

    public function test_update_rejects_post(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/bots/{$bot->id}", [
                'name' => 'Should Fail',
            ]);

        $response->assertMethodNotAllowed();
    }

    // Delete - DELETE only
    public function test_destroy_accepts_delete(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->deleteJson("/api/v1/bots/{$bot->id}");

        $response->assertNoContent();
    }

    public function test_destroy_rejects_get(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        // GET should show, not delete
        $response = $this->actingAs($user)
            ->getJson("/api/v1/bots/{$bot->id}");

        $response->assertOk();
        $this->assertDatabaseHas('bots', ['id' => $bot->id, 'deleted_at' => null]);
    }
}
```

**Why it's better:**
- Tests correct methods
- Tests rejected methods
- Verifies REST semantics
- Catches method vulnerabilities

## Test Coverage

| Endpoint | Methods to Test |
|----------|-----------------|
| index | GET ✓, POST ✗ |
| store | POST ✓, GET ✗ |
| show | GET ✓ |
| update | PUT ✓, PATCH ✓, POST ✗ |
| destroy | DELETE ✓, GET ✗ |

## Run Command

```bash
# Run HTTP method tests
php artisan test --filter "accepts\|rejects"
```

## Project-Specific Notes

**BotFacebook HTTP Method Testing:**

```php
// Custom action endpoints
class BotControllerTest extends TestCase
{
    // Activate - POST only
    public function test_activate_requires_post(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->inactive()->create(['user_id' => $user->id]);

        // POST works
        $response = $this->actingAs($user)
            ->postJson("/api/v1/bots/{$bot->id}/activate");

        $response->assertOk();
        $this->assertTrue($bot->fresh()->is_active);
    }

    public function test_activate_rejects_get(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->inactive()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/bots/{$bot->id}/activate");

        $response->assertMethodNotAllowed();
        $this->assertFalse($bot->fresh()->is_active);
    }

    // Test webhook endpoint
    public function test_webhook_only_accepts_post(): void
    {
        $bot = Bot::factory()->create();

        // POST works
        $response = $this->postJson("/api/webhook/line/{$bot->id}");
        $response->assertOk();

        // GET fails
        $response = $this->getJson("/api/webhook/line/{$bot->id}");
        $response->assertMethodNotAllowed();
    }
}
```
