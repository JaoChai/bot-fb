---
id: ui-003-animations
title: Handle Animations in Tests
impact: LOW
impactDescription: "Flaky tests due to animation timing"
category: ui
tags: [ui-test, animations, transitions, flaky]
relatedRules: [ui-002-visual-regression, e2e-003-waiting-strategies]
---

## Why This Matters

Animations cause test flakiness when tests run faster than animations complete. Proper handling ensures reliable tests.

## Bad Example

```typescript
test('opens modal', async ({ page }) => {
  await page.goto('/dashboard');
  await page.click('[data-testid="open-modal"]');

  // Modal might still be animating!
  const content = await page.locator('.modal-content').textContent();
  expect(content).toContain('Settings');
});

test('collapses sidebar', async ({ page }) => {
  await page.goto('/dashboard');
  await page.click('[data-testid="toggle-sidebar"]');

  // Sidebar width still transitioning
  const box = await page.locator('.sidebar').boundingBox();
  expect(box?.width).toBe(0);  // Fails during animation!
});
```

**Why it's problematic:**
- Tests run faster than animations
- Screenshot taken mid-animation
- Width/height checks fail
- Random test failures

## Good Example

```typescript
import { test, expect } from '@playwright/test';

test.describe('Animation Handling', () => {
  test('waits for modal animation', async ({ page }) => {
    await page.goto('/dashboard');
    await page.click('[data-testid="open-modal"]');

    // Wait for modal to be fully visible (animation complete)
    const modal = page.locator('[data-testid="modal"]');
    await expect(modal).toBeVisible();

    // Additional wait for animation if needed
    await modal.evaluate(el =>
      el.getAnimations().forEach(a => a.finish())
    );

    // Now interact with content
    await expect(modal.locator('h2')).toContainText('Settings');
  });

  test('disables animations for screenshots', async ({ page }) => {
    // Disable all animations
    await page.addStyleTag({
      content: `
        *, *::before, *::after {
          animation-duration: 0s !important;
          transition-duration: 0s !important;
        }
      `,
    });

    await page.goto('/dashboard');
    await page.click('[data-testid="open-modal"]');

    await expect(page).toHaveScreenshot('modal-open.png', {
      animations: 'disabled',  // Playwright's built-in option
    });
  });

  test('waits for transition to complete', async ({ page }) => {
    await page.goto('/dashboard');

    const sidebar = page.locator('[data-testid="sidebar"]');

    // Click toggle
    await page.click('[data-testid="toggle-sidebar"]');

    // Wait for transition
    await sidebar.evaluate(el => {
      return new Promise(resolve => {
        el.addEventListener('transitionend', resolve, { once: true });
      });
    });

    // Now check dimensions
    const box = await sidebar.boundingBox();
    expect(box?.width).toBe(0);
  });

  test('uses reduced motion preference', async ({ page }) => {
    // Set reduced motion preference
    await page.emulateMedia({ reducedMotion: 'reduce' });

    await page.goto('/dashboard');
    await page.click('[data-testid="open-modal"]');

    // App should respect prefers-reduced-motion
    // Animations should be instant
    await expect(page.locator('[data-testid="modal"]')).toBeVisible();
  });

  test('tests loading animations', async ({ page }) => {
    // Slow down network
    await page.route('**/api/**', async route => {
      await new Promise(r => setTimeout(r, 2000));
      await route.continue();
    });

    await page.goto('/dashboard');

    // Loading spinner should be visible
    await expect(page.locator('[data-testid="loading-spinner"]')).toBeVisible();

    // Wait for loading to complete
    await expect(page.locator('[data-testid="loading-spinner"]'))
      .not.toBeVisible({ timeout: 10000 });

    // Content should be visible
    await expect(page.locator('[data-testid="content"]')).toBeVisible();
  });
});

// Global setup to disable animations
// playwright.config.ts
export default defineConfig({
  use: {
    // Disable CSS animations
    contextOptions: {
      reducedMotion: 'reduce',
    },
  },
});
```

**Why it's better:**
- Waits for animations
- Disables when needed
- Screenshots are stable
- Respects user preferences

## Test Coverage

| Animation Type | Strategy |
|----------------|----------|
| CSS transitions | Wait for transitionend |
| CSS animations | animations: 'disabled' |
| JS animations | Wait for element state |
| Loading | Wait for content |

## Run Command

```bash
# Run with reduced motion
npx playwright test --project="chromium" --reduced-motion=reduce
```

## Project-Specific Notes

**BotFacebook Animation Testing:**

```typescript
// Helper to disable animations
async function disableAnimations(page: Page) {
  await page.addStyleTag({
    content: `
      *, *::before, *::after {
        animation-duration: 0.001ms !important;
        animation-delay: 0ms !important;
        transition-duration: 0.001ms !important;
        transition-delay: 0ms !important;
      }
    `,
  });
}

// Test chat message animation
test('new message appears with animation', async ({ page }) => {
  await disableAnimations(page);
  await loginAsTestUser(page);
  await page.goto('/chat/test-bot');

  await page.fill('[data-testid="message-input"]', 'Hello');
  await page.click('[data-testid="send-button"]');

  // Message should appear immediately
  await expect(page.locator('[data-testid="message"]').last()).toBeVisible();
});

// Test skeleton loading
test('skeleton loading state', async ({ page }) => {
  await page.route('**/api/v1/bots', async route => {
    await new Promise(r => setTimeout(r, 3000));
    await route.continue();
  });

  await loginAsTestUser(page);
  await page.goto('/dashboard');

  // Skeleton should be pulsing
  const skeleton = page.locator('[data-testid="skeleton"]');
  await expect(skeleton).toBeVisible();
  await expect(skeleton).toHaveClass(/animate-pulse/);
});
```
