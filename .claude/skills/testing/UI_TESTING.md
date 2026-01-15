# UI Testing Guide

## Responsive Testing

### Device Breakpoints

```typescript
import { test, expect, devices } from '@playwright/test';

const breakpoints = [
  { name: 'mobile', width: 375, height: 667 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'laptop', width: 1280, height: 800 },
  { name: 'desktop', width: 1920, height: 1080 },
];

for (const bp of breakpoints) {
  test(`dashboard renders correctly on ${bp.name}`, async ({ page }) => {
    await page.setViewportSize({ width: bp.width, height: bp.height });
    await page.goto('/dashboard');

    await expect(page).toHaveScreenshot(`dashboard-${bp.name}.png`);
  });
}
```

### Mobile-Specific Testing

```typescript
import { test, expect, devices } from '@playwright/test';

test.use(devices['iPhone 13']);

test('mobile menu opens on tap', async ({ page }) => {
  await page.goto('/');

  // Menu should be hidden
  await expect(page.locator('nav')).not.toBeVisible();

  // Tap hamburger menu
  await page.tap('[aria-label="Menu"]');

  // Menu should be visible
  await expect(page.locator('nav')).toBeVisible();
});
```

### Touch Gestures

```typescript
test('swipe to delete', async ({ page }) => {
  await page.goto('/messages');

  const item = page.locator('.message-item').first();
  const box = await item.boundingBox();

  if (box) {
    // Swipe left
    await page.mouse.move(box.x + box.width - 10, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + 10, box.y + box.height / 2);
    await page.mouse.up();
  }

  await expect(page.locator('.delete-button')).toBeVisible();
});
```

## Accessibility Testing

### Automated A11y Checks

```typescript
import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

test.describe('Accessibility', () => {
  test('dashboard has no violations', async ({ page }) => {
    await page.goto('/dashboard');

    const accessibilityScanResults = await new AxeBuilder({ page }).analyze();

    expect(accessibilityScanResults.violations).toEqual([]);
  });

  test('login form has no violations', async ({ page }) => {
    await page.goto('/login');

    const results = await new AxeBuilder({ page })
      .include('form')
      .analyze();

    expect(results.violations).toEqual([]);
  });
});
```

### Manual A11y Checks

```typescript
test('form inputs have labels', async ({ page }) => {
  await page.goto('/login');

  const emailInput = page.locator('[name="email"]');
  const passwordInput = page.locator('[name="password"]');

  // Check for associated labels
  const emailLabel = page.locator('label[for="email"]');
  const passwordLabel = page.locator('label[for="password"]');

  await expect(emailLabel).toBeVisible();
  await expect(passwordLabel).toBeVisible();
});

test('buttons have accessible names', async ({ page }) => {
  await page.goto('/dashboard');

  const buttons = await page.locator('button').all();

  for (const button of buttons) {
    const name = await button.getAttribute('aria-label') ||
                 await button.textContent();
    expect(name).toBeTruthy();
  }
});
```

### Keyboard Navigation

```typescript
test('can navigate form with keyboard', async ({ page }) => {
  await page.goto('/login');

  // Tab to email input
  await page.keyboard.press('Tab');
  await expect(page.locator('[name="email"]')).toBeFocused();

  // Tab to password
  await page.keyboard.press('Tab');
  await expect(page.locator('[name="password"]')).toBeFocused();

  // Tab to submit
  await page.keyboard.press('Tab');
  await expect(page.locator('button[type="submit"]')).toBeFocused();
});

test('modal can be closed with Escape', async ({ page }) => {
  await page.goto('/dashboard');
  await page.click('text=Create Bot');

  await expect(page.locator('[role="dialog"]')).toBeVisible();

  await page.keyboard.press('Escape');

  await expect(page.locator('[role="dialog"]')).not.toBeVisible();
});
```

### Focus Management

```typescript
test('modal traps focus', async ({ page }) => {
  await page.goto('/dashboard');
  await page.click('text=Create Bot');

  const modal = page.locator('[role="dialog"]');
  await expect(modal).toBeVisible();

  // First focusable element should be focused
  const firstInput = modal.locator('input').first();
  await expect(firstInput).toBeFocused();

  // Tab through all focusable elements
  const focusableElements = await modal.locator(
    'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
  ).all();

  for (let i = 0; i < focusableElements.length + 1; i++) {
    await page.keyboard.press('Tab');
  }

  // Focus should loop back to first element
  await expect(firstInput).toBeFocused();
});
```

## Visual Regression Testing

### Snapshot Testing

```typescript
test('component snapshots', async ({ page }) => {
  await page.goto('/components');

  // Full page
  await expect(page).toHaveScreenshot('components-page.png');

  // Specific component
  await expect(page.locator('.button-primary')).toHaveScreenshot('button-primary.png');

  // With mask (hide dynamic content)
  await expect(page).toHaveScreenshot('page-masked.png', {
    mask: [page.locator('.timestamp')],
  });
});
```

### Animation Handling

```typescript
test('handles animations', async ({ page }) => {
  await page.goto('/dashboard');

  // Wait for animations to complete
  await page.waitForTimeout(1000);

  // Or disable animations
  await page.addStyleTag({
    content: `
      *, *::before, *::after {
        animation-duration: 0s !important;
        transition-duration: 0s !important;
      }
    `,
  });

  await expect(page).toHaveScreenshot();
});
```

## Component Testing

### Testing Interactive States

```typescript
test('button states', async ({ page }) => {
  await page.goto('/components/button');

  const button = page.locator('.button-primary');

  // Default state
  await expect(button).toHaveScreenshot('button-default.png');

  // Hover state
  await button.hover();
  await expect(button).toHaveScreenshot('button-hover.png');

  // Focus state
  await button.focus();
  await expect(button).toHaveScreenshot('button-focus.png');

  // Active state
  await button.click({ force: true, noWaitAfter: true });
  await expect(button).toHaveScreenshot('button-active.png');

  // Disabled state
  const disabledButton = page.locator('.button-disabled');
  await expect(disabledButton).toHaveScreenshot('button-disabled.png');
});
```

### Form Validation UI

```typescript
test('shows validation errors', async ({ page }) => {
  await page.goto('/login');

  // Submit empty form
  await page.click('button[type="submit"]');

  // Check error styling
  const emailInput = page.locator('[name="email"]');
  await expect(emailInput).toHaveClass(/error|invalid/);

  // Check error message
  await expect(page.locator('.error-message')).toBeVisible();
});
```

## Dark Mode Testing

```typescript
test.describe('Dark Mode', () => {
  test('respects system preference', async ({ page }) => {
    // Set dark mode preference
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/');

    await expect(page.locator('html')).toHaveClass(/dark/);
  });

  test('toggle changes theme', async ({ page }) => {
    await page.goto('/');

    await page.click('[aria-label="Toggle dark mode"]');

    await expect(page.locator('html')).toHaveClass(/dark/);

    // Compare screenshots
    await expect(page).toHaveScreenshot('dark-mode.png');
  });
});
```

## Running UI Tests

```bash
# Visual tests
npx playwright test --grep "screenshot"

# Update snapshots
npx playwright test --update-snapshots

# Accessibility tests
npx playwright test tests/e2e/accessibility/

# Responsive tests
npx playwright test --grep "mobile|tablet|desktop"
```

## Best Practices

### DO
- Test at multiple breakpoints
- Include accessibility tests
- Use semantic selectors (role, label)
- Test keyboard navigation
- Handle animations in snapshots

### DON'T
- Test pixel-perfect layouts
- Ignore accessibility violations
- Skip mobile testing
- Hardcode viewport sizes
- Test every CSS property
