---
id: e2e-001-critical-paths
title: Test Critical User Paths
impact: CRITICAL
impactDescription: "Core user flows broken without detection"
category: e2e
tags: [e2e, playwright, critical-path, user-flow]
relatedRules: [e2e-002-auth-flow, e2e-003-waiting-strategies]
---

## Why This Matters

E2E tests verify that critical user journeys work end-to-end. If login, signup, or core features break, E2E tests catch it before users do.

## Bad Example

```typescript
// Only tests isolated elements
test('shows login button', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('text=Login')).toBeVisible();
});

test('shows dashboard title', async ({ page }) => {
  await page.goto('/dashboard');
  await expect(page.locator('h1')).toContainText('Dashboard');
});

// No actual user flow testing!
```

**Why it's problematic:**
- Elements visible doesn't mean they work
- No interaction testing
- No flow verification
- Misses integration bugs

## Good Example

```typescript
import { test, expect } from '@playwright/test';

test.describe('Critical User Paths', () => {
  test('complete signup to first bot creation flow', async ({ page }) => {
    // Step 1: Signup
    await page.goto('/signup');
    await page.fill('[name="name"]', 'Test User');
    await page.fill('[name="email"]', `test-${Date.now()}@example.com`);
    await page.fill('[name="password"]', 'SecurePass123!');
    await page.fill('[name="password_confirmation"]', 'SecurePass123!');
    await page.click('button[type="submit"]');

    // Step 2: Redirected to dashboard
    await expect(page).toHaveURL('/dashboard');
    await expect(page.locator('text=Welcome')).toBeVisible();

    // Step 3: Create first bot
    await page.click('text=Create Bot');
    await page.fill('[name="name"]', 'My First Bot');
    await page.selectOption('[name="platform"]', 'line');
    await page.click('button[type="submit"]');

    // Step 4: Verify bot created
    await expect(page.locator('text=My First Bot')).toBeVisible();
    await expect(page.locator('[data-testid="bot-card"]')).toHaveCount(1);
  });

  test('login and view existing data', async ({ page }) => {
    // Assuming test user exists
    await page.goto('/login');
    await page.fill('[name="email"]', 'existing@example.com');
    await page.fill('[name="password"]', 'password123');
    await page.click('button[type="submit"]');

    await expect(page).toHaveURL('/dashboard');

    // Verify can access existing bots
    await page.click('text=My Bots');
    await expect(page.locator('[data-testid="bot-list"]')).toBeVisible();
  });

  test('complete conversation flow', async ({ page }) => {
    // Login first
    await loginAsTestUser(page);

    // Navigate to bot
    await page.click('text=Test Bot');
    await page.click('text=Conversations');

    // Start new conversation
    await page.click('text=Test Chat');

    // Send message
    await page.fill('[data-testid="message-input"]', 'Hello bot');
    await page.click('[data-testid="send-button"]');

    // Verify message sent
    await expect(page.locator('text=Hello bot')).toBeVisible();

    // Wait for AI response
    await expect(page.locator('[data-testid="assistant-message"]')).toBeVisible({
      timeout: 30000,  // AI might take time
    });
  });
});

// Helper function
async function loginAsTestUser(page: Page) {
  await page.goto('/login');
  await page.fill('[name="email"]', 'test@example.com');
  await page.fill('[name="password"]', 'password');
  await page.click('button[type="submit"]');
  await expect(page).toHaveURL('/dashboard');
}
```

**Why it's better:**
- Tests complete user journeys
- Verifies each step
- Tests real interactions
- Catches integration bugs

## Test Coverage

| Critical Path | Priority |
|---------------|----------|
| Signup → First use | Must have |
| Login → Dashboard | Must have |
| Create resource flow | Must have |
| Update resource flow | Should have |
| Error recovery | Should have |

## Run Command

```bash
# Run critical path tests
npx playwright test --grep "critical\|flow"

# Run with traces for debugging
npx playwright test --trace on
```

## Project-Specific Notes

**BotFacebook Critical Paths:**

```typescript
// tests/e2e/critical-paths.spec.ts

test.describe('BotFacebook Critical Paths', () => {
  test('new user onboarding flow', async ({ page }) => {
    // Signup
    await page.goto('/signup');
    await page.fill('[name="name"]', 'New User');
    await page.fill('[name="email"]', `user-${Date.now()}@test.com`);
    await page.fill('[name="password"]', 'TestPass123!');
    await page.click('button:text("Create Account")');

    // Onboarding
    await expect(page).toHaveURL('/onboarding');
    await page.click('text=LINE');  // Select platform
    await page.click('text=Continue');

    // Create first bot
    await page.fill('[name="botName"]', 'My LINE Bot');
    await page.click('text=Create Bot');

    // Dashboard with new bot
    await expect(page).toHaveURL('/dashboard');
    await expect(page.locator('text=My LINE Bot')).toBeVisible();
  });

  test('knowledge base upload and chat flow', async ({ page }) => {
    await loginAsTestUser(page);

    // Navigate to knowledge
    await page.click('text=Test Bot');
    await page.click('text=Knowledge Base');

    // Upload document
    const fileInput = page.locator('input[type="file"]');
    await fileInput.setInputFiles('tests/fixtures/sample.txt');
    await expect(page.locator('text=sample.txt')).toBeVisible();
    await expect(page.locator('text=Indexed')).toBeVisible({ timeout: 60000 });

    // Test chat with knowledge
    await page.click('text=Test Chat');
    await page.fill('[data-testid="message-input"]', 'What is in the document?');
    await page.click('text=Send');

    // Should reference the document
    await expect(page.locator('[data-testid="assistant-message"]')).toBeVisible({
      timeout: 30000,
    });
  });
});
```
