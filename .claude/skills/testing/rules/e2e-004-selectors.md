---
id: e2e-004-selectors
title: Use Stable Test Selectors
impact: MEDIUM
impactDescription: "Tests break when UI changes even though functionality works"
category: e2e
tags: [e2e, playwright, selectors, data-testid]
relatedRules: [e2e-003-waiting-strategies, e2e-005-page-objects]
---

## Why This Matters

Selectors based on class names or text break when UI changes. Stable selectors (data-testid, roles) make tests resilient to cosmetic changes.

## Bad Example

```typescript
test('creates bot', async ({ page }) => {
  // Brittle selectors
  await page.click('.btn.btn-primary.create-btn');  // Class names
  await page.fill('.form-control.name-input', 'Test');  // More classes
  await page.click('div > form > button:last-child');  // Structure-based
  await page.click('button:has-text("Create Bot")');  // Text might change

  // Breaks if:
  // - CSS classes renamed
  // - DOM structure changes
  // - Button text translated
});
```

**Why it's problematic:**
- Class names change with styling
- Structure changes with refactoring
- Text changes with i18n
- Selector breaks, test fails

## Good Example

```typescript
import { test, expect } from '@playwright/test';

test.describe('Stable Selectors', () => {
  test('uses data-testid for interactive elements', async ({ page }) => {
    await page.goto('/bots');

    // data-testid selectors - never change accidentally
    await page.click('[data-testid="create-bot-button"]');
    await page.fill('[data-testid="bot-name-input"]', 'Test Bot');
    await page.selectOption('[data-testid="platform-select"]', 'line');
    await page.click('[data-testid="submit-button"]');

    await expect(page.locator('[data-testid="bot-card"]')).toBeVisible();
  });

  test('uses role selectors for standard elements', async ({ page }) => {
    await page.goto('/dashboard');

    // Role-based selectors - semantic and stable
    await page.getByRole('button', { name: 'Create' }).click();
    await page.getByRole('textbox', { name: 'Bot name' }).fill('Test');
    await page.getByRole('combobox', { name: 'Platform' }).selectOption('line');
    await page.getByRole('button', { name: 'Save' }).click();

    await expect(page.getByRole('alert')).toContainText('Created');
  });

  test('uses label text for form fields', async ({ page }) => {
    await page.goto('/settings');

    // Label-based - accessible and stable
    await page.getByLabel('Display Name').fill('Test Bot');
    await page.getByLabel('Description').fill('A test bot');
    await page.getByLabel('Enable notifications').check();
  });

  test('combines selectors when needed', async ({ page }) => {
    await page.goto('/bots');

    // Scoped selectors
    const botCard = page.locator('[data-testid="bot-card"]').first();
    await botCard.getByRole('button', { name: 'Edit' }).click();

    // Within a specific container
    const modal = page.locator('[data-testid="edit-modal"]');
    await modal.getByLabel('Name').fill('Updated Name');
    await modal.getByRole('button', { name: 'Save' }).click();
  });

  test('uses text content carefully', async ({ page }) => {
    await page.goto('/dashboard');

    // Text selectors - only for content that won't change
    await expect(page.getByText('Dashboard')).toBeVisible();

    // Avoid for buttons/actions that might be translated
    // Bad: page.click('text=Create Bot')
    // Good: page.click('[data-testid="create-bot-button"]')
  });
});

// React component with data-testid
// <Button data-testid="create-bot-button">Create Bot</Button>
// <Input data-testid="bot-name-input" label="Bot Name" />
// <Select data-testid="platform-select" label="Platform">
```

**Why it's better:**
- Survives CSS changes
- Survives structure changes
- Explicit test contract
- Easier to maintain

## Test Coverage

| Selector Type | When to Use |
|---------------|------------|
| data-testid | Interactive elements |
| getByRole | Standard UI patterns |
| getByLabel | Form fields |
| getByText | Static content |
| Scoped locators | Multiple similar elements |

## Run Command

```bash
# Run with codegen to explore selectors
npx playwright codegen https://app.botjao.com
```

## Project-Specific Notes

**BotFacebook Test ID Conventions:**

```tsx
// Component with test IDs
export function BotCard({ bot }: { bot: Bot }) {
  return (
    <div data-testid="bot-card" data-bot-id={bot.id}>
      <h3 data-testid="bot-name">{bot.name}</h3>
      <Badge data-testid="bot-platform">{bot.platform}</Badge>

      <div data-testid="bot-actions">
        <Button data-testid="edit-bot-button">Edit</Button>
        <Button data-testid="delete-bot-button" variant="destructive">
          Delete
        </Button>
      </div>

      <div data-testid="bot-stats">
        <span data-testid="conversation-count">
          {bot.conversationsCount} conversations
        </span>
      </div>
    </div>
  );
}

// Test using these IDs
test('displays bot information', async ({ page }) => {
  await page.goto('/dashboard');

  const firstBot = page.locator('[data-testid="bot-card"]').first();

  await expect(firstBot.locator('[data-testid="bot-name"]')).toContainText('My Bot');
  await expect(firstBot.locator('[data-testid="bot-platform"]')).toContainText('LINE');

  // Click edit on specific bot
  await firstBot.locator('[data-testid="edit-bot-button"]').click();
});

// Convention: {element-type}-{purpose}-{modifier}
// Examples:
// - create-bot-button
// - bot-name-input
// - platform-select
// - delete-confirm-modal
// - error-message
// - loading-spinner
```
