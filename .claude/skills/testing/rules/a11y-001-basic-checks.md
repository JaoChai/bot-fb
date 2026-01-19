---
id: a11y-001-basic-checks
title: Run Basic Accessibility Checks
impact: MEDIUM
impactDescription: "App unusable for users with disabilities"
category: a11y
tags: [accessibility, a11y, wcag, axe]
relatedRules: [a11y-002-keyboard-navigation, a11y-003-screen-reader]
---

## Why This Matters

Accessibility ensures your app is usable by everyone, including people with disabilities. It's also often legally required.

## Bad Example

```typescript
test('shows login form', async ({ page }) => {
  await page.goto('/login');

  // Only tests visibility, not accessibility
  await expect(page.locator('form')).toBeVisible();
  await expect(page.locator('input')).toHaveCount(2);
});

// Form has no labels, wrong roles, missing alt text
```

**Why it's problematic:**
- Screen readers can't navigate
- Keyboard users stuck
- Low vision users can't read
- Legal liability

## Good Example

```typescript
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test.describe('Accessibility', () => {
  test('login page has no accessibility violations', async ({ page }) => {
    await page.goto('/login');

    const accessibilityScanResults = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
      .analyze();

    expect(accessibilityScanResults.violations).toEqual([]);
  });

  test('dashboard has no accessibility violations', async ({ page }) => {
    await loginAsTestUser(page);
    await page.goto('/dashboard');

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa'])
      .exclude('[data-testid="third-party-widget"]')  // Exclude third-party
      .analyze();

    expect(results.violations).toEqual([]);
  });

  test('forms have proper labels', async ({ page }) => {
    await page.goto('/login');

    // All inputs should have labels
    const inputs = page.locator('input');
    const count = await inputs.count();

    for (let i = 0; i < count; i++) {
      const input = inputs.nth(i);
      const id = await input.getAttribute('id');
      const ariaLabel = await input.getAttribute('aria-label');
      const ariaLabelledby = await input.getAttribute('aria-labelledby');

      // Should have some form of label
      const hasLabel = id && (await page.locator(`label[for="${id}"]`).count()) > 0;
      const hasAriaLabel = ariaLabel || ariaLabelledby;

      expect(hasLabel || hasAriaLabel).toBeTruthy();
    }
  });

  test('images have alt text', async ({ page }) => {
    await page.goto('/dashboard');

    const images = page.locator('img');
    const count = await images.count();

    for (let i = 0; i < count; i++) {
      const img = images.nth(i);
      const alt = await img.getAttribute('alt');
      const role = await img.getAttribute('role');

      // Should have alt text or role="presentation"
      expect(alt !== null || role === 'presentation').toBeTruthy();
    }
  });

  test('buttons have accessible names', async ({ page }) => {
    await page.goto('/dashboard');

    const buttons = page.locator('button');
    const count = await buttons.count();

    for (let i = 0; i < count; i++) {
      const button = buttons.nth(i);
      const name = await button.evaluate(el =>
        el.textContent || el.getAttribute('aria-label')
      );

      expect(name).toBeTruthy();
    }
  });

  test('color contrast is sufficient', async ({ page }) => {
    await page.goto('/dashboard');

    const results = await new AxeBuilder({ page })
      .withRules(['color-contrast'])
      .analyze();

    if (results.violations.length > 0) {
      console.log('Contrast violations:', results.violations.map(v => ({
        help: v.help,
        nodes: v.nodes.map(n => n.html),
      })));
    }

    expect(results.violations).toEqual([]);
  });
});

// Check multiple pages
const pagesToTest = [
  { url: '/login', name: 'Login' },
  { url: '/signup', name: 'Signup' },
  { url: '/dashboard', name: 'Dashboard', auth: true },
  { url: '/settings', name: 'Settings', auth: true },
];

for (const p of pagesToTest) {
  test(`${p.name} page accessibility`, async ({ page }) => {
    if (p.auth) {
      await loginAsTestUser(page);
    }
    await page.goto(p.url);

    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa'])
      .analyze();

    expect(results.violations).toEqual([]);
  });
}
```

**Why it's better:**
- Automated checks
- WCAG compliance
- Tests all pages
- Specific checks

## Test Coverage

| Check | WCAG Level |
|-------|-----------|
| Labels | A |
| Alt text | A |
| Color contrast | AA |
| Focus visible | AA |
| Keyboard | A |

## Run Command

```bash
# Run accessibility tests
npx playwright test --grep "a11y\|accessibility"

# Install axe-core
npm install -D @axe-core/playwright
```

## Project-Specific Notes

**BotFacebook Accessibility Testing:**

```typescript
// a11y.setup.ts - Global accessibility checks
import AxeBuilder from '@axe-core/playwright';

export async function checkAccessibility(page: Page, excludeSelectors: string[] = []) {
  let builder = new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21aa']);

  for (const selector of excludeSelectors) {
    builder = builder.exclude(selector);
  }

  const results = await builder.analyze();

  if (results.violations.length > 0) {
    console.log('Accessibility violations:');
    results.violations.forEach(v => {
      console.log(`- ${v.help} (${v.impact})`);
      v.nodes.forEach(n => console.log(`  ${n.html}`));
    });
  }

  return results;
}

// Chat interface accessibility
test('chat interface is accessible', async ({ page }) => {
  await loginAsTestUser(page);
  await page.goto('/chat/test-bot');

  const results = await checkAccessibility(page, [
    '[data-testid="third-party-embed"]',  // Skip third-party
  ]);

  expect(results.violations).toEqual([]);

  // Specific checks for chat
  const messageInput = page.locator('[data-testid="message-input"]');
  await expect(messageInput).toHaveAttribute('aria-label', /.+/);

  const sendButton = page.locator('[data-testid="send-button"]');
  await expect(sendButton).toHaveAttribute('aria-label', /.+/);
});
```
