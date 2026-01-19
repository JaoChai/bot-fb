---
id: eloquent-007-factories
title: Model Factories
impact: LOW
impactDescription: "Enables consistent test data generation and database seeding"
category: eloquent
tags: [eloquent, testing, factory, seeding]
relatedRules: []
---

## Why This Matters

Factories generate fake model instances for testing and seeding. They ensure consistent test data, make tests readable, and allow creating complex object graphs easily.

## Bad Example

```php
// Problem: Inline test data creation
public function test_user_can_create_bot()
{
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);

    $this->actingAs($user)
        ->post('/api/v1/bots', [
            'name' => 'My Bot',
            'platform' => 'line',
        ]);
}
```

**Why it's wrong:**
- Manual data creation
- Hard to maintain
- Not reusable
- Missing relationships

## Good Example

```php
// database/factories/BotFactory.php
class BotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->company() . ' Bot',
            'platform' => fake()->randomElement(['line', 'telegram', 'messenger']),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    // States for specific scenarios
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function line(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => 'line',
        ]);
    }

    // With relationships
    public function withFlows(int $count = 3): static
    {
        return $this->has(Flow::factory()->count($count));
    }

    public function withSettings(): static
    {
        return $this->has(BotSettings::factory());
    }
}

// Usage in tests
public function test_user_can_create_bot()
{
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/api/v1/bots', [
            'name' => 'My Bot',
            'platform' => 'line',
        ])
        ->assertCreated();
}

public function test_inactive_bots_not_shown()
{
    $user = User::factory()->create();
    Bot::factory()->for($user)->inactive()->count(3)->create();
    Bot::factory()->for($user)->count(2)->create();

    $this->actingAs($user)
        ->get('/api/v1/bots')
        ->assertJsonCount(2, 'data');
}

// Complex setup
$bot = Bot::factory()
    ->for($user)
    ->line()
    ->withSettings()
    ->withFlows(5)
    ->create();
```

**Why it's better:**
- Consistent test data
- Readable tests
- Reusable states
- Easy relationship setup

## Project-Specific Notes

**BotFacebook Factory Organization:**
```
database/factories/
├── BotFactory.php
├── FlowFactory.php
├── ConversationFactory.php
├── MessageFactory.php
├── KnowledgeBaseFactory.php
└── UserFactory.php
```

**Common Patterns:**
```php
// Create without saving
$bot = Bot::factory()->make();

// Create multiple
$bots = Bot::factory()->count(10)->create();

// With specific relationship
Bot::factory()->for($user)->create();
Bot::factory()->for(User::factory()->admin())->create();

// Sequence
Bot::factory()->count(3)->sequence(
    ['platform' => 'line'],
    ['platform' => 'telegram'],
    ['platform' => 'messenger'],
)->create();
```

## References

- [Laravel Model Factories](https://laravel.com/docs/eloquent-factories)
