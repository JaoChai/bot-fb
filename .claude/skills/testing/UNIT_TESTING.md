# Unit Testing Guide (PHPUnit)

## Setup

### Test Base Class

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}
```

### Test Traits

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

class BotServiceTest extends TestCase
{
    use RefreshDatabase; // Reset DB between tests
    use WithFaker;       // Generate fake data
}
```

## Service Tests

### Basic Pattern

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Bot;
use App\Models\User;
use App\Services\BotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotServiceTest extends TestCase
{
    use RefreshDatabase;

    private BotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BotService::class);
    }

    public function test_creates_bot_with_valid_data(): void
    {
        // Arrange
        $user = User::factory()->create();
        $data = [
            'name' => 'Test Bot',
            'platform' => 'line',
        ];

        // Act
        $bot = $this->service->create($user, $data);

        // Assert
        $this->assertInstanceOf(Bot::class, $bot);
        $this->assertEquals('Test Bot', $bot->name);
        $this->assertEquals('line', $bot->platform);
        $this->assertEquals($user->id, $bot->user_id);
    }

    public function test_throws_exception_for_invalid_platform(): void
    {
        // Arrange
        $user = User::factory()->create();

        // Assert & Act
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create($user, ['name' => 'Bot', 'platform' => 'invalid']);
    }
}
```

### Testing with Mocks

```php
use Mockery;

public function test_sends_notification_on_creation(): void
{
    // Mock notification service
    $notificationMock = Mockery::mock(NotificationService::class);
    $notificationMock->shouldReceive('send')
        ->once()
        ->with(Mockery::type(User::class), Mockery::type('string'));

    $this->app->instance(NotificationService::class, $notificationMock);

    $service = app(BotService::class);
    $user = User::factory()->create();

    $service->create($user, ['name' => 'Bot', 'platform' => 'line']);
}
```

### Testing Database Queries

```php
public function test_returns_active_bots_only(): void
{
    $user = User::factory()->create();

    // Create bots
    Bot::factory()->active()->count(3)->create(['user_id' => $user->id]);
    Bot::factory()->inactive()->count(2)->create(['user_id' => $user->id]);

    // Act
    $activeBots = $this->service->getActiveBots($user);

    // Assert
    $this->assertCount(3, $activeBots);
    $activeBots->each(fn($bot) => $this->assertTrue($bot->is_active));
}
```

## Model Tests

### Testing Relationships

```php
public function test_bot_belongs_to_user(): void
{
    $user = User::factory()->create();
    $bot = Bot::factory()->create(['user_id' => $user->id]);

    $this->assertInstanceOf(User::class, $bot->user);
    $this->assertEquals($user->id, $bot->user->id);
}

public function test_bot_has_many_conversations(): void
{
    $bot = Bot::factory()->create();
    Conversation::factory()->count(3)->create(['bot_id' => $bot->id]);

    $this->assertCount(3, $bot->conversations);
}
```

### Testing Scopes

```php
public function test_active_scope_filters_correctly(): void
{
    Bot::factory()->active()->count(2)->create();
    Bot::factory()->inactive()->count(3)->create();

    $active = Bot::active()->get();

    $this->assertCount(2, $active);
}
```

### Testing Accessors/Mutators

```php
public function test_full_name_accessor(): void
{
    $user = User::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
    ]);

    $this->assertEquals('John Doe', $user->full_name);
}

public function test_email_mutator_lowercases(): void
{
    $user = User::factory()->create([
        'email' => 'TEST@EXAMPLE.COM',
    ]);

    $this->assertEquals('test@example.com', $user->email);
}
```

## Testing Exceptions

```php
public function test_throws_not_found_exception(): void
{
    $this->expectException(ModelNotFoundException::class);

    $this->service->findOrFail(999);
}

public function test_throws_validation_exception_with_message(): void
{
    $this->expectException(ValidationException::class);
    $this->expectExceptionMessage('The name field is required');

    $this->service->create(User::factory()->create(), []);
}
```

## Data Providers

```php
/**
 * @dataProvider validPlatformProvider
 */
public function test_accepts_valid_platforms(string $platform): void
{
    $user = User::factory()->create();
    $bot = $this->service->create($user, [
        'name' => 'Test',
        'platform' => $platform,
    ]);

    $this->assertEquals($platform, $bot->platform);
}

public static function validPlatformProvider(): array
{
    return [
        'line' => ['line'],
        'telegram' => ['telegram'],
        'messenger' => ['messenger'],
    ];
}

/**
 * @dataProvider invalidDataProvider
 */
public function test_rejects_invalid_data(array $data, string $expectedError): void
{
    $this->expectException(ValidationException::class);
    $this->expectExceptionMessage($expectedError);

    $this->service->create(User::factory()->create(), $data);
}

public static function invalidDataProvider(): array
{
    return [
        'missing name' => [
            ['platform' => 'line'],
            'name field is required'
        ],
        'invalid platform' => [
            ['name' => 'Bot', 'platform' => 'invalid'],
            'platform is invalid'
        ],
    ];
}
```

## Factories

### Basic Factory

```php
<?php

namespace Database\Factories;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class BotFactory extends Factory
{
    protected $model = Bot::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => $this->faker->words(2, true),
            'platform' => $this->faker->randomElement(['line', 'telegram']),
            'is_active' => true,
        ];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function line(): static
    {
        return $this->state(['platform' => 'line']);
    }
}
```

### Factory with Relationships

```php
// Create bot with settings
$bot = Bot::factory()
    ->has(BotSettings::factory())
    ->create();

// Create bot with 5 conversations
$bot = Bot::factory()
    ->has(Conversation::factory()->count(5))
    ->create();

// Create bot with specific user
$user = User::factory()->create();
$bot = Bot::factory()
    ->for($user)
    ->create();
```

## Running Tests

```bash
# All tests
php artisan test

# Unit tests only
php artisan test --filter Unit

# Specific test class
php artisan test --filter BotServiceTest

# Specific test method
php artisan test --filter test_creates_bot_with_valid_data

# With coverage
php artisan test --coverage

# Parallel execution
php artisan test --parallel

# Stop on first failure
php artisan test --stop-on-failure
```

## Best Practices

### DO
- One assertion per test (when practical)
- Use descriptive test names
- Test edge cases
- Use factories for test data
- Clean up after tests (RefreshDatabase)

### DON'T
- Test framework code
- Test private methods directly
- Use real external services
- Share state between tests
- Write tests that depend on order
