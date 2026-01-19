---
id: feature-001-auth-testing
title: Test Authentication Properly
impact: CRITICAL
impactDescription: "Auth bypass vulnerabilities missed in testing"
category: feature
tags: [feature-test, auth, sanctum, security]
relatedRules: [feature-002-validation-testing, feature-003-response-format]
---

## Why This Matters

Authentication is critical. Tests must verify that unauthenticated users can't access protected endpoints and that users can only access their own data.

## Bad Example

```php
class BotControllerTest extends TestCase
{
    public function test_gets_bots(): void
    {
        // No auth testing!
        $bots = Bot::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/bots');

        $response->assertOk();
    }

    public function test_updates_bot(): void
    {
        $bot = Bot::factory()->create();

        // Authenticated but not checking ownership
        $response = $this->actingAs(User::factory()->create())
            ->putJson("/api/v1/bots/{$bot->id}", ['name' => 'New Name']);

        $response->assertOk();  // This shouldn't pass!
    }
}
```

**Why it's problematic:**
- No test for unauthenticated access
- No test for authorization (ownership)
- IDOR vulnerabilities missed
- Security holes in production

## Good Example

```php
class BotControllerTest extends TestCase
{
    use RefreshDatabase;

    // Test unauthenticated access
    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/bots');

        $response->assertUnauthorized();
    }

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/bots', [
            'name' => 'Test Bot',
            'platform' => 'line',
        ]);

        $response->assertUnauthorized();
    }

    // Test authorization (ownership)
    public function test_user_can_only_see_own_bots(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Bot::factory()->count(2)->create(['user_id' => $user->id]);
        Bot::factory()->count(3)->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/bots');

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_user_cannot_view_other_users_bot(): void
    {
        $user = User::factory()->create();
        $otherBot = Bot::factory()->create();  // Different user

        $response = $this->actingAs($user)
            ->getJson("/api/v1/bots/{$otherBot->id}");

        $response->assertForbidden();
    }

    public function test_user_cannot_update_other_users_bot(): void
    {
        $user = User::factory()->create();
        $otherBot = Bot::factory()->create();

        $response = $this->actingAs($user)
            ->putJson("/api/v1/bots/{$otherBot->id}", [
                'name' => 'Hacked Name',
            ]);

        $response->assertForbidden();

        // Verify data unchanged
        $this->assertDatabaseMissing('bots', ['name' => 'Hacked Name']);
    }

    public function test_user_cannot_delete_other_users_bot(): void
    {
        $user = User::factory()->create();
        $otherBot = Bot::factory()->create();

        $response = $this->actingAs($user)
            ->deleteJson("/api/v1/bots/{$otherBot->id}");

        $response->assertForbidden();

        // Verify not deleted
        $this->assertDatabaseHas('bots', ['id' => $otherBot->id]);
    }

    // Test proper authenticated access
    public function test_user_can_update_own_bot(): void
    {
        $user = User::factory()->create();
        $bot = Bot::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->putJson("/api/v1/bots/{$bot->id}", [
                'name' => 'Updated Name',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('bots', [
            'id' => $bot->id,
            'name' => 'Updated Name',
        ]);
    }
}
```

**Why it's better:**
- Tests all auth scenarios
- Tests ownership/authorization
- Verifies data protection
- Catches IDOR bugs

## Test Coverage

| Scenario | Priority |
|----------|----------|
| Unauthenticated access | Must test |
| Access own resource | Must test |
| Access other's resource | Must test |
| Admin access | Should test |
| Token scopes | Should test |

## Run Command

```bash
# Run auth tests
php artisan test --filter auth --filter Auth

# Test specific authorization
php artisan test --filter "cannot.*other"
```

## Project-Specific Notes

**BotFacebook Auth Testing:**

```php
// Base class for auth tests
abstract class AuthenticatedTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
    }

    protected function asUser(): static
    {
        $this->actingAs($this->user);
        return $this;
    }
}

// Controller test
class ConversationControllerTest extends AuthenticatedTestCase
{
    public function test_user_cannot_access_other_users_conversation(): void
    {
        $otherBot = Bot::factory()->create(['user_id' => $this->otherUser->id]);
        $conversation = Conversation::factory()->create(['bot_id' => $otherBot->id]);

        $response = $this->asUser()
            ->getJson("/api/v1/conversations/{$conversation->id}");

        $response->assertForbidden();
    }
}
```
