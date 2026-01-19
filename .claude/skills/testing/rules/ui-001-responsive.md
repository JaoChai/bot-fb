---
id: ui-001-responsive
title: Test Responsive Layouts
impact: MEDIUM
impactDescription: "UI broken on mobile or tablet devices"
category: ui
tags: [ui-test, responsive, mobile, breakpoints]
relatedRules: [ui-002-visual-regression, e2e-004-selectors]
---

## Why This Matters

Users access apps from various devices. Responsive tests ensure layouts work correctly at all breakpoints.

## Bad Example

```typescript
test('shows dashboard', async ({ page }) => {
  await page.goto('/dashboard');
  // Only tests at default viewport
  await expect(page.locator('.sidebar')).toBeVisible();
  await expect(page.locator('.main-content')).toBeVisible();
});

// Mobile users see broken layout!
```

**Why it's problematic:**
- Only tests one viewport
- Mobile layout untested
- Tablet issues missed
- Users complain about broken UI

## Good Example

```typescript
import { test, expect } from '@playwright/test';

// Define breakpoints
const breakpoints = [
  { width: 375, height: 667, name: 'mobile' },
  { width: 768, height: 1024, name: 'tablet' },
  { width: 1280, height: 800, name: 'desktop' },
  { width: 1920, height: 1080, name: 'large-desktop' },
];

test.describe('Responsive Layout', () => {
  for (const bp of breakpoints) {
    test(`dashboard renders correctly on ${bp.name}`, async ({ page }) => {
      await page.setViewportSize({ width: bp.width, height: bp.height });
      await page.goto('/dashboard');

      // Common elements
      await expect(page.locator('[data-testid="header"]')).toBeVisible();
      await expect(page.locator('[data-testid="main-content"]')).toBeVisible();

      // Layout-specific checks
      if (bp.name === 'mobile') {
        // Mobile: sidebar should be hidden by default
        await expect(page.locator('[data-testid="sidebar"]')).not.toBeVisible();
        await expect(page.locator('[data-testid="mobile-menu-button"]')).toBeVisible();
      } else {
        // Desktop: sidebar visible
        await expect(page.locator('[data-testid="sidebar"]')).toBeVisible();
      }
    });
  }

  test('mobile navigation works', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/dashboard');

    // Open mobile menu
    await page.click('[data-testid="mobile-menu-button"]');
    await expect(page.locator('[data-testid="mobile-nav"]')).toBeVisible();

    // Navigate
    await page.click('[data-testid="mobile-nav"] >> text=Settings');
    await expect(page).toHaveURL('/settings');
  });

  test('tables scroll horizontally on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/analytics');

    const table = page.locator('[data-testid="data-table"]');
    await expect(table).toBeVisible();

    // Table should be scrollable
    const tableBox = await table.boundingBox();
    expect(tableBox?.width).toBeLessThanOrEqual(375);
  });

  test('forms are usable on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/bots/new');

    // All form fields should be visible and tappable
    await expect(page.locator('[data-testid="bot-name-input"]')).toBeVisible();
    await expect(page.locator('[data-testid="platform-select"]')).toBeVisible();
    await expect(page.locator('[data-testid="submit-button"]')).toBeVisible();

    // Fields should be full width on mobile
    const input = page.locator('[data-testid="bot-name-input"]');
    const inputBox = await input.boundingBox();
    expect(inputBox?.width).toBeGreaterThan(300);  // Nearly full width
  });
});

// Test orientation changes
test('handles orientation change', async ({ page }) => {
  // Portrait
  await page.setViewportSize({ width: 375, height: 667 });
  await page.goto('/dashboard');
  await expect(page.locator('[data-testid="sidebar"]')).not.toBeVisible();

  // Landscape (wider)
  await page.setViewportSize({ width: 667, height: 375 });
  // Layout might still be mobile in landscape
  await expect(page.locator('[data-testid="mobile-menu-button"]')).toBeVisible();
});
```

**Why it's better:**
- Tests all breakpoints
- Tests mobile navigation
- Tests touch interactions
- Tests layout changes

## Test Coverage

| Breakpoint | Width | Priority |
|------------|-------|----------|
| Mobile | 375px | Must test |
| Tablet | 768px | Should test |
| Desktop | 1280px | Must test |
| Large | 1920px | Nice to have |

## Run Command

```bash
# Run responsive tests
npx playwright test --grep "responsive\|mobile\|tablet"

# Test specific viewport
npx playwright test --viewport-size=375,667
```

## Project-Specific Notes

**BotFacebook Responsive Testing:**

```typescript
// playwright.config.ts
export default defineConfig({
  projects: [
    {
      name: 'Desktop Chrome',
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1280, height: 800 },
      },
    },
    {
      name: 'Mobile Safari',
      use: {
        ...devices['iPhone 13'],
      },
    },
    {
      name: 'Tablet',
      use: {
        ...devices['iPad Pro 11'],
      },
    },
  ],
});

// Responsive test for chat interface
test.describe('Chat Responsive', () => {
  test('chat works on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await loginAsTestUser(page);
    await page.goto('/chat/test-bot');

    // Message input should be at bottom
    const input = page.locator('[data-testid="message-input"]');
    const inputBox = await input.boundingBox();
    expect(inputBox?.y).toBeGreaterThan(500);  // Near bottom

    // Send message
    await input.fill('Hello');
    await page.click('[data-testid="send-button"]');

    // Messages should scroll
    await expect(page.locator('[data-testid="message"]').last()).toBeInViewport();
  });
});
```
