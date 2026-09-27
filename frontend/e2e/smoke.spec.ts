import { test, expect } from './fixtures'

// Smoke: the real login form flow (typed credentials -> submit -> dashboard
// heading) against a fully mocked API. Selectors verified against
// src/pages/auth/LoginPage.tsx; heading text against
// src/pages/DashboardPage.tsx (PageHeader title).
test('login page renders and signs in to the dashboard', async ({
  page,
  pageErrors,
}) => {
  await page.goto('/login')
  await page.getByPlaceholder('name@example.com').fill('e2e@example.com')
  await page.getByPlaceholder('รหัสผ่าน').fill('password')
  await page.getByRole('button', { name: 'เข้าสู่ระบบ' }).click()
  await expect(page).toHaveURL(/\/dashboard$/)
  await expect(page.getByRole('heading', { name: 'แดชบอร์ด' })).toBeVisible()
  expect(pageErrors).toEqual([])
})

test.describe('authenticated', () => {
  test('dashboard', async ({ page, loggedIn, pageErrors }) => {
    void loggedIn
    await page.goto('/dashboard')
    // PageHeader h1 title (src/pages/DashboardPage.tsx).
    await expect(page.getByRole('heading', { name: 'แดชบอร์ด' })).toBeVisible()
    expect(pageErrors).toEqual([])
  })

  test('bots list', async ({ page, loggedIn, pageErrors }) => {
    void loggedIn
    await page.goto('/bots')
    // PageHeader h1 title (src/pages/BotsPage.tsx).
    await expect(page.getByRole('heading', { name: 'การเชื่อมต่อ' })).toBeVisible()
    // The mocked bot renders as a list row heading (h3 in BotsPage).
    await expect(page.getByText('E2E Bot')).toBeVisible()
    expect(pageErrors).toEqual([])
  })

  test('chat opens a conversation', async ({ page, loggedIn, pageErrors }) => {
    void loggedIn
    await page.goto('/chat')
    // ChatPage auto-redirects to /chat?botId=1 (first bot), then the
    // conversation row renders (button in ConversationItem). On desktop the
    // first conversation is auto-selected; on mobile this click also flips
    // chatStore.showMobileChat to reveal the chat window — same control for
    // both projects.
    await page.getByRole('button', { name: /E2E Customer/ }).click()
    // The message bubble (LINEMessageBubble renders message.content in a <p>).
    await expect(page.getByText('สวัสดีครับ')).toBeVisible()
    expect(pageErrors).toEqual([])
  })

  test('flow editor opens and switches tabs', async ({ page, loggedIn, pageErrors }) => {
    void loggedIn
    // Real route shape: /flows/:flowId/edit with ?botId= query
    // (src/router.tsx; FlowEditorPage reads botId from searchParams).
    await page.goto('/flows/1/edit?botId=1')
    if (test.info().project.name !== 'mobile') {
      // The <h1> flow name only exists in the desktop layout; the mobile
      // layout shows the flow name in a <span> in its header instead.
      await expect(
        page.getByRole('heading', { level: 1, name: 'E2E Flow' }),
      ).toBeVisible()
    }
    // The tabs panel is rendered twice (desktop layout + mobile layout keep
    // shared state), so scope every assertion to the visible copy.
    const editorTabs = page
      .getByRole('button', { name: 'Knowledge', exact: true })
      .filter({ visible: true })
    // Editor tabs are plain nav <button>s (FlowEditorTabsPanel) with
    // class-based active state — no role="tab" / data-state. 'Knowledge' is
    // inactive at first.
    await expect(editorTabs).not.toHaveClass(/bg-accent(?!\/)/)
    await editorTabs.click()
    // The Knowledge panel description only renders once that tab is active,
    // which proves the switch.
    await expect(
      page
        .getByText('เอกสารที่ AI ใช้ค้นหาข้อมูลประกอบการตอบ')
        .filter({ visible: true }),
    ).toBeVisible()
    await expect(editorTabs).toHaveClass(/bg-accent(?!\/)/)
    expect(pageErrors).toEqual([])
  })
})
