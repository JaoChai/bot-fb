---
id: unit-004-factory-usage
title: Use Factories Effectively
impact: MEDIUM
impactDescription: "Test data creation is verbose and inconsistent"
category: unit
tags: [unit-test, factory, fixtures, laravel]
relatedRules: [unit-001-isolation, feature-004-database-testing]
---

## Why This Matters

Factories create consistent test data with minimal code. Manual object creation is verbose, error-prone, and makes tests harder to maintain.

## Bad Example

```php
public function test_bot_service(): void
{
    // Manual creation - verbose and fragile
    $user = new User();
    $user->name = 'Test User';
    $user->email = 'test@example.com';
    $user->password = Hash::make('password');
    $user->save();

    $bot = new Bot();
    $bot->user_id = $user->id;
    $bot->name = 'Test Bot';
    $bot->platform = 'line';
    $bot->is_active = true;
    $bot->save();

    // More manual setup...
}

// Duplicate data in every test
public function test_another_thing(): void
{
    $user = new User();
    $user->name = 'Test User';
    // Same code again...
}
```

**Why it's problematic:**
- Verbose test setup
- Duplicated code
- Breaks when schema changes
- Missing default values

## Good Example

```php
// database/factories/BotFactory.php
class BotFactory extends Factory
{
    protected $model = Bot::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company() . ' Bot',
            'platform' => fake()->randomElement(['line', 'telegram']),
            'is_active' => true,
            'created_at' => now(),
        ];
    }

    // States for common scenarios
    public function line(): static
    {
        return $this->state(['platform' => 'line']);
    }

    public function telegram(): static
    {
        return $this->state(['platform' => 'telegram']);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withConversations(int $count = 3): static
    {
        return $this->has(Conversation::factory()->count($count));
    }
}

// Clean test code
class BotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_active_bots(): void
    {
        $user = User::factory()->create();

        // Create test data with factories
        Bot::factory()->count(3)->create(['user_id' => $user->id]);
        Bot::factory()->inactive()->create(['user_id' => $user->id]);

        $service = app(BotService::class);
        $bots = $service->getActive($user);

        $this->assertCount(3, $bots);
    }

    public function test_counts_conversations(): void
    {
        $bot = Bot::factory()
            ->withConversations(5)
            ->create();

        $count = $bot->conversations()->count();

        $this->assertEquals(5, $count);
    }

    public function test_filters_by_platform(): void
    {
        $user = User::factory()->create();

        Bot::factory()->line()->count(2)->create(['user_id' => $user->id]);
        Bot::factory()->telegram()->count(3)->create(['user_id' => $user->id]);

        $lineBots = $user->bots()->where('platform', 'line')->count();

        $this->assertEquals(2, $lineBots);
    }
}
```

**Why it's better:**
- Minimal test setup code
- Reusable states
- Relationships handled
- Consistent defaults

## Test Coverage

| Factory Feature | Use Case |
|-----------------|----------|
| Basic create | Simple test data |
| States | Variations (active/inactive) |
| Relationships | has(), for() |
| Sequences | Unique sequential data |
| Callbacks | Post-create actions |

## Run Command

```bash
# Test factory definitions
php artisan test --filter FactoryTest
```

## Project-Specific Notes

**BotFacebook Factory Patterns:**

```php
// ConversationFactory with message chain
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bot_id' => Bot::factory(),
            'platform_user_id' => fake()->uuid(),
            'platform_user_name' => fake()->name(),
        ];
    }

    public function withMessages(int $count = 5): static
    {
        return $this->has(
            Message::factory()
                ->count($count)
                ->sequence(
                    ['role' => 'user'],
                    ['role' => 'assistant'],
                )
        );
    }

    public function line(): static
    {
        return $this->state(fn () => [
            'platform_user_id' => 'U' . fake()->uuid(),
        ]);
    }
}

// KnowledgeDocumentFactory with embeddings
class KnowledgeDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'knowledge_base_id' => KnowledgeBase::factory(),
            'title' => fake()->sentence(),
            'content' => fake()->paragraphs(3, true),
        ];
    }

    public function withEmbedding(): static
    {
        return $this->has(
            DocumentChunk::factory()
                ->state(['embedding' => array_fill(0, 1536, 0.1)])
        );
    }
}
```
