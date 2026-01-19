---
id: e2e-005-page-objects
title: Use Page Object Pattern for Complex Flows
impact: MEDIUM
impactDescription: "Duplicated selectors and actions across tests"
category: e2e
tags: [e2e, playwright, page-object, organization]
relatedRules: [e2e-004-selectors, e2e-001-critical-paths]
---

## Why This Matters

Page Objects encapsulate page-specific selectors and actions. When UI changes, you update one place instead of every test.

## Bad Example

```typescript
// Same selectors duplicated in every test
test('creates bot', async ({ page }) => {
  await page.goto('/dashboard');
  await page.click('[data-testid="create-bot-button"]');
  await page.fill('[data-testid="bot-name-input"]', 'Test');
  await page.selectOption('[data-testid="platform-select"]', 'line');
  await page.click('[data-testid="submit-button"]');
});

test('edits bot', async ({ page }) => {
  await page.goto('/dashboard');
  await page.click('[data-testid="bot-card"]');
  await page.click('[data-testid="edit-button"]');
  await page.fill('[data-testid="bot-name-input"]', 'Updated');  // Same selector
  await page.click('[data-testid="submit-button"]');  // Same selector
});

test('deletes bot', async ({ page }) => {
  await page.goto('/dashboard');
  await page.click('[data-testid="bot-card"]');  // Same selector
  await page.click('[data-testid="delete-button"]');
});
```

**Why it's problematic:**
- Selector changes = update many tests
- Duplicated navigation logic
- Hard to maintain
- No reusable actions

## Good Example

```typescript
// pages/DashboardPage.ts
import { Page, Locator, expect } from '@playwright/test';

export class DashboardPage {
  readonly page: Page;
  readonly createBotButton: Locator;
  readonly botCards: Locator;
  readonly emptyState: Locator;

  constructor(page: Page) {
    this.page = page;
    this.createBotButton = page.locator('[data-testid="create-bot-button"]');
    this.botCards = page.locator('[data-testid="bot-card"]');
    this.emptyState = page.locator('[data-testid="empty-state"]');
  }

  async goto() {
    await this.page.goto('/dashboard');
    await expect(this.page).toHaveURL('/dashboard');
  }

  async createBot() {
    await this.createBotButton.click();
    return new BotFormPage(this.page);
  }

  async getBotCount() {
    return await this.botCards.count();
  }

  async selectBot(name: string) {
    await this.botCards.filter({ hasText: name }).click();
    return new BotDetailPage(this.page);
  }
}

// pages/BotFormPage.ts
export class BotFormPage {
  readonly page: Page;
  readonly nameInput: Locator;
  readonly platformSelect: Locator;
  readonly submitButton: Locator;
  readonly errorMessage: Locator;

  constructor(page: Page) {
    this.page = page;
    this.nameInput = page.locator('[data-testid="bot-name-input"]');
    this.platformSelect = page.locator('[data-testid="platform-select"]');
    this.submitButton = page.locator('[data-testid="submit-button"]');
    this.errorMessage = page.locator('[data-testid="error-message"]');
  }

  async fillForm(name: string, platform: string) {
    await this.nameInput.fill(name);
    await this.platformSelect.selectOption(platform);
  }

  async submit() {
    await this.submitButton.click();
  }

  async createBot(name: string, platform: string) {
    await this.fillForm(name, platform);
    await this.submit();
    // Wait for redirect back to dashboard
    await expect(this.page).toHaveURL('/dashboard');
    return new DashboardPage(this.page);
  }
}

// pages/BotDetailPage.ts
export class BotDetailPage {
  readonly page: Page;
  readonly editButton: Locator;
  readonly deleteButton: Locator;
  readonly botName: Locator;

  constructor(page: Page) {
    this.page = page;
    this.editButton = page.locator('[data-testid="edit-button"]');
    this.deleteButton = page.locator('[data-testid="delete-button"]');
    this.botName = page.locator('[data-testid="bot-name"]');
  }

  async edit() {
    await this.editButton.click();
    return new BotFormPage(this.page);
  }

  async delete() {
    await this.deleteButton.click();
    await this.page.locator('[data-testid="confirm-delete"]').click();
    return new DashboardPage(this.page);
  }
}

// Clean tests using Page Objects
import { test, expect } from '@playwright/test';
import { DashboardPage } from './pages/DashboardPage';
import { LoginPage } from './pages/LoginPage';

test.describe('Bot Management', () => {
  let dashboard: DashboardPage;

  test.beforeEach(async ({ page }) => {
    const login = new LoginPage(page);
    await login.goto();
    await login.login('test@example.com', 'password');

    dashboard = new DashboardPage(page);
    await dashboard.goto();
  });

  test('creates new bot', async ({ page }) => {
    const form = await dashboard.createBot();
    const newDashboard = await form.createBot('My New Bot', 'line');

    await expect(newDashboard.botCards.filter({ hasText: 'My New Bot' })).toBeVisible();
  });

  test('edits existing bot', async ({ page }) => {
    const botDetail = await dashboard.selectBot('Test Bot');
    const form = await botDetail.edit();
    await form.fillForm('Updated Bot', 'telegram');
    await form.submit();

    await expect(page.locator('text=Updated Bot')).toBeVisible();
  });

  test('deletes bot', async ({ page }) => {
    const initialCount = await dashboard.getBotCount();

    const botDetail = await dashboard.selectBot('Bot to Delete');
    await botDetail.delete();

    expect(await dashboard.getBotCount()).toBe(initialCount - 1);
  });
});
```

**Why it's better:**
- Single place for selectors
- Reusable actions
- Clean test code
- Easy maintenance

## Test Coverage

| Component | Page Object |
|-----------|-------------|
| Login | LoginPage |
| Dashboard | DashboardPage |
| Bot form | BotFormPage |
| Bot detail | BotDetailPage |
| Chat | ChatPage |

## Run Command

```bash
# Run tests with page objects
npx playwright test --project=chromium
```

## Project-Specific Notes

**BotFacebook Page Objects:**

```typescript
// pages/index.ts - Export all page objects
export { LoginPage } from './LoginPage';
export { DashboardPage } from './DashboardPage';
export { BotFormPage } from './BotFormPage';
export { BotDetailPage } from './BotDetailPage';
export { ChatPage } from './ChatPage';
export { KnowledgeBasePage } from './KnowledgeBasePage';

// pages/ChatPage.ts
export class ChatPage {
  readonly page: Page;
  readonly messageInput: Locator;
  readonly sendButton: Locator;
  readonly messages: Locator;
  readonly typingIndicator: Locator;

  constructor(page: Page) {
    this.page = page;
    this.messageInput = page.locator('[data-testid="message-input"]');
    this.sendButton = page.locator('[data-testid="send-button"]');
    this.messages = page.locator('[data-testid="message"]');
    this.typingIndicator = page.locator('[data-testid="typing-indicator"]');
  }

  async sendMessage(text: string) {
    await this.messageInput.fill(text);
    await this.sendButton.click();
    await expect(this.typingIndicator).toBeVisible();
    await expect(this.typingIndicator).not.toBeVisible({ timeout: 30000 });
  }

  async getLastResponse() {
    const assistantMessages = this.messages.filter({ hasText: /^assistant/i });
    return await assistantMessages.last().textContent();
  }
}
```
