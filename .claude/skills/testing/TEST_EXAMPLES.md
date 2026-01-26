# Test Code Examples

## Unit Test - Service Pattern

```php
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
        $user = User::factory()->create();
        $data = [
            'name' => 'Test Bot',
            'platform' => 'line',
        ];

        $bot = $this->service->create($user, $data);

        $this->assertInstanceOf(Bot::class, $bot);
        $this->assertEquals('Test Bot', $bot->name);
        $this->assertDatabaseHas('bots', ['name' => 'Test Bot']);
    }

    public function test_throws_exception_for_invalid_platform(): void
    {
        $this->expectException(ValidationException::class);

        $user = User::factory()->create();
        $this->service->create($user, ['platform' => 'invalid']);
    }
}
```

## Feature Test - Controller Pattern

```php
class BotControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_user_bots(): void
    {
        $user = User::factory()->create();
        Bot::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/bots');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'platform']],
                'meta' => ['timestamp'],
            ]);
    }

    public function test_store_creates_bot(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/bots', [
                'name' => 'New Bot',
                'platform' => 'line',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'New Bot');

        $this->assertDatabaseHas('bots', ['name' => 'New Bot']);
    }

    public function test_store_validates_required_fields(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/v1/bots', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'platform']);
    }

    public function test_unauthorized_without_auth(): void
    {
        $response = $this->getJson('/api/v1/bots');

        $response->assertUnauthorized();
    }
}
```

## E2E Test - Playwright Pattern

```typescript
import { test, expect } from '@playwright/test';

test.describe('Bot Management', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.fill('[name="email"]', 'test@example.com');
    await page.fill('[name="password"]', 'password');
    await page.click('button[type="submit"]');
    await page.waitForURL('/dashboard');
  });

  test('creates new bot', async ({ page }) => {
    await page.click('text=Create Bot');
    await page.fill('[name="name"]', 'E2E Test Bot');
    await page.selectOption('[name="platform"]', 'line');
    await page.click('button[type="submit"]');

    await expect(page.locator('text=E2E Test Bot')).toBeVisible();
  });
});
```

## Responsive Testing

```typescript
const breakpoints = [
  { width: 375, height: 667, name: 'mobile' },
  { width: 768, height: 1024, name: 'tablet' },
  { width: 1280, height: 800, name: 'desktop' },
];

for (const bp of breakpoints) {
  test(`renders correctly on ${bp.name}`, async ({ page }) => {
    await page.setViewportSize({ width: bp.width, height: bp.height });
    await page.goto('/dashboard');
    await expect(page).toHaveScreenshot(`dashboard-${bp.name}.png`);
  });
}
```

## Accessibility Testing

```typescript
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('has no accessibility violations', async ({ page }) => {
  await page.goto('/dashboard');
  const results = await new AxeBuilder({ page }).analyze();
  expect(results.violations).toEqual([]);
});
```

## Test Data Factories

```php
// Create factory
Bot::factory()->create(['name' => 'Specific Name']);

// Create with relationship
Bot::factory()
    ->has(Conversation::factory()->count(5))
    ->create();

// Create with state
Bot::factory()->active()->create();
```
