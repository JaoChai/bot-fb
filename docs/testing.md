# Testing Strategy

กลยุทธ์การทดสอบสำหรับโปรเจกต์ BotFacebook

## Testing Pyramid

```
       /\
      /  \  E2E Tests (Critical flows)
     /----\
    /      \ Integration Tests (API + DB)
   /--------\
  /          \ Unit Tests (Services, Models)
 /------------\
```

---

## Unit Tests

### เทสอะไร
- Service layer logic
- Value objects
- Utility functions
- Business rules

### ตัวอย่าง
```php
// tests/Unit/SecondAI/SecondAICheckResultTest.php
class SecondAICheckResultTest extends TestCase
{
    public function test_create_from_json()
    {
        $json = [
            'checks_applied' => ['grammar', 'relevance'],
            'passed' => true,
            'reasoning' => 'All checks passed',
        ];

        $result = SecondAICheckResult::fromJson($json);

        $this->assertTrue($result->passed);
        $this->assertCount(2, $result->checksApplied);
    }

    public function test_detects_grammar_check()
    {
        $result = new SecondAICheckResult(
            checksApplied: ['grammar'],
            passed: true,
            reasoning: 'Grammar is correct'
        );

        $this->assertTrue($result->hasGrammarCheck());
        $this->assertFalse($result->hasRelevanceCheck());
    }
}
```

### รัน Unit Tests
```bash
# รันทั้งหมด
php artisan test --filter Unit

# รันเฉพาะไฟล์
php artisan test tests/Unit/SecondAI/SecondAICheckResultTest.php

# รันเฉพาะ test method
php artisan test --filter test_create_from_json
```

### Coverage Target
- **Services: 80%+**
- **Models: 70%+**
- **Utilities: 90%+**

---

## Feature Tests (Integration Tests)

### เทสอะไร
- API endpoints (request/response)
- Database operations
- Authentication/Authorization
- Validation rules

### ตัวอย่าง
```php
// tests/Feature/BotControllerTest.php
class BotControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_bots()
    {
        $user = User::factory()->create();
        Bot::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)->getJson('/api/v1/bots');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'status', 'channel_type']
                ],
                'meta',
                'errors'
            ])
            ->assertJsonCount(3, 'data');
    }

    public function test_cannot_create_bot_without_auth()
    {
        $response = $this->postJson('/api/v1/bots', [
            'name' => 'Test Bot',
        ]);

        $response->assertUnauthorized();
    }

    public function test_validates_bot_name()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/bots', [
            'name' => '', // Empty name
            'channel_type' => 'line',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }
}
```

### รัน Feature Tests
```bash
# รันทั้งหมด
php artisan test --filter Feature

# รันเฉพาะ controller
php artisan test tests/Feature/BotControllerTest.php
```

### Coverage Target
- **Controllers: 60%+**
- **API Routes: 80%+**
- **Critical flows: 100%**

---

## E2E Tests

### เทสอะไร
- Critical user flows
- Frontend + Backend integration
- Real user scenarios

### ตัวอย่าง Flow
```
1. User Registration Flow
   - ลงทะเบียน → ยืนยัน email → login → dashboard

2. Bot Creation Flow
   - Login → สร้าง bot → ตั้งค่า → ทดสอบ → activate

3. Conversation Flow
   - User ส่งข้อความ → Bot ตอบ → AI evaluation → บันทึก
```

### ใช้ integration-test Set
```
"test user registration flow ทั้งหมด"
"test bot creation และ activation"
```

---

## Test Organization

### โครงสร้าง Directory
```
tests/
├── Unit/
│   ├── SecondAI/
│   │   ├── SecondAICheckResultTest.php
│   │   └── UnifiedCheckServiceTest.php
│   ├── Evaluation/
│   │   └── ModelTierConfigTest.php
│   └── Services/
│       └── BotServiceTest.php
├── Feature/
│   ├── Controllers/
│   │   ├── BotControllerTest.php
│   │   └── ConversationControllerTest.php
│   ├── API/
│   │   ├── AuthenticationTest.php
│   │   └── WebhookTest.php
│   └── Services/
│       └── SecondAIServiceTest.php
└── E2E/
    └── (managed by integration-test set)
```

---

## Test Database

### Setup
```php
// tests/TestCase.php
abstract class TestCase extends BaseTestCase
{
    use CreatesApplication, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed ข้อมูลพื้นฐานที่จำเป็น
        $this->seed(TestDataSeeder::class);
    }
}
```

### ใช้ SQLite สำหรับ Tests
```env
# .env.testing
DB_CONNECTION=sqlite
DB_DATABASE=:memory:
```

---

## Factories

### สร้าง Test Data
```php
// database/factories/BotFactory.php
class BotFactory extends Factory
{
    public function definition()
    {
        return [
            'name' => $this->faker->company(),
            'status' => 'active',
            'channel_type' => 'line',
            'user_id' => User::factory(),
        ];
    }

    public function inactive()
    {
        return $this->state(fn () => ['status' => 'inactive']);
    }
}

// ใช้งาน
Bot::factory()->count(5)->create();
Bot::factory()->inactive()->create();
```

---

## Mocking

### Mock External Services
```php
// Mock OpenRouter API
public function test_ai_evaluation_with_mock()
{
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'choices' => [
                ['message' => ['content' => 'Mocked AI response']]
            ]
        ], 200),
    ]);

    $result = $this->secondAIService->evaluate($message);

    $this->assertNotNull($result);
}
```

### Mock Queue
```php
Queue::fake();

// Action ที่จะ dispatch job
$this->botService->processMessage($message);

// Assert job ถูก dispatch
Queue::assertPushed(ProcessAIEvaluationJob::class);
```

---

## Test Checklist

### Before Writing Tests
- [ ] เข้าใจ requirement ชัดเจน
- [ ] รู้ edge cases ที่ต้องครอบคลุม
- [ ] มี test data พร้อม (factories)

### Test Cases ต้องครอบคลุม
- [ ] **Happy path** - กรณีปกติ
- [ ] **Edge cases** - กรณีขอบ (empty, null, max length)
- [ ] **Error cases** - กรณีผิดพลาด
- [ ] **Authorization** - ตรวจสอบสิทธิ์
- [ ] **Validation** - ตรวจสอบ input

### After Writing Tests
- [ ] Tests ทั้งหมด pass
- [ ] Coverage เพียงพอ
- [ ] Test names ชัดเจน
- [ ] No random test data (ใช้ factories)

---

## Running Tests

### คำสั่งพื้นฐาน
```bash
# รันทั้งหมด
php artisan test

# รัน parallel (เร็วขึ้น)
php artisan test --parallel

# รันเฉพาะที่ fail
php artisan test --failed

# ดู coverage
php artisan test --coverage
php artisan test --coverage-html coverage
```

### Filter Tests
```bash
# By type
php artisan test --filter Unit
php artisan test --filter Feature

# By file
php artisan test tests/Unit/SecondAI/

# By method name
php artisan test --filter test_create_from_json
```

### With Output
```bash
# Verbose mode
php artisan test --verbose

# Stop on first failure
php artisan test --stop-on-failure
```

---

## CI/CD Integration

### GitHub Actions (ตัวอย่าง)
```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.2

      - name: Install Dependencies
        run: composer install

      - name: Run Tests
        run: php artisan test --parallel

      - name: Coverage
        run: php artisan test --coverage --min=70
```

---

## Best Practices

### 1. Test Naming
```php
✅ Good
test_can_create_bot_with_valid_data()
test_validates_bot_name_is_required()
test_returns_404_when_bot_not_found()

❌ Bad
test_bot()
testCreate()
test_validation()
```

### 2. Arrange-Act-Assert Pattern
```php
public function test_can_update_bot()
{
    // Arrange
    $bot = Bot::factory()->create(['name' => 'Old Name']);

    // Act
    $response = $this->putJson("/api/v1/bots/{$bot->id}", [
        'name' => 'New Name'
    ]);

    // Assert
    $response->assertOk();
    $this->assertEquals('New Name', $bot->fresh()->name);
}
```

### 3. One Assertion Per Test (เมื่อเป็นไปได้)
```php
✅ Good - แยก test
test_bot_name_is_required()
test_bot_channel_type_is_required()

❌ Bad - รวมหลาย assertion
test_bot_validation()
```

### 4. Don't Test Framework
```php
❌ Bad - testing Laravel's functionality
public function test_eloquent_creates_record()
{
    $bot = Bot::create(['name' => 'Test']);
    $this->assertDatabaseHas('bots', ['name' => 'Test']);
}

✅ Good - testing YOUR business logic
public function test_bot_service_activates_bot()
{
    $bot = Bot::factory()->inactive()->create();

    $this->botService->activate($bot);

    $this->assertTrue($bot->fresh()->isActive());
}
```

### 5. Clean Up After Tests
```php
// ใช้ RefreshDatabase trait
use RefreshDatabase;

// หรือ DatabaseTransactions
use DatabaseTransactions;

// Cleanup files
protected function tearDown(): void
{
    Storage::fake('local')->deleteDirectory('test-uploads');
    parent::tearDown();
}
```

---

## Debugging Tests

### ดู SQL Queries
```php
DB::enableQueryLog();

// Your test code

dd(DB::getQueryLog());
```

### ดู Response Content
```php
$response = $this->getJson('/api/v1/bots');

// ดู response structure
$response->dump();

// ดูเฉพาะ data
$response->json('data'); // returns array
```

### Debug Specific Test
```php
// เพิ่ม dd() หรือ dump() ใน test
public function test_something()
{
    $result = $this->service->doSomething();

    dd($result); // Stop and dump

    $this->assertTrue($result);
}
```

---

## Common Patterns

### Testing JSON API
```php
$response = $this->getJson('/api/v1/bots')
    ->assertOk()
    ->assertJsonStructure([
        'data' => [
            '*' => ['id', 'name', 'status']
        ],
        'meta' => ['timestamp'],
        'errors'
    ])
    ->assertJsonPath('data.0.name', 'Expected Name');
```

### Testing Authentication
```php
// Without auth
$response = $this->getJson('/api/v1/bots');
$response->assertUnauthorized();

// With auth
$user = User::factory()->create();
$response = $this->actingAs($user)->getJson('/api/v1/bots');
$response->assertOk();
```

### Testing Validation
```php
$response = $this->postJson('/api/v1/bots', [
    'name' => '', // Invalid
])
    ->assertStatus(422)
    ->assertJsonValidationErrors(['name'])
    ->assertJsonPath('errors.0.field', 'name');
```

### Testing Database
```php
// Assert record exists
$this->assertDatabaseHas('bots', ['name' => 'Test Bot']);

// Assert record missing
$this->assertDatabaseMissing('bots', ['id' => 999]);

// Assert count
$this->assertDatabaseCount('bots', 5);
```

---

## Performance Testing

### Benchmark Critical Operations
```php
public function test_ai_evaluation_performance()
{
    $start = microtime(true);

    $this->secondAIService->evaluate($message);

    $duration = microtime(true) - $start;

    $this->assertLessThan(1.5, $duration, 'AI evaluation took too long');
}
```

### Query Count
```php
DB::enableQueryLog();

$bots = Bot::with('conversations')->get();

$queries = count(DB::getQueryLog());
$this->assertLessThan(5, $queries, 'Too many queries (N+1?)');
```

---

## Resources

- [Laravel Testing Docs](https://laravel.com/docs/testing)
- [PHPUnit Manual](https://phpunit.de/manual/current/en/)
- [Test-Driven Development](https://martinfowler.com/bliki/TestDrivenDevelopment.html)
