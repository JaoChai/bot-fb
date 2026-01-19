---
id: a11y-003-screen-reader
title: Test Screen Reader Support
impact: MEDIUM
impactDescription: "Blind users cannot use the application"
category: a11y
tags: [accessibility, screen-reader, aria, semantics]
relatedRules: [a11y-001-basic-checks, a11y-002-keyboard-navigation]
---

## Why This Matters

Screen reader users rely on semantic HTML and ARIA attributes to understand and navigate your app. Missing or incorrect markup makes the app unusable.

## Bad Example

```html
<!-- No semantic structure -->
<div class="header">My App</div>
<div class="nav">
  <div class="link" onclick="navigate('home')">Home</div>
  <div class="link" onclick="navigate('about')">About</div>
</div>
<div class="content">
  <div class="title">Welcome</div>
  <div class="btn" onclick="doAction()">Click me</div>
</div>

<!-- Screen reader: "My App Home About Welcome Click me"
     No structure, no landmarks, no interactive elements -->
```

**Why it's problematic:**
- No semantic structure
- Divs not announced properly
- No landmarks for navigation
- Custom elements not accessible

## Good Example

```typescript
import { test, expect } from '@playwright/test';

test.describe('Screen Reader Support', () => {
  test('page has proper heading structure', async ({ page }) => {
    await page.goto('/dashboard');

    // Check heading hierarchy
    const h1 = await page.locator('h1').count();
    expect(h1).toBe(1);  // Only one h1

    // H2s should come after H1
    const headings = await page.$$eval('h1, h2, h3, h4, h5, h6', els =>
      els.map(el => ({ tag: el.tagName, text: el.textContent }))
    );

    // Verify no skipped levels (h1 -> h3 without h2)
    let lastLevel = 0;
    for (const heading of headings) {
      const level = parseInt(heading.tag[1]);
      expect(level).toBeLessThanOrEqual(lastLevel + 1);
      lastLevel = level;
    }
  });

  test('page has proper landmarks', async ({ page }) => {
    await page.goto('/dashboard');

    // Check for landmark regions
    const landmarks = await page.$$eval('[role], main, nav, header, footer, aside', els =>
      els.map(el => el.getAttribute('role') || el.tagName.toLowerCase())
    );

    expect(landmarks).toContain('main');
    expect(landmarks).toContain('navigation');
    expect(landmarks).toContain('banner'); // header
  });

  test('dynamic content has live regions', async ({ page }) => {
    await loginAsTestUser(page);
    await page.goto('/dashboard');

    // Notification area should be a live region
    const notifications = page.locator('[data-testid="notifications"]');
    const ariaLive = await notifications.getAttribute('aria-live');
    expect(ariaLive).toBe('polite');

    // Error messages should be assertive
    await page.goto('/login');
    await page.fill('[name="email"]', 'invalid');
    await page.click('button[type="submit"]');

    const error = page.locator('[data-testid="error-message"]');
    const errorLive = await error.getAttribute('aria-live');
    expect(errorLive).toBe('assertive');
  });

  test('buttons have accessible names', async ({ page }) => {
    await page.goto('/dashboard');

    const buttons = page.locator('button');
    const count = await buttons.count();

    for (let i = 0; i < count; i++) {
      const button = buttons.nth(i);
      const accessibleName = await button.evaluate(el => {
        // Get computed accessible name
        return el.getAttribute('aria-label') ||
               el.getAttribute('aria-labelledby') ||
               el.textContent?.trim();
      });

      expect(accessibleName).toBeTruthy();
      expect(accessibleName?.length).toBeGreaterThan(0);
    }
  });

  test('form fields have proper labels', async ({ page }) => {
    await page.goto('/login');

    // Email field
    const emailInput = page.locator('[name="email"]');
    const emailId = await emailInput.getAttribute('id');
    const emailLabel = page.locator(`label[for="${emailId}"]`);
    await expect(emailLabel).toBeVisible();

    // Or aria-label
    const ariaLabel = await emailInput.getAttribute('aria-label');
    expect(emailId || ariaLabel).toBeTruthy();
  });

  test('icons have accessible labels', async ({ page }) => {
    await page.goto('/dashboard');

    // Icon buttons
    const iconButtons = page.locator('button svg').locator('..');
    const count = await iconButtons.count();

    for (let i = 0; i < count; i++) {
      const button = iconButtons.nth(i);
      const ariaLabel = await button.getAttribute('aria-label');
      const srOnly = await button.locator('.sr-only').count();

      // Should have aria-label or screen-reader-only text
      expect(ariaLabel || srOnly > 0).toBeTruthy();
    }

    // Decorative icons should be hidden
    const decorativeIcons = page.locator('svg[aria-hidden="true"]');
    expect(await decorativeIcons.count()).toBeGreaterThan(0);
  });

  test('tables have proper structure', async ({ page }) => {
    await page.goto('/analytics');

    const table = page.locator('table');
    if (await table.count() > 0) {
      // Should have caption or aria-label
      const caption = await table.locator('caption').count();
      const ariaLabel = await table.getAttribute('aria-label');
      expect(caption > 0 || ariaLabel).toBeTruthy();

      // Should have th elements
      const headers = await table.locator('th').count();
      expect(headers).toBeGreaterThan(0);

      // Headers should have scope
      const scopedHeaders = await table.locator('th[scope]').count();
      expect(scopedHeaders).toBe(headers);
    }
  });

  test('loading states are announced', async ({ page }) => {
    await page.route('**/api/**', async route => {
      await new Promise(r => setTimeout(r, 2000));
      await route.continue();
    });

    await loginAsTestUser(page);
    await page.goto('/dashboard');

    const loadingIndicator = page.locator('[data-testid="loading"]');

    // Should have aria-busy or role="status"
    const ariaBusy = await loadingIndicator.getAttribute('aria-busy');
    const role = await loadingIndicator.getAttribute('role');

    expect(ariaBusy === 'true' || role === 'status').toBeTruthy();
  });
});
```

**Why it's better:**
- Tests semantic HTML
- Tests landmarks
- Tests ARIA attributes
- Tests dynamic content

## Test Coverage

| Element | Screen Reader Need |
|---------|-------------------|
| Headings | Proper hierarchy |
| Landmarks | Navigation points |
| Buttons | Accessible names |
| Forms | Labels |
| Tables | Headers, captions |
| Dynamic | Live regions |

## Run Command

```bash
# Run screen reader tests
npx playwright test --grep "screen-reader\|aria\|semantic"
```

## Project-Specific Notes

**BotFacebook Screen Reader Testing:**

```typescript
// Chat messages for screen readers
test('chat messages announced properly', async ({ page }) => {
  await loginAsTestUser(page);
  await page.goto('/chat/test-bot');

  const messageList = page.locator('[data-testid="message-list"]');

  // Should be a live region
  const ariaLive = await messageList.getAttribute('aria-live');
  expect(ariaLive).toBe('polite');

  // Messages should have proper roles
  const messages = page.locator('[data-testid="message"]');
  const role = await messages.first().getAttribute('role');
  expect(role).toBe('article');  // Or 'listitem' if in list

  // User/assistant distinction
  const userMsg = page.locator('[data-testid="user-message"]').first();
  const ariaPerson = await userMsg.getAttribute('aria-label');
  expect(ariaPerson).toContain('You');

  const assistantMsg = page.locator('[data-testid="assistant-message"]').first();
  const ariaAssistant = await assistantMsg.getAttribute('aria-label');
  expect(ariaAssistant).toContain('Bot');
});

// Required HTML structure
// <main role="main" aria-label="Chat">
//   <ul role="list" aria-live="polite" data-testid="message-list">
//     <li role="listitem" aria-label="You said" data-testid="user-message">
//       Hello
//     </li>
//     <li role="listitem" aria-label="Bot replied" data-testid="assistant-message">
//       Hi there!
//     </li>
//   </ul>
//   <form>
//     <label for="message-input" class="sr-only">Type a message</label>
//     <input id="message-input" aria-label="Type a message" />
//     <button type="submit" aria-label="Send message">
//       <svg aria-hidden="true" />
//     </button>
//   </form>
// </main>
```
