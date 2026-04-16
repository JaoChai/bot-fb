# Testing Patterns — bot-fb (Laravel 12 + React 19)

สรุปเนื้อหาการทดสอบในโปรเจคนี้: structure, tools, mocking patterns, CI integration.

---

## 1. Test Structure & Conventions

### Backend (PHPUnit)

```
backend/tests/
├── Unit/              # ← Service, Job, Helper, Middleware tests
│   ├── Services/      # OpenRouterServiceTest, PaymentFlexServiceTest, etc.
│   ├── Jobs/          # SendDelayedBubbleJobTest
│   ├── Middleware/    # SecurityHeadersTest
│   ├── Events/        # MessageSentTest, ConversationUpdatedTest
│   ├── Commands/      # RefreshLineProfilePicturesTest
│   ├── SecondAI/      # PromptInjectionDetectorTest
│   ├── Helpers/       # ConfigHelperTest
│   └── Support/       # SanitizerTest
│
└── Feature/           # ← Endpoint, API, Webhook tests
    ├── BotApiTest.php                    # GET/POST /api/bots/*
    ├── LINEWebhookTest.php               # POST /webhook/line
    ├── HealthCheckTest.php               # GET /up
    ├── FlowApiTest.php
    ├── LeadRecoveryTest.php
    ├── ApiContractTest.php               # Response format validation
    ├── Broadcasting/ChannelAuthorizationTest.php
    ├── Security/RateLimitingTest.php
    ├── Security/SecurityHeadersTest.php
    ├── Security/InputValidationTest.php
    └── SecondAI/                         # AI safety tests
```

**Naming convention:**
- `test_<feature>`: kebab-case action (e.g., `test_can_list_user_bots`)
- Doc attribute `/** @test */` optional but sometimes used
- Method signature: `public function test_xxxx(): void`

**phpunit.xml config:**
```xml
<testsuites>
  <testsuite name="Unit">
    <directory>tests/Unit</directory>
  </testsuite>
  <testsuite name="Feature">
    <directory>tests/Feature</directory>
  </testsuite>
</testsuites>

<source>
  <include>
    <directory>app</directory>  <!-- Coverage only from app/ -->
  </include>
</source>

<php>
  <env name="DB_CONNECTION" value="sqlite"/>
  <env name="DB_DATABASE" value=":memory:"/>  <!-- ← In-memory SQLite for fast tests -->
  <env name="CACHE_STORE" value="array"/>
  <env name="QUEUE_CONNECTION" value="sync"/>
  <env name="APP_ENV" value="testing"/>
</php>
```

### Frontend (Vitest)

```
frontend/src/
├── test/
│   ├── setup.ts              # ← MSW, localStorage, env mocks
│   └── mocks/
│       ├── server.ts         # MSW setupServer instance
│       └── handlers.ts       # HTTP handlers (auth, health, etc.)
│
└── components/
    ├── ui/button.test.tsx    # ← Colocated with component (not separate dir)
    ├── ui/button.tsx
    └── ... (test next to src)
```

**vitest.config.ts:**
```typescript
export default defineConfig({
  plugins: [react()],
  test: {
    globals: true,
    environment: "jsdom",
    setupFiles: ["./src/test/setup.ts"],
    include: ["src/**/*.test.{ts,tsx}"],
    coverage: {
      provider: "v8",
      exclude: ["src/components/ui/**", "src/test/**"],
    },
  },
})
```

---

## 2. Running Tests

### Backend

```bash
cd backend

# All tests (Unit + Feature)
php artisan test

# Unit tests only
php artisan test --filter=Unit

# Specific test file
php artisan test --filter=OpenRouterServiceTest

# Single test method
php artisan test --filter=test_can_list_user_bots

# With coverage
php artisan test --coverage

# Specific test class with namespace
php artisan test tests/Unit/Services/OpenRouterServiceTest.php

# CI command (from .github/workflows/ci.yml)
php artisan test
vendor/bin/pint --test  # Code style check
```

### Frontend

```bash
cd frontend

# All tests with coverage
npm run test

# Watch mode for development
npm run test:watch  # (if available, else vitest --watch)

# Single file
npm run test -- src/components/ui/button.test.tsx

# Pattern match
npm run test -- --grep "Button"

# CI commands (from .github/workflows/ci.yml)
npm run lint              # ESLint check
npm run test              # Vitest run (default: ci mode)
npx tsc --noEmit          # TypeScript type checking
```

---

## 3. Key Test Utilities & Helpers

### Backend

#### Base TestCase
```php
// backend/tests/TestCase.php
abstract class TestCase extends BaseTestCase
{
    // Extends Laravel's TestCase — inherits all assertion methods
    // Used by all backend tests
}
```

**Common traits:**
- `RefreshDatabase` — Migrate + reset DB before each test (uses :memory: SQLite)
- `WithoutMiddleware` — Skip middleware (rare)
- `WithoutEvents` — Don't dispatch events

#### Factory Usage
```php
// Create with specific attributes
$user = User::factory()->owner()->create();
$bot = Bot::factory()->count(3)->create(['user_id' => $this->user->id]);

// Create state (optional, defined in factory)
$user = User::factory()->owner()->create();  // State: user.role = 'owner'

// Create without saving
$user = User::factory()->make();
```

#### Test HTTP Assertions
```php
// After making a request
$response->assertOk()                              // 200
$response->assertCreated()                         // 201
$response->assertForbidden()                       // 403
$response->assertUnprocessable()                   // 422
$response->assertJsonPath('data.bot.name', 'Test Bot')
$response->assertJsonValidationErrors(['name', 'channel_type'])
$response->assertJsonCount(3, 'data')
$response->assertJsonStructure(['data' => ['id', 'name']])
$response->assertSoftDeleted('bots', ['id' => $bot->id])

// Acting as user
$response = $this->actingAs($user)->getJson('/api/bots');
```

#### Mockery Usage
```php
use Mockery;

$lineService = Mockery::mock(LINEService::class);
$lineService
    ->shouldReceive('push')
    ->once()
    ->with($bot, 'U_user_123', ['Hello'], 'retry-key')
    ->andReturn(true);

// Inject into job/service
$job->handle($lineService);

// Cleanup (important!)
protected function tearDown(): void {
    Mockery::close();
    parent::tearDown();
}
```

### Frontend

#### Testing Library Queries
```typescript
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"

// Queries (in priority order)
screen.getByRole("button", { name: "Click me" })
screen.getByLabelText("Username")
screen.getByText("Submit")
screen.getByPlaceholderText("Search...")
screen.getByTestId("modal-close")

// Async queries
await screen.findByText("Loaded") // wait + error if not found

// Assertions
expect(element).toBeInTheDocument()
expect(element).toBeDisabled()
expect(element).toHaveAttribute("href", "/test")
expect(element).toHaveClass("active")
expect(element).toHaveTextContent("text")
```

#### User Event Simulation
```typescript
const user = userEvent.setup()

await user.click(screen.getByRole("button"))
await user.type(screen.getByRole("textbox"), "hello")
await user.hover(element)
await user.keyboard("{Enter}")
```

#### Mock Functions
```typescript
import { vi } from "vitest"

const onClick = vi.fn()
const service = vi.mock("./api")

render(<Button onClick={onClick}>Click</Button>)
expect(onClick).toHaveBeenCalledOnce()
expect(onClick).toHaveBeenCalledWith(expectedArg)
```

#### Custom Render with Providers
```typescript
// If using Zustand, React Query, Redux, etc.
function renderWithProviders(component: React.ReactElement) {
  return render(<Providers>{component}</Providers>)
}

// Use in tests instead of bare render()
renderWithProviders(<MyComponent />)
```

---

## 4. Mocking Patterns

### Backend

#### HTTP::fake() — LLM Calls
```php
// Mock OpenRouter API
Http::fake([
    'openrouter.ai/api/v1/chat/completions' => Http::response([
        'id' => 'gen-123',
        'model' => 'anthropic/claude-3.5-sonnet',
        'choices' => [
            [
                'message' => ['content' => 'Hello! How can I help you?'],
                'finish_reason' => 'stop',
            ],
        ],
        'usage' => [
            'prompt_tokens' => 10,
            'completion_tokens' => 8,
            'total_tokens' => 18,
        ],
    ], 200),
]);

$result = $this->service->chat([
    ['role' => 'user', 'content' => 'Hello'],
]);

$this->assertEquals('Hello! How can I help you?', $result['content']);

// Verify request was made correctly
Http::assertSent(function ($request) {
    $body = $request->data();
    return isset($body['models']) && 
           $body['models'] === ['anthropic/claude-3.5-sonnet', 'openai/gpt-4o-mini'];
});
```

#### Queue::fake() — Job Scheduling
```php
use Illuminate\Support\Facades\Queue;

Queue::fake();

// Trigger job dispatch
$this->service->sendDelayedMessage($bot, 'user_id', 'message');

// Verify job was pushed
Queue::assertPushed(SendDelayedBubbleJob::class);
Queue::assertPushed(SendDelayedBubbleJob::class, function ($job) {
    return $job->bot->id === $bot->id;
});
```

#### Storage::fake() — File Operations
```php
use Illuminate\Support\Facades\Storage;

Storage::fake('local');

// Upload/process files
$service->processUploadedImage($file);

// Assertions
Storage::disk('local')->assertExists('path/to/file.jpg');
```

#### Time::fake() / Carbon Testing
```php
use Illuminate\Support\Facades\Time;

Time::freeze('2026-04-16 10:00:00');

// Code runs as if it's that time
$this->travel(30)->minutes(); // Travel 30 min forward

Time::resume(); // Unfreeze
```

#### Encryption Handling (Gotcha!)
```php
// Some models use EncryptedWithFallback cast
// Can't factory with encrypted values easily
// Workaround: setRawAttributes to bypass encryption

protected function createBotInstance(array $attributes = []): Bot {
    $bot = new Bot;
    $bot->setRawAttributes(array_merge([
        'id' => 1,
        'channel_access_token' => 'test_token',
        'channel_secret' => 'test_secret',
    ], $attributes));
    return $bot;
}
```

### Frontend

#### MSW (Mock Service Worker) — HTTP Handlers
```typescript
// frontend/src/test/mocks/handlers.ts
import { http, HttpResponse } from "msw"

export const handlers = [
  http.post(`${API_URL}/auth/login`, async ({ request }) => {
    const body = (await request.json()) as { email: string; password: string }
    
    if (body.email === "test@example.com" && body.password === "password") {
      return HttpResponse.json({
        data: {
          user: { id: 1, name: "Test User", email: "test@example.com" },
          token: "test-token-123",
        },
      })
    }
    
    return HttpResponse.json({ message: "Invalid credentials" }, { status: 401 })
  }),

  http.get(`${API_URL}/auth/user`, ({ request }) => {
    const authHeader = request.headers.get("Authorization")
    
    if (authHeader === "Bearer test-token-123") {
      return HttpResponse.json({
        data: { id: 1, name: "Test User", email: "test@example.com" },
      })
    }
    
    return HttpResponse.json({ message: "Unauthenticated" }, { status: 401 })
  }),
]

// frontend/src/test/setup.ts
beforeAll(() => server.listen({ onUnhandledRequest: "bypass" }))
afterEach(() => {
  cleanup()
  server.resetHandlers()
})
afterAll(() => server.close())
```

#### localStorage Mock
```typescript
// In setup.ts — automatically mocked
const localStorageMock = {
  getItem: (key) => store[key] ?? null,
  setItem: (key, value) => { store[key] = value },
  removeItem: (key) => { delete store[key] },
  clear: () => { store = {} },
}

Object.defineProperty(window, "localStorage", { value: localStorageMock })
```

#### vi.mock() — Module Mocking
```typescript
vi.mock("./api", () => ({
  fetchUser: vi.fn().mockResolvedValue({ id: 1, name: "Test" }),
}))

// Partial mock (keep some real functions)
vi.mock("./utils", async () => {
  const actual = await vi.importActual<typeof import("./utils")>("./utils")
  return { ...actual, sum: vi.fn(() => 99) }
})
```

---

## 5. Example Tests

### Backend Unit Test (Service)

```php
// backend/tests/Unit/Services/PaymentFlexServiceTest.php
namespace Tests\Unit\Services;

use App\Services\PaymentFlexService;
use Tests\TestCase;

class PaymentFlexServiceTest extends TestCase
{
    private PaymentFlexService $service;

    protected function setUp(): void {
        parent::setUp();
        $this->service = new PaymentFlexService;
    }

    public function test_detects_payment_message(): void {
        $text = <<<'TEXT'
        สรุปรายการสั่งซื้อ
        1. Nolimit Level Up+ BM (800 x 2) = 1,600 บาท
        รวมยอดโอน: 1,600 บาท
        223-3-24880-3
        TEXT;

        $this->assertTrue($this->service->isPaymentMessage($text));
    }

    public function test_does_not_detect_normal_message(): void {
        // Has bank account but no total keyword
        $this->assertFalse($this->service->isPaymentMessage('เลขบัญชี 223-3-24880-3'));
        // Has total but no bank account
        $this->assertFalse($this->service->isPaymentMessage('รวมยอดโอน: 1,600 บาท'));
    }

    public function test_parses_multi_item_order(): void {
        $text = <<<'TEXT'
        1. Nolimit Level Up+ BM (800 x 2) = 1,600 บาท
        2. Nolimit Level Up+ Personal 900 บาท
        รวมยอดโอน: 2,500 บาท
        223-3-24880-3
        TEXT;

        $data = $this->service->parsePaymentData($text);

        $this->assertNotNull($data);
        $this->assertCount(2, $data['items']);
        $this->assertEquals('2,500', $data['total']);
    }
}
```

### Backend Feature Test (API Endpoint)

```php
// backend/tests/Feature/BotApiTest.php
namespace Tests\Feature;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BotApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void {
        parent::setUp();
        $this->user = User::factory()->owner()->create();
    }

    public function test_can_list_user_bots(): void {
        Bot::factory()->count(3)->create(['user_id' => $this->user->id]);
        Bot::factory()->create(); // Another user's bot

        $response = $this->actingAs($this->user)->getJson('/api/bots');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_can_create_a_bot(): void {
        $response = $this->actingAs($this->user)->postJson('/api/bots', [
            'name' => 'Test Bot',
            'description' => 'A test bot',
            'channel_type' => 'line',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.bot.name', 'Test Bot')
            ->assertDatabaseHas('bots', [
                'name' => 'Test Bot',
                'user_id' => $this->user->id,
            ]);
    }

    public function test_cannot_update_other_user_bot(): void {
        $bot = Bot::factory()->create(); // Different user's bot

        $response = $this->actingAs($this->user)->putJson("/api/bots/{$bot->id}", [
            'name' => 'Hacked',
        ]);

        $response->assertForbidden();
    }
}
```

### Backend Job Test

```php
// backend/tests/Unit/Jobs/SendDelayedBubbleJobTest.php
namespace Tests\Unit\Jobs;

use App\Jobs\SendDelayedBubbleJob;
use App\Models\Bot;
use App\Services\LINEService;
use Mockery;
use Tests\TestCase;

class SendDelayedBubbleJobTest extends TestCase
{
    protected function tearDown(): void {
        Mockery::close();
        parent::tearDown();
    }

    protected function createBotInstance(array $attributes = []): Bot {
        $bot = new Bot;
        $bot->setRawAttributes(array_merge([
            'id' => 1,
            'user_id' => 1,
            'name' => 'Test Bot',
            'channel_type' => 'line',
            'channel_access_token' => 'test_token',
            'channel_secret' => 'test_secret',
        ], $attributes));
        return $bot;
    }

    public function test_job_sends_push_message(): void {
        $bot = $this->createBotInstance();

        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('generateRetryKey')
            ->once()
            ->andReturn('retry-key');
        $lineService->shouldReceive('push')
            ->once()
            ->with($bot, 'U_user_123', ['Hello bubble 2'], 'retry-key')
            ->andReturn(true);

        $job = new SendDelayedBubbleJob(
            $bot,
            'U_user_123',
            'Hello bubble 2',
            2,
            3
        );

        $job->handle($lineService);
        $this->assertTrue(true); // Mockery verifies expectations
    }

    public function test_job_throws_exception_on_line_api_failure(): void {
        $bot = $this->createBotInstance();

        $lineService = Mockery::mock(LINEService::class);
        $lineService->shouldReceive('generateRetryKey')
            ->once()
            ->andReturn('retry-key');
        $lineService->shouldReceive('push')
            ->once()
            ->andThrow(new \Exception('LINE API error'));

        $job = new SendDelayedBubbleJob($bot, 'U_user_123', 'Hello', 2, 3);

        $this->expectException(\Exception::class);
        $job->handle($lineService);
    }
}
```

### Frontend Component Test

```typescript
// frontend/src/components/ui/button.test.tsx
import { render, screen } from "@testing-library/react"
import userEvent from "@testing-library/user-event"
import { describe, it, expect, vi } from "vitest"
import { Button } from "./button"

describe("Button", () => {
  it("renders with text", () => {
    render(<Button>Click me</Button>)
    expect(screen.getByRole("button", { name: "Click me" })).toBeInTheDocument()
  })

  it("handles click events", async () => {
    const user = userEvent.setup()
    const onClick = vi.fn()

    render(<Button onClick={onClick}>Click</Button>)
    await user.click(screen.getByRole("button"))

    expect(onClick).toHaveBeenCalledOnce()
  })

  it("renders disabled state", () => {
    render(<Button disabled>Disabled</Button>)
    expect(screen.getByRole("button")).toBeDisabled()
  })

  it("applies variant classes", () => {
    render(<Button variant="destructive">Delete</Button>)
    const button = screen.getByRole("button")
    expect(button).toHaveAttribute("data-variant", "destructive")
  })

  it("renders as child component with asChild", () => {
    render(
      <Button asChild>
        <a href="/test">Link</a>
      </Button>
    )
    const link = screen.getByRole("link", { name: "Link" })
    expect(link).toHaveAttribute("href", "/test")
  })
})
```

---

## 6. Database Testing

### In-Memory SQLite Testing
```php
// phpunit.xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>  // ← Fresh database per test
```

**Traits for DB tests:**
```php
use Illuminate\Foundation\Testing\RefreshDatabase;  // Migrate before each test
use Illuminate\Foundation\Testing\DatabaseMigrations;  // Alternative: no refresh
```

### RefreshDatabase vs DatabaseMigrations

| Feature | RefreshDatabase | DatabaseMigrations |
|---------|-----------------|-------------------|
| Runs migrations | ✓ Each test | ✓ Once before suite |
| Rollback | ✓ After each test | ✗ No rollback |
| Speed | Slower | Faster |
| Isolation | Complete | Partial (data persists) |
| Use case | Most tests | When fixtures needed |

### pgvector Testing (Neon)
```php
// For embedding/similarity tests, use in-memory SQLite with mock vector data
// OR use separate test DB branch on Neon (not typical)

// In Unit tests: mock vector results
Http::fake([
    'embeddings-api/...' => Http::response([
        'data' => [['embedding' => [0.1, 0.2, ...]]],
    ]),
]);
```

---

## 7. Coverage Approach

**What's covered:**
- ✅ Core services (OpenRouter, RAG, LeadRecovery, PaymentFlex, etc.)
- ✅ API endpoints (bots, webhooks, flows)
- ✅ Database models and relations
- ✅ Jobs and async operations
- ✅ Security (auth, validation, CSRF)
- ✅ Frontend components (Button, UI primitives)

**What's typically not covered:**
- ❌ Vendor code (`vendor/`, `node_modules/`)
- ❌ Generated files (migrations, stubs)
- ❌ Configuration files
- ❌ Most view/blade templates (unless critical logic)

**Critical paths that must have tests:**
1. **Payment flow** — PaymentFlexService detection + parsing
2. **AI response generation** — OpenRouterService + RAG
3. **Webhook handlers** — LINE, Telegram, Facebook
4. **Lead recovery** — HITL escalation, message routing
5. **Auth** — Login, user roles, API token validation
6. **Stock guard** — Inventory checks, blocking out-of-stock

---

## 8. CI Integration

### GitHub Actions (.github/workflows/ci.yml)

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:
    branches: [main]

jobs:
  backend-tests:
    name: Backend Tests
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          extensions: mbstring, pdo, pdo_sqlite, sqlite3
      - name: Install dependencies
        working-directory: backend
        run: composer install --no-interaction --prefer-dist
      - name: Run tests
        working-directory: backend
        run: php artisan test
      - name: Check code style
        working-directory: backend
        run: vendor/bin/pint --test

  frontend-checks:
    name: Frontend Checks
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: "22"
          cache: npm
          cache-dependency-path: frontend/package-lock.json
      - name: Install dependencies
        working-directory: frontend
        run: npm ci
      - name: Run linter
        working-directory: frontend
        run: npm run lint
      - name: Run tests
        working-directory: frontend
        run: npm run test
      - name: TypeScript check
        working-directory: frontend
        run: npx tsc --noEmit

  post-deploy-smoke:
    name: Post-Deploy Smoke Test
    needs: [backend-tests, frontend-checks]
    if: github.event_name == 'push'  # Only on push to main
    runs-on: ubuntu-latest
    steps:
      - name: Wait for Railway deployment
        run: sleep 180
      - name: Check Backend Health
        run: |
          STATUS=$(curl -s -o /dev/null -w "%{http_code}" https://api.botjao.com/up)
          [ "$STATUS" = "200" ] || exit 1
      - name: Check Frontend Health
        run: |
          STATUS=$(curl -s -o /dev/null -w "%{http_code}" https://www.botjao.com/health)
          [ "$STATUS" = "200" ] || exit 1
```

**What runs on PR/push:**
1. Backend tests: `php artisan test`
2. Backend style: `vendor/bin/pint --test`
3. Frontend linter: `npm run lint`
4. Frontend tests: `npm run test`
5. TypeScript: `npx tsc --noEmit`
6. (After merge) Smoke tests on deployed URLs

---

## Key Patterns Summary

| Pattern | Backend | Frontend |
|---------|---------|----------|
| **Base class** | `TestCase extends BaseTestCase` | React Testing Library |
| **Mocking HTTP** | `Http::fake()` | MSW (Mock Service Worker) |
| **Mocking jobs** | `Queue::fake()` | N/A |
| **Database** | SQLite :memory: | N/A |
| **User auth** | `$this->actingAs()` | localStorage + MSW handlers |
| **Assertions** | PHPUnit: `->assert*()` | Jest: `expect().to*()` |
| **Setup** | `setUp()` method | `setup.ts` file |
| **Cleanup** | `tearDown()` method | `afterEach()` hook |

---

## ไม่พบในโปรเจค

- ❌ End-to-end (E2E) tests (Playwright, Cypress) — ไม่มี
- ❌ Load testing — ไม่มี
- ❌ Visual regression testing — ไม่มี
- ❌ Contract testing (Pact) — ไม่มี
- ❌ Integration tests (cross-service) — บางส่วนเท่านั้น

---

**Last updated:** 2026-04-16  
**Stack:** Laravel 12 (PHP 8.4) + React 19 + Vitest  
**CI:** GitHub Actions → Railway auto-deploy
