---
id: e2e-003-waiting-strategies
title: Use Proper Waiting Strategies
impact: HIGH
impactDescription: "Flaky tests that pass/fail randomly"
category: e2e
tags: [e2e, playwright, async, waiting, flaky]
relatedRules: [e2e-001-critical-paths, e2e-004-selectors]
---

## Why This Matters

Web apps are async. Hardcoded waits cause slow tests. Missing waits cause flaky tests. Proper waiting strategies make tests fast and reliable.

## Bad Example

```typescript
test('sends message', async ({ page }) => {
  await page.goto('/chat');

  // Hardcoded wait - slow and unreliable
  await page.waitForTimeout(2000);

  await page.fill('[name="message"]', 'Hello');
  await page.click('button[type="submit"]');

  // Another hardcoded wait
  await page.waitForTimeout(5000);

  // Check for response - might not be loaded yet!
  await expect(page.locator('.response')).toBeVisible();
});

test('loads dashboard', async ({ page }) => {
  await page.goto('/dashboard');
  // No wait at all - race condition!
  const count = await page.locator('.bot-card').count();
  expect(count).toBeGreaterThan(0);
});
```

**Why it's problematic:**
- Hardcoded waits are slow
- No wait = race conditions
- Tests flaky in CI
- Hard to debug failures

## Good Example

```typescript
import { test, expect } from '@playwright/test';

test.describe('Proper Waiting', () => {
  test('waits for element visibility', async ({ page }) => {
    await page.goto('/dashboard');

    // Wait for specific element to appear
    await expect(page.locator('[data-testid="bot-list"]')).toBeVisible();

    // Now safe to interact
    const count = await page.locator('[data-testid="bot-card"]').count();
    expect(count).toBeGreaterThan(0);
  });

  test('waits for network request', async ({ page }) => {
    await page.goto('/dashboard');

    // Wait for API response
    const responsePromise = page.waitForResponse(
      response => response.url().includes('/api/v1/bots') && response.status() === 200
    );

    await page.click('text=Refresh');
    await responsePromise;

    // Now data is loaded
    await expect(page.locator('[data-testid="bot-list"]')).toBeVisible();
  });

  test('waits for navigation', async ({ page }) => {
    await page.goto('/login');
    await page.fill('[name="email"]', 'test@example.com');
    await page.fill('[name="password"]', 'password');

    // Wait for navigation after submit
    await Promise.all([
      page.waitForURL('/dashboard'),
      page.click('button[type="submit"]'),
    ]);

    // Now on dashboard
    await expect(page.locator('h1')).toContainText('Dashboard');
  });

  test('waits for loading state to clear', async ({ page }) => {
    await page.goto('/chat');

    await page.fill('[data-testid="message-input"]', 'Hello');
    await page.click('[data-testid="send-button"]');

    // Wait for loading indicator to appear then disappear
    await expect(page.locator('[data-testid="loading"]')).toBeVisible();
    await expect(page.locator('[data-testid="loading"]')).not.toBeVisible({
      timeout: 30000,  // AI response might take time
    });

    // Now response is ready
    await expect(page.locator('[data-testid="assistant-message"]')).toBeVisible();
  });

  test('waits for specific text', async ({ page }) => {
    await page.goto('/dashboard');

    // Wait for specific content
    await expect(page.locator('body')).toContainText('Welcome');

    // Or wait for text to appear
    await page.waitForSelector('text=My Bots');
  });

  test('waits with custom conditions', async ({ page }) => {
    await page.goto('/analytics');

    // Wait for chart to render (custom condition)
    await page.waitForFunction(() => {
      const chart = document.querySelector('[data-testid="chart"]');
      return chart && chart.querySelectorAll('.bar').length > 0;
    });

    // Now chart is rendered
    const bars = await page.locator('[data-testid="chart"] .bar').count();
    expect(bars).toBeGreaterThan(0);
  });

  test('uses retry with expect', async ({ page }) => {
    await page.goto('/notifications');

    // expect auto-retries until timeout
    await expect(async () => {
      const count = await page.locator('.notification').count();
      expect(count).toBeGreaterThan(0);
    }).toPass({ timeout: 10000 });
  });
});
```

**Why it's better:**
- No hardcoded waits
- Tests are fast
- Tests are reliable
- Clear conditions

## Test Coverage

| Waiting Type | Use When |
|--------------|----------|
| toBeVisible | Element appears |
| waitForURL | Navigation |
| waitForResponse | API call |
| waitForFunction | Custom condition |
| not.toBeVisible | Loading ends |

## Run Command

```bash
# Run with slow motion to debug waits
npx playwright test --slow-mo=500

# Run with trace to see timeline
npx playwright test --trace on
```

## Project-Specific Notes

**BotFacebook Waiting Patterns:**

```typescript
// Wait helpers for common scenarios
async function waitForAIResponse(page: Page, timeout = 30000) {
  // Wait for typing indicator
  await expect(page.locator('[data-testid="typing-indicator"]')).toBeVisible();

  // Wait for response to appear
  await expect(page.locator('[data-testid="assistant-message"]')).toBeVisible({
    timeout,
  });
}

async function waitForIndexing(page: Page) {
  // Wait for "Indexing..." to appear and then "Indexed"
  await expect(page.locator('text=Indexing')).toBeVisible();
  await expect(page.locator('text=Indexed')).toBeVisible({ timeout: 120000 });
}

async function waitForBotList(page: Page) {
  // Wait for API and render
  const response = page.waitForResponse(
    r => r.url().includes('/api/v1/bots') && r.ok()
  );
  await page.goto('/dashboard');
  await response;
  await expect(page.locator('[data-testid="bot-list"]')).toBeVisible();
}

// Usage in test
test('sends message and receives response', async ({ page }) => {
  await loginAsTestUser(page);
  await page.goto('/chat/test-bot');

  await page.fill('[data-testid="message-input"]', 'Hello');
  await page.click('[data-testid="send-button"]');

  await waitForAIResponse(page);

  // Verify response content
  const response = await page.locator('[data-testid="assistant-message"]').textContent();
  expect(response).not.toBeEmpty();
});
```
