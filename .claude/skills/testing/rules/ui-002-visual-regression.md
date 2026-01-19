---
id: ui-002-visual-regression
title: Use Visual Regression Testing
impact: MEDIUM
impactDescription: "Visual bugs slip through unnoticed"
category: ui
tags: [ui-test, visual, regression, screenshots]
relatedRules: [ui-001-responsive, ui-003-animations]
---

## Why This Matters

Visual regression tests catch unintended UI changes by comparing screenshots. Small CSS changes that break layouts are caught automatically.

## Bad Example

```typescript
test('dashboard looks correct', async ({ page }) => {
  await page.goto('/dashboard');

  // Only checks element exists, not how it looks
  await expect(page.locator('.dashboard')).toBeVisible();
});

// CSS typo changes layout - test passes!
```

**Why it's problematic:**
- Doesn't verify appearance
- CSS bugs slip through
- Layout shifts undetected
- Spacing issues missed

## Good Example

```typescript
import { test, expect } from '@playwright/test';

test.describe('Visual Regression', () => {
  test('dashboard matches snapshot', async ({ page }) => {
    await page.goto('/dashboard');
    await page.waitForLoadState('networkidle');

    // Full page screenshot comparison
    await expect(page).toHaveScreenshot('dashboard.png', {
      maxDiffPixelRatio: 0.01,  // Allow 1% difference
      animations: 'disabled',   // Disable CSS animations
    });
  });

  test('bot card component matches snapshot', async ({ page }) => {
    await page.goto('/dashboard');

    // Component-level screenshot
    const botCard = page.locator('[data-testid="bot-card"]').first();
    await expect(botCard).toHaveScreenshot('bot-card.png');
  });

  test('login form matches snapshot', async ({ page }) => {
    await page.goto('/login');

    await expect(page.locator('[data-testid="login-form"]'))
      .toHaveScreenshot('login-form.png');
  });

  test('empty state matches snapshot', async ({ page }) => {
    // Create user with no bots
    await loginAsNewUser(page);
    await page.goto('/dashboard');

    await expect(page.locator('[data-testid="empty-state"]'))
      .toHaveScreenshot('empty-state.png');
  });

  test('error states match snapshot', async ({ page }) => {
    await page.goto('/login');
    await page.fill('[name="email"]', 'test@example.com');
    await page.fill('[name="password"]', 'wrong');
    await page.click('button[type="submit"]');

    await expect(page.locator('[data-testid="error-message"]'))
      .toHaveScreenshot('login-error.png');
  });

  test('loading state matches snapshot', async ({ page }) => {
    // Slow down network to capture loading
    await page.route('**/api/**', async route => {
      await new Promise(r => setTimeout(r, 1000));
      await route.continue();
    });

    await page.goto('/dashboard');

    await expect(page.locator('[data-testid="loading-skeleton"]'))
      .toHaveScreenshot('loading-skeleton.png');
  });
});

// Multiple breakpoints
test.describe('Responsive Visual Regression', () => {
  const viewports = [
    { width: 375, height: 667, name: 'mobile' },
    { width: 1280, height: 800, name: 'desktop' },
  ];

  for (const vp of viewports) {
    test(`dashboard on ${vp.name}`, async ({ page }) => {
      await page.setViewportSize(vp);
      await page.goto('/dashboard');
      await page.waitForLoadState('networkidle');

      await expect(page).toHaveScreenshot(`dashboard-${vp.name}.png`, {
        animations: 'disabled',
      });
    });
  }
});

// Dark mode testing
test('dark mode matches snapshot', async ({ page }) => {
  await page.emulateMedia({ colorScheme: 'dark' });
  await page.goto('/dashboard');

  await expect(page).toHaveScreenshot('dashboard-dark.png');
});
```

**Why it's better:**
- Catches visual bugs
- Tests multiple states
- Tests dark mode
- Tests responsive

## Test Coverage

| Screenshot Type | When to Use |
|-----------------|------------|
| Full page | Page layouts |
| Component | Reusable components |
| State-based | Loading, error, empty |
| Multi-viewport | Responsive design |

## Run Command

```bash
# Update snapshots
npx playwright test --update-snapshots

# Run visual tests
npx playwright test --grep "snapshot\|visual"
```

## Project-Specific Notes

**BotFacebook Visual Testing:**

```typescript
// playwright.config.ts
export default defineConfig({
  expect: {
    toHaveScreenshot: {
      maxDiffPixelRatio: 0.01,
      animations: 'disabled',
    },
  },
  snapshotDir: './tests/e2e/snapshots',
});

// Mask dynamic content
test('dashboard with masked dynamic content', async ({ page }) => {
  await page.goto('/dashboard');

  await expect(page).toHaveScreenshot('dashboard.png', {
    mask: [
      page.locator('[data-testid="timestamp"]'),
      page.locator('[data-testid="user-avatar"]'),
      page.locator('[data-testid="message-count"]'),
    ],
    animations: 'disabled',
  });
});

// Test theming
test.describe('Theme Visual Testing', () => {
  const themes = ['light', 'dark'];

  for (const theme of themes) {
    test(`${theme} theme`, async ({ page }) => {
      await page.goto(`/dashboard?theme=${theme}`);
      await expect(page).toHaveScreenshot(`dashboard-${theme}.png`);
    });
  }
});
```
