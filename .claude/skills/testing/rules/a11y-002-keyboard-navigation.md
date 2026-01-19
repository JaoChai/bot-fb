---
id: a11y-002-keyboard-navigation
title: Test Keyboard Navigation
impact: MEDIUM
impactDescription: "Keyboard-only users cannot navigate the app"
category: a11y
tags: [accessibility, keyboard, focus, navigation]
relatedRules: [a11y-001-basic-checks, a11y-003-screen-reader]
---

## Why This Matters

Many users navigate with keyboard only (motor disabilities, power users, screen reader users). All interactive elements must be keyboard accessible.

## Bad Example

```typescript
test('can click create button', async ({ page }) => {
  await page.goto('/dashboard');
  await page.click('[data-testid="create-button"]');
  // Works with mouse, but...
});

// <div onClick={handleClick}>Create</div>
// Cannot be tabbed to or activated with keyboard!
```

**Why it's problematic:**
- Divs not focusable
- Custom elements miss focus
- Tab order broken
- Keyboard traps

## Good Example

```typescript
import { test, expect } from '@playwright/test';

test.describe('Keyboard Navigation', () => {
  test('can navigate login form with keyboard', async ({ page }) => {
    await page.goto('/login');

    // Tab to email field
    await page.keyboard.press('Tab');
    await expect(page.locator('[name="email"]')).toBeFocused();

    // Type email
    await page.keyboard.type('test@example.com');

    // Tab to password
    await page.keyboard.press('Tab');
    await expect(page.locator('[name="password"]')).toBeFocused();

    // Type password
    await page.keyboard.type('password');

    // Tab to submit
    await page.keyboard.press('Tab');
    const submitButton = page.locator('button[type="submit"]');
    await expect(submitButton).toBeFocused();

    // Submit with Enter
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL('/dashboard');
  });

  test('modal can be closed with Escape', async ({ page }) => {
    await page.goto('/dashboard');
    await page.click('[data-testid="open-modal"]');

    // Modal should be visible
    await expect(page.locator('[data-testid="modal"]')).toBeVisible();

    // Press Escape to close
    await page.keyboard.press('Escape');

    // Modal should be closed
    await expect(page.locator('[data-testid="modal"]')).not.toBeVisible();
  });

  test('focus is trapped in modal', async ({ page }) => {
    await page.goto('/dashboard');
    await page.click('[data-testid="open-modal"]');

    const modal = page.locator('[data-testid="modal"]');
    await expect(modal).toBeVisible();

    // Get focusable elements in modal
    const focusableElements = modal.locator(
      'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
    );
    const count = await focusableElements.count();

    // Tab through all elements
    for (let i = 0; i < count + 1; i++) {
      await page.keyboard.press('Tab');
    }

    // Focus should still be in modal (trapped)
    const activeElement = await page.evaluate(() => document.activeElement?.closest('[data-testid="modal"]'));
    expect(activeElement).toBeTruthy();
  });

  test('dropdown navigable with arrow keys', async ({ page }) => {
    await page.goto('/dashboard');

    // Focus dropdown
    await page.click('[data-testid="platform-select"]');

    // Navigate with arrows
    await page.keyboard.press('ArrowDown');
    await expect(page.locator('[data-testid="option-line"]')).toHaveClass(/focused|highlighted/);

    await page.keyboard.press('ArrowDown');
    await expect(page.locator('[data-testid="option-telegram"]')).toHaveClass(/focused|highlighted/);

    // Select with Enter
    await page.keyboard.press('Enter');
    await expect(page.locator('[data-testid="platform-select"]')).toContainText('Telegram');
  });

  test('skip link works', async ({ page }) => {
    await page.goto('/dashboard');

    // First tab should reach skip link
    await page.keyboard.press('Tab');
    const skipLink = page.locator('[data-testid="skip-to-content"]');
    await expect(skipLink).toBeFocused();

    // Activate skip link
    await page.keyboard.press('Enter');

    // Focus should move to main content
    await expect(page.locator('[data-testid="main-content"]')).toBeFocused();
  });

  test('tab order is logical', async ({ page }) => {
    await page.goto('/dashboard');

    const expectedOrder = [
      '[data-testid="skip-to-content"]',
      '[data-testid="nav-home"]',
      '[data-testid="nav-bots"]',
      '[data-testid="nav-settings"]',
      '[data-testid="user-menu"]',
      '[data-testid="main-content"]',
      '[data-testid="create-bot-button"]',
    ];

    for (const selector of expectedOrder) {
      await page.keyboard.press('Tab');
      const element = page.locator(selector);

      // Element should exist and be focusable
      if (await element.count() > 0) {
        await expect(element).toBeFocused();
      }
    }
  });

  test('custom buttons work with keyboard', async ({ page }) => {
    await page.goto('/dashboard');

    // Tab to custom button
    const customButton = page.locator('[data-testid="custom-action"]');
    await customButton.focus();

    // Should work with Enter
    await page.keyboard.press('Enter');
    await expect(page.locator('[data-testid="action-result"]')).toBeVisible();

    // Reset
    await page.goto('/dashboard');
    await customButton.focus();

    // Should also work with Space
    await page.keyboard.press(' ');
    await expect(page.locator('[data-testid="action-result"]')).toBeVisible();
  });
});
```

**Why it's better:**
- Tests tab navigation
- Tests keyboard shortcuts
- Tests focus management
- Tests ARIA patterns

## Test Coverage

| Pattern | Keyboard Support |
|---------|------------------|
| Buttons | Enter, Space |
| Links | Enter |
| Dropdowns | Arrows, Enter, Escape |
| Modals | Escape, focus trap |
| Forms | Tab, Enter |

## Run Command

```bash
# Run keyboard tests
npx playwright test --grep "keyboard\|focus"
```

## Project-Specific Notes

**BotFacebook Keyboard Testing:**

```typescript
// Chat keyboard navigation
test('chat supports keyboard', async ({ page }) => {
  await loginAsTestUser(page);
  await page.goto('/chat/test-bot');

  const input = page.locator('[data-testid="message-input"]');
  await input.focus();

  // Type message
  await page.keyboard.type('Hello');

  // Send with Ctrl+Enter
  await page.keyboard.press('Control+Enter');

  // Message should be sent
  await expect(page.locator('[data-testid="message"]').last()).toContainText('Hello');
});

// Bot card keyboard
test('bot cards accessible via keyboard', async ({ page }) => {
  await loginAsTestUser(page);
  await page.goto('/dashboard');

  // Tab to first bot card
  const botCard = page.locator('[data-testid="bot-card"]').first();
  await botCard.focus();

  // Enter to open
  await page.keyboard.press('Enter');
  await expect(page).toHaveURL(/\/bots\/\d+/);
});
```
