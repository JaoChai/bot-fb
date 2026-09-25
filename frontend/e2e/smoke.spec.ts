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
