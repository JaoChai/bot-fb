# E2E Testing Guide (Playwright)

## Setup

### Installation

```bash
# Install Playwright
npm install -D @playwright/test

# Install browsers
npx playwright install
```

### Configuration

```typescript
// playwright.config.ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: process.env.CI ? 1 : undefined,
  reporter: 'html',

  use: {
    baseURL: 'http://localhost:5173',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'Mobile Chrome',
      use: { ...devices['Pixel 5'] },
    },
  ],

  webServer: {
    command: 'npm run dev',
    url: 'http://localhost:5173',
    reuseExistingServer: !process.env.CI,
  },
});
```

## Test Patterns

### Basic Test

```typescript
import { test, expect } from '@playwright/test';

test('homepage loads correctly', async ({ page }) => {
  await page.goto('/');

  await expect(page).toHaveTitle(/BotFacebook/);
  await expect(page.getByRole('heading', { name: 'Welcome' })).toBeVisible();
});
```

### Authentication Flow

```typescript
import { test, expect } from '@playwright/test';

test.describe('Authentication', () => {
  test('login with valid credentials', async ({ page }) => {
    await page.goto('/login');

    await page.fill('[name="email"]', 'test@example.com');
    await page.fill('[name="password"]', 'password123');
    await page.click('button[type="submit"]');

    // Wait for redirect
    await page.waitForURL('/dashboard');

    // Verify logged in
    await expect(page.getByText('Dashboard')).toBeVisible();
  });

  test('shows error for invalid credentials', async ({ page }) => {
    await page.goto('/login');

    await page.fill('[name="email"]', 'wrong@example.com');
    await page.fill('[name="password"]', 'wrongpassword');
    await page.click('button[type="submit"]');

    await expect(page.getByText('Invalid credentials')).toBeVisible();
  });
});
```

### With Authentication Setup

```typescript
// tests/e2e/fixtures/auth.ts
import { test as base, Page } from '@playwright/test';

type AuthFixtures = {
  authenticatedPage: Page;
};

export const test = base.extend<AuthFixtures>({
  authenticatedPage: async ({ page }, use) => {
    // Login
    await page.goto('/login');
    await page.fill('[name="email"]', 'test@example.com');
    await page.fill('[name="password"]', 'password123');
    await page.click('button[type="submit"]');
    await page.waitForURL('/dashboard');

    // Use authenticated page
    await use(page);
  },
});

// Usage in tests
import { test } from './fixtures/auth';

test('can create bot when authenticated', async ({ authenticatedPage }) => {
  await authenticatedPage.goto('/bots/new');
  // ...
});
```

### CRUD Operations

```typescript
test.describe('Bot Management', () => {
  test.beforeEach(async ({ page }) => {
    // Login before each test
    await page.goto('/login');
    await page.fill('[name="email"]', 'test@example.com');
    await page.fill('[name="password"]', 'password123');
    await page.click('button[type="submit"]');
    await page.waitForURL('/dashboard');
  });

  test('creates new bot', async ({ page }) => {
    await page.click('text=Create Bot');

    await page.fill('[name="name"]', 'E2E Test Bot');
    await page.selectOption('[name="platform"]', 'line');
    await page.click('button[type="submit"]');

    // Verify created
    await expect(page.getByText('E2E Test Bot')).toBeVisible();
    await expect(page.getByText('Bot created successfully')).toBeVisible();
  });

  test('edits existing bot', async ({ page }) => {
    // Navigate to bot
    await page.click('text=E2E Test Bot');
    await page.click('text=Edit');

    // Update name
    await page.fill('[name="name"]', 'Updated Bot Name');
    await page.click('button[type="submit"]');

    await expect(page.getByText('Updated Bot Name')).toBeVisible();
  });

  test('deletes bot', async ({ page }) => {
    await page.click('text=Updated Bot Name');
    await page.click('text=Delete');

    // Confirm deletion
    await page.click('text=Confirm');

    await expect(page.getByText('Bot deleted')).toBeVisible();
    await expect(page.getByText('Updated Bot Name')).not.toBeVisible();
  });
});
```

## Page Object Model

### Page Object

```typescript
// tests/e2e/pages/LoginPage.ts
import { Page, Locator } from '@playwright/test';

export class LoginPage {
  readonly page: Page;
  readonly emailInput: Locator;
  readonly passwordInput: Locator;
  readonly submitButton: Locator;
  readonly errorMessage: Locator;

  constructor(page: Page) {
    this.page = page;
    this.emailInput = page.locator('[name="email"]');
    this.passwordInput = page.locator('[name="password"]');
    this.submitButton = page.locator('button[type="submit"]');
    this.errorMessage = page.locator('.error-message');
  }

  async goto() {
    await this.page.goto('/login');
  }

  async login(email: string, password: string) {
    await this.emailInput.fill(email);
    await this.passwordInput.fill(password);
    await this.submitButton.click();
  }
}
```

### Using Page Objects

```typescript
import { test, expect } from '@playwright/test';
import { LoginPage } from './pages/LoginPage';

test('login flow', async ({ page }) => {
  const loginPage = new LoginPage(page);

  await loginPage.goto();
  await loginPage.login('test@example.com', 'password123');

  await expect(page).toHaveURL('/dashboard');
});
```

## API Testing

```typescript
import { test, expect } from '@playwright/test';

test.describe('API Tests', () => {
  test('creates bot via API', async ({ request }) => {
    const response = await request.post('/api/v1/bots', {
      headers: {
        Authorization: `Bearer ${process.env.TEST_TOKEN}`,
      },
      data: {
        name: 'API Test Bot',
        platform: 'line',
      },
    });

    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.data.name).toBe('API Test Bot');
  });

  test('returns 401 without auth', async ({ request }) => {
    const response = await request.get('/api/v1/bots');
    expect(response.status()).toBe(401);
  });
});
```

## Visual Testing

```typescript
test('dashboard looks correct', async ({ page }) => {
  await page.goto('/dashboard');

  // Full page screenshot
  await expect(page).toHaveScreenshot('dashboard.png');

  // Element screenshot
  await expect(page.locator('.sidebar')).toHaveScreenshot('sidebar.png');
});
```

## Debugging

### Debug Mode

```bash
# Run with UI
npx playwright test --ui

# Debug specific test
npx playwright test --debug tests/e2e/login.spec.ts

# Headed mode
npx playwright test --headed

# Slow motion
npx playwright test --headed --slow-mo=1000
```

### Trace Viewer

```bash
# Enable traces
npx playwright test --trace on

# View trace
npx playwright show-trace trace.zip
```

### Console Logs

```typescript
test('debug console', async ({ page }) => {
  page.on('console', (msg) => console.log(msg.text()));

  await page.goto('/');
});
```

## Running Tests

```bash
# All tests
npx playwright test

# Specific file
npx playwright test tests/e2e/login.spec.ts

# Specific test
npx playwright test -g "login with valid"

# Specific browser
npx playwright test --project=chromium

# With report
npx playwright test --reporter=html
npx playwright show-report
```

## Best Practices

### DO
- Use descriptive test names
- Use Page Object Model for complex flows
- Test critical user journeys
- Clean up test data
- Use appropriate timeouts

### DON'T
- Test implementation details
- Create dependencies between tests
- Hardcode credentials in tests
- Skip error scenarios
- Rely on test order

## Bot-FB Critical User Journeys

### 1. Bot Creation & Configuration

```typescript
test('creates bot and configures flow', async ({ page }) => {
  // Login
  await page.goto('/login');
  await page.fill('[name="email"]', process.env.TEST_EMAIL!);
  await page.fill('[name="password"]', process.env.TEST_PASSWORD!);
  await page.click('button[type="submit"]');
  await page.waitForURL('/dashboard');

  // Create bot
  await page.click('text=Create Bot');
  await page.fill('[name="name"]', 'E2E Test Bot');
  await page.selectOption('[name="platform"]', 'line');
  await page.click('button[type="submit"]');
  await expect(page.getByText('E2E Test Bot')).toBeVisible();

  // Navigate to Flow editor
  await page.click('text=Flows');
  await page.click('text=Base Flow');

  // Edit system prompt
  const promptEditor = page.locator('textarea[name="system_prompt"]');
  await promptEditor.fill('Test system prompt');

  // Set temperature
  await page.fill('input[name="temperature"]', '0.5');

  // Save
  await page.click('button:has-text("Save")');
  await expect(page.getByText('Flow updated')).toBeVisible();
});
```

### 2. Chat Emulator Test

```typescript
test('sends message and receives AI response', async ({ page }) => {
  // Navigate to flow editor with emulator
  await page.goto('/bots/26/flows/24');

  // Open chat emulator
  await page.click('button:has-text("Test")');

  // Send message
  const chatInput = page.locator('input[placeholder*="message"]');
  await chatInput.fill('สวัสดีครับ');
  await chatInput.press('Enter');

  // Wait for AI response (may take 5-15 seconds)
  await expect(page.locator('.assistant-message').first())
    .toBeVisible({ timeout: 30000 });
});
```

### 3. Knowledge Base Attachment

```typescript
test('attaches KB to flow', async ({ page }) => {
  await page.goto('/bots/26/flows/24');

  // Open KB section
  await page.click('text=Knowledge Bases');

  // Select KB
  await page.click('text=Line Adsvance');

  // Verify attached
  await expect(page.locator('.kb-badge')).toBeVisible();

  // Save
  await page.click('button:has-text("Save")');
});
```

## Test Coverage Strategy

### Priority Matrix

| Area | Priority | Tests Needed |
|------|----------|-------------|
| Auth (login/register/logout) | P0 | Feature + E2E |
| Bot CRUD | P0 | Feature |
| Flow CRUD + save | P0 | Feature + E2E |
| Chat emulator | P1 | E2E |
| KB management | P1 | Feature |
| Payment Flex detection | P1 | Unit (exists) |
| Agent loop | P1 | Unit (exists) |
| Order tracking | P2 | Feature |
| Dashboard analytics | P2 | Feature |
| Real-time (WebSocket) | P3 | E2E |

### Pre-existing Test Failures (Known)

| Test | Issue | Status |
|------|-------|--------|
| SendDelayedBubbleJobTest | Constructor mismatch | Pre-existing |
| MultipleBubblesServiceTest | Missing PaymentFlexService param | Pre-existing |
| ModelCapabilityServiceTest | Config mismatch | Pre-existing |
| InputValidationTest | Null name assertion | Pre-existing |
