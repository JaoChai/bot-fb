---
name: testing
description: Full-stack testing specialist for PHPUnit, Playwright, and UI testing. Handles unit tests, feature tests, E2E flows, responsive testing, accessibility. Use when writing tests, verifying features work correctly, testing UI across devices, or setting up test automation.
---

# Testing

Full-stack testing for BotFacebook.

## Quick Start

```bash
# Run all tests
php artisan test

# Run specific test
php artisan test --filter BotControllerTest

# Run with coverage
php artisan test --coverage
```

## MCP Tools Available

- **chrome**: `computer`, `screenshot`, `read_page` - UI testing
- **neon**: `run_sql` - Database state verification
- **sentry**: `search_issues` - Check for errors after tests

## Test Types & Coverage Targets

| Type | Location | Target | Command |
|------|----------|--------|---------|
| Unit | `tests/Unit/` | Services 80%+ | `--filter Unit` |
| Feature | `tests/Feature/` | Controllers 60%+ | `--filter Feature` |
| E2E | Playwright | Critical flows | `npm run test:e2e` |
| UI | Chrome | Visual regression | Manual |

## Unit Testing

### Service Test Pattern

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

## Feature Testing

### Controller Test Pattern

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

## E2E Testing (Playwright)

### Test Pattern

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

### Run Playwright

```bash
# Install
npx playwright install

# Run tests
npm run test:e2e

# Run with UI
npm run test:e2e -- --ui

# Run specific test
npm run test:e2e -- --grep "creates new bot"
```

## UI Testing

### Responsive Testing

```typescript
// Test at different breakpoints
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

### Accessibility Testing

```typescript
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test('has no accessibility violations', async ({ page }) => {
  await page.goto('/dashboard');
  const results = await new AxeBuilder({ page }).analyze();
  expect(results.violations).toEqual([]);
});
```

## Detailed Guides

- **Unit Testing**: See [UNIT_TESTING.md](UNIT_TESTING.md)
- **E2E Testing**: See [E2E_TESTING.md](E2E_TESTING.md)
- **UI Testing**: See [UI_TESTING.md](UI_TESTING.md)

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

## Testing Commands

```bash
# PHPUnit
php artisan test                        # All
php artisan test --filter Unit          # Unit only
php artisan test --filter Feature       # Feature only
php artisan test --coverage             # With coverage
php artisan test --parallel             # Parallel execution

# Playwright
npm run test:e2e                        # All E2E
npm run test:e2e -- --headed            # With browser
npm run test:e2e -- --debug             # Debug mode
```

## Key Files

| File | Purpose |
|------|---------|
| `tests/Unit/` | Unit tests for services |
| `tests/Feature/` | Controller/API tests |
| `tests/TestCase.php` | Base test class |
| `database/factories/` | Model factories |
| `playwright.config.ts` | Playwright configuration |
| `phpunit.xml` | PHPUnit configuration |

## Gotchas

| Problem | Cause | Solution |
|---------|-------|----------|
| Tests fail randomly | Database state | Use `RefreshDatabase` trait |
| Slow tests | Too many DB operations | Use `LazilyRefreshDatabase` |
| Factory errors | Missing relationship | Define relationship in factory |
| Auth not working | Missing Sanctum setup | Use `Sanctum::actingAs($user)` |
| Playwright timeout | Slow page load | Increase `timeout` in config |
| Screenshot diff | CSS animation | Use `toHaveScreenshot({ animations: 'disabled' })` |
| E2E flaky | Race condition | Add proper `waitFor` conditions |

## Utility Scripts

- `scripts/run_unit.sh` - Run unit tests with coverage
- `scripts/run_e2e.sh` - Run E2E tests
