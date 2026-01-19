---
id: ui-004-dark-mode
title: Test Dark Mode and Theming
impact: LOW
impactDescription: "Theme-specific bugs not caught"
category: ui
tags: [ui-test, dark-mode, theming, color-scheme]
relatedRules: [ui-002-visual-regression, a11y-002-color-contrast]
---

## Why This Matters

Users increasingly expect dark mode support. Theme-specific bugs like invisible text or broken contrasts need testing.

## Bad Example

```typescript
test('shows dashboard', async ({ page }) => {
  await page.goto('/dashboard');
  // Only tests default (light) mode
  await expect(page.locator('.sidebar')).toBeVisible();
});

// Dark mode users see white text on white background!
```

**Why it's problematic:**
- Dark mode untested
- Color contrast issues
- Theme switching bugs
- User preference ignored

## Good Example

```typescript
import { test, expect } from '@playwright/test';

test.describe('Theme Testing', () => {
  test('renders correctly in light mode', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await page.goto('/dashboard');

    // Verify light mode styles
    await expect(page.locator('body')).toHaveCSS('background-color', 'rgb(255, 255, 255)');
    await expect(page).toHaveScreenshot('dashboard-light.png');
  });

  test('renders correctly in dark mode', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/dashboard');

    // Verify dark mode styles
    const body = page.locator('body');
    const bgColor = await body.evaluate(el => getComputedStyle(el).backgroundColor);
    expect(bgColor).not.toBe('rgb(255, 255, 255)');  // Not white

    await expect(page).toHaveScreenshot('dashboard-dark.png');
  });

  test('theme toggle switches correctly', async ({ page }) => {
    await page.goto('/dashboard');

    // Default theme
    await expect(page.locator('body')).toHaveAttribute('data-theme', 'light');

    // Toggle to dark
    await page.click('[data-testid="theme-toggle"]');
    await expect(page.locator('body')).toHaveAttribute('data-theme', 'dark');

    // Toggle back
    await page.click('[data-testid="theme-toggle"]');
    await expect(page.locator('body')).toHaveAttribute('data-theme', 'light');
  });

  test('respects system preference', async ({ page }) => {
    // Set system to dark mode
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/dashboard');

    // App should automatically be dark
    await expect(page.locator('body')).toHaveAttribute('data-theme', 'dark');
  });

  test('persists theme preference', async ({ page, context }) => {
    await page.goto('/dashboard');

    // Switch to dark
    await page.click('[data-testid="theme-toggle"]');
    await expect(page.locator('body')).toHaveAttribute('data-theme', 'dark');

    // Reload page
    await page.reload();

    // Should still be dark
    await expect(page.locator('body')).toHaveAttribute('data-theme', 'dark');
  });

  test('all components visible in dark mode', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/dashboard');

    // Check key elements are visible
    await expect(page.locator('[data-testid="header"]')).toBeVisible();
    await expect(page.locator('[data-testid="sidebar"]')).toBeVisible();
    await expect(page.locator('[data-testid="bot-card"]').first()).toBeVisible();

    // Check text is readable (contrast check)
    const text = page.locator('[data-testid="bot-name"]').first();
    const color = await text.evaluate(el => getComputedStyle(el).color);
    // Should not be black text (would be invisible on dark bg)
    expect(color).not.toBe('rgb(0, 0, 0)');
  });
});

// Test both themes for all critical pages
const pages = ['/dashboard', '/bots', '/settings', '/chat'];
const themes = ['light', 'dark'];

for (const url of pages) {
  for (const theme of themes) {
    test(`${url} in ${theme} mode`, async ({ page }) => {
      await page.emulateMedia({ colorScheme: theme as 'light' | 'dark' });
      await page.goto(url);

      await expect(page).toHaveScreenshot(`${url.slice(1) || 'home'}-${theme}.png`);
    });
  }
}
```

**Why it's better:**
- Tests both themes
- Tests system preference
- Tests theme switching
- Tests persistence

## Test Coverage

| Theme Feature | Tests |
|---------------|-------|
| Light mode | Visual, contrast |
| Dark mode | Visual, contrast |
| Toggle | Switch works |
| Persistence | Saved preference |
| System preference | Auto-detection |

## Run Command

```bash
# Run dark mode tests
npx playwright test --grep "dark\|theme"
```

## Project-Specific Notes

**BotFacebook Theme Testing:**

```typescript
// playwright.config.ts - Separate dark mode project
export default defineConfig({
  projects: [
    {
      name: 'Light Mode',
      use: {
        colorScheme: 'light',
      },
    },
    {
      name: 'Dark Mode',
      use: {
        colorScheme: 'dark',
      },
    },
  ],
});

// Chat interface in dark mode
test('chat messages readable in dark mode', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  await loginAsTestUser(page);
  await page.goto('/chat/test-bot');

  // User message bubble
  const userMsg = page.locator('[data-testid="user-message"]').first();
  await expect(userMsg).toBeVisible();

  // Assistant message bubble
  const assistantMsg = page.locator('[data-testid="assistant-message"]').first();
  await expect(assistantMsg).toBeVisible();

  // Code blocks readable
  const codeBlock = page.locator('[data-testid="code-block"]').first();
  if (await codeBlock.count() > 0) {
    await expect(codeBlock).toBeVisible();
    const bgColor = await codeBlock.evaluate(el => getComputedStyle(el).backgroundColor);
    // Should have visible background
    expect(bgColor).not.toBe('transparent');
  }
});
```
