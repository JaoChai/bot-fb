---
name: testing
description: |
  Full-stack testing specialist for PHPUnit, Playwright, and UI testing.
  Triggers: 'test', 'unit test', 'feature test', 'E2E', 'coverage', 'Playwright'.
  Use when: writing tests, verifying features, testing UI, setting up automation.
allowed-tools:
  - Bash(php artisan test*)
  - Bash(npm run test*)
  - Bash(npx playwright*)
  - Read
  - Grep
context:
  - path: phpunit.xml
  - path: playwright.config.ts
  - path: tests/TestCase.php
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

| Tool | Commands | Use For |
|------|----------|---------|
| **chrome** | `computer`, `screenshot` | UI testing |
| **neon** | `run_sql` | DB state verification |
| **sentry** | `search_issues` | Errors after tests |

## Test Types & Targets

| Type | Location | Target | Command |
|------|----------|--------|---------|
| Unit | `tests/Unit/` | Services 80%+ | `--filter Unit` |
| Feature | `tests/Feature/` | Controllers 60%+ | `--filter Feature` |
| E2E | Playwright | Critical flows | `npm run test:e2e` |

## Testing Commands

```bash
# PHPUnit
php artisan test                 # All
php artisan test --filter Unit   # Unit only
php artisan test --coverage      # With coverage
php artisan test --parallel      # Parallel

# Playwright
npm run test:e2e                 # All E2E
npm run test:e2e -- --headed     # With browser
npm run test:e2e -- --debug      # Debug mode
```

## Key Patterns

### Factory Usage

```php
// Create with specific data
Bot::factory()->create(['name' => 'Test Bot']);

// Create with relationship
Bot::factory()
    ->has(Conversation::factory()->count(5))
    ->create();
```

### Auth in Tests

```php
$response = $this->actingAs($user)
    ->getJson('/api/v1/bots');
```

## Key Files

| File | Purpose |
|------|---------|
| `tests/Unit/` | Service tests |
| `tests/Feature/` | Controller tests |
| `tests/TestCase.php` | Base test class |
| `database/factories/` | Model factories |
| `phpunit.xml` | PHPUnit config |
| `playwright.config.ts` | Playwright config |

## Detailed Guides

- **Test Examples**: See [TEST_EXAMPLES.md](TEST_EXAMPLES.md)
- **Unit Testing**: See [UNIT_TESTING.md](UNIT_TESTING.md)
- **E2E Testing**: See [E2E_TESTING.md](E2E_TESTING.md)

## Known Pre-existing Test Failures

These tests have pre-existing failures and should not block new work:
- `SendDelayedBubbleJobTest` - pre-existing failure
- `ModelCapabilityServiceTest` - pre-existing failure
- `MultipleBubblesServiceTest` - pre-existing failure

## E2E Testing Note

CLAUDE.md recommends using `claude-in-chrome` over Playwright for browser testing in this project.

## Gotchas

| Problem | Solution |
|---------|----------|
| Tests fail randomly | Use `RefreshDatabase` trait |
| Slow tests | Use `LazilyRefreshDatabase` |
| Factory errors | Define relationship in factory |
| Auth not working | Use `Sanctum::actingAs($user)` |
| Playwright timeout | Increase `timeout` in config |
| E2E flaky | Add proper `waitFor` conditions |
| RAGServiceTest constructor mismatch | `RAGServiceTest.php` must mirror `RAGService` constructor exactly. `FlowCacheService` was added as 5th required parameter - tests must include it |
