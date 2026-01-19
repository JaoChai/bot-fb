---
id: e2e-002-auth-flow
title: Test Authentication Flow Completely
impact: HIGH
impactDescription: "Users locked out or unauthorized access possible"
category: e2e
tags: [e2e, playwright, auth, login, security]
relatedRules: [e2e-001-critical-paths, feature-001-auth-testing]
---

## Why This Matters

Auth flows involve multiple steps, redirects, and session management. E2E tests ensure the complete flow works from the user's perspective.

## Bad Example

```typescript
test('can login', async ({ page }) => {
  await page.goto('/login');
  await page.fill('[name="email"]', 'test@example.com');
  await page.fill('[name="password"]', 'password');
  await page.click('button[type="submit"]');

  // Only checks URL
  await expect(page).toHaveURL('/dashboard');
});

// Missing: logout, session persistence, error cases
```

**Why it's problematic:**
- Doesn't test session persistence
- Doesn't test logout
- Doesn't test error handling
- Doesn't test redirects

## Good Example

```typescript
import { test, expect } from '@playwright/test';

test.describe('Authentication Flow', () => {
  test('complete login and session persistence', async ({ page }) => {
    await page.goto('/login');
    await page.fill('[name="email"]', 'test@example.com');
    await page.fill('[name="password"]', 'password');
    await page.click('button[type="submit"]');

    // Verify logged in
    await expect(page).toHaveURL('/dashboard');
    await expect(page.locator('[data-testid="user-menu"]')).toBeVisible();

    // Verify session persists after refresh
    await page.reload();
    await expect(page).toHaveURL('/dashboard');
    await expect(page.locator('[data-testid="user-menu"]')).toBeVisible();
  });

  test('logout clears session', async ({ page }) => {
    // Login first
    await loginAsTestUser(page);

    // Logout
    await page.click('[data-testid="user-menu"]');
    await page.click('text=Logout');

    // Verify logged out
    await expect(page).toHaveURL('/login');

    // Verify can't access protected route
    await page.goto('/dashboard');
    await expect(page).toHaveURL('/login');
  });

  test('shows error for invalid credentials', async ({ page }) => {
    await page.goto('/login');
    await page.fill('[name="email"]', 'test@example.com');
    await page.fill('[name="password"]', 'wrongpassword');
    await page.click('button[type="submit"]');

    // Verify error shown
    await expect(page.locator('text=Invalid credentials')).toBeVisible();
    await expect(page).toHaveURL('/login');  // Still on login page
  });

  test('redirects to intended page after login', async ({ page }) => {
    // Try to access protected page
    await page.goto('/bots/settings');

    // Should redirect to login
    await expect(page).toHaveURL(/\/login/);

    // Login
    await page.fill('[name="email"]', 'test@example.com');
    await page.fill('[name="password"]', 'password');
    await page.click('button[type="submit"]');

    // Should redirect to original intended page
    await expect(page).toHaveURL('/bots/settings');
  });

  test('handles expired session', async ({ page, context }) => {
    // Login
    await loginAsTestUser(page);

    // Clear cookies to simulate expired session
    await context.clearCookies();

    // Navigate - should redirect to login
    await page.goto('/dashboard');
    await expect(page).toHaveURL('/login');
  });

  test('password reset flow', async ({ page }) => {
    await page.goto('/login');
    await page.click('text=Forgot password');

    await expect(page).toHaveURL('/forgot-password');

    await page.fill('[name="email"]', 'test@example.com');
    await page.click('button[type="submit"]');

    await expect(page.locator('text=Reset link sent')).toBeVisible();
  });

  test('rate limits login attempts', async ({ page }) => {
    await page.goto('/login');

    // Multiple failed attempts
    for (let i = 0; i < 6; i++) {
      await page.fill('[name="email"]', 'test@example.com');
      await page.fill('[name="password"]', 'wrongpassword');
      await page.click('button[type="submit"]');
      await page.waitForTimeout(500);
    }

    // Should show rate limit message
    await expect(page.locator('text=Too many attempts')).toBeVisible();
  });
});
```

**Why it's better:**
- Tests complete auth lifecycle
- Tests session persistence
- Tests error cases
- Tests rate limiting

## Test Coverage

| Auth Scenario | Priority |
|---------------|----------|
| Login success | Must test |
| Login failure | Must test |
| Session persistence | Must test |
| Logout | Must test |
| Redirect after login | Should test |
| Password reset | Should test |
| Rate limiting | Should test |

## Run Command

```bash
# Run auth tests
npx playwright test --grep "auth\|login\|logout"
```

## Project-Specific Notes

**BotFacebook Auth Testing:**

```typescript
// tests/e2e/auth.spec.ts

test.describe('BotFacebook Auth', () => {
  test('login with remember me persists longer', async ({ page }) => {
    await page.goto('/login');
    await page.fill('[name="email"]', 'test@example.com');
    await page.fill('[name="password"]', 'password');
    await page.check('[name="remember"]');
    await page.click('button[type="submit"]');

    await expect(page).toHaveURL('/dashboard');

    // Verify remember me cookie set
    const cookies = await page.context().cookies();
    const rememberCookie = cookies.find(c => c.name.includes('remember'));
    expect(rememberCookie).toBeTruthy();
    expect(rememberCookie?.expires).toBeGreaterThan(Date.now() / 1000 + 86400);
  });

  test('OAuth login redirect', async ({ page }) => {
    await page.goto('/login');
    await page.click('button:text("Continue with Google")');

    // Should redirect to OAuth provider
    await expect(page.url()).toContain('accounts.google.com');
  });
});

// Auth state fixture for reuse
import { test as baseTest } from '@playwright/test';

export const test = baseTest.extend({
  authenticatedPage: async ({ page }, use) => {
    await page.goto('/login');
    await page.fill('[name="email"]', 'test@example.com');
    await page.fill('[name="password"]', 'password');
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL('/dashboard');
    await use(page);
  },
});
```
