import { test, expect } from './fixtures'
import {
  emptyOrderSummary,
  emptySlipsResponse,
  pagination,
  slipsResponse,
} from './mock-data'

// Orders (/orders) and Slips (/slips) smoke journeys: populated list, empty
// state, server error. Orders populated = the fixture defaults (fixtures.ts;
// the dashboard reads the same endpoints). Only SlipsPage reads /api/slips,
// so every slips journey registers its own response. The empty/500 journeys
// re-register the endpoint inside the test (the most recently registered
// route wins over the fixture's).
//
// Error journeys: the app's QueryClient (src/lib/query.ts) retries a failed
// query 3 times with exponential backoff (1s + 2s + 4s) before the page can
// show its error state, so error assertions allow 15s instead of the 5s
// default. The expect timeout is raised per assertion; the test timeout and
// playwright.config.ts are untouched.
const ERROR_STATE_TIMEOUT = 15_000

const ORDERS_API = '**/api/orders*'
const ORDERS_SUMMARY_API = '**/api/orders/summary*'
const SLIPS_API = '**/api/slips*'

test.describe('orders page', () => {
  test('populated: the orders tab lists the known order row', async ({
    page,
    loggedIn,
    pageErrors,
  }) => {
    void loggedIn
    await page.goto('/orders')
    // PageHeader h1 (src/pages/OrdersPage.tsx); exact so the empty-state h3
    // "ยังไม่มีออเดอร์" can never satisfy it.
    await expect(
      page.getByRole('heading', { name: 'ออเดอร์', exact: true }),
    ).toBeVisible()
    // Overview tab (default) shows the summary card, not the empty state.
    await expect(page.getByText('ออเดอร์ทั้งหมด')).toBeVisible()
    await expect(
      page.getByRole('heading', { name: 'ยังไม่มีออเดอร์' }),
    ).toHaveCount(0)

    // Orders list tab (OrdersAnalytics): the order renders as one table row
    // "<date> | customer | "<product> x<qty>" | amount | status | note".
    await page.getByRole('tab', { name: 'รายการออเดอร์' }).click()
    const row = page.getByRole('row').filter({ hasText: 'E2E Product x2' })
    await expect(row).toBeVisible()
    await expect(row).toContainText('E2E Customer')
    await expect(row).toContainText('฿1,500')
    await expect(row).toContainText('สำเร็จ')
    expect(pageErrors).toEqual([])
  })

  test('empty: no orders shows the empty states', async ({
    page,
    loggedIn,
    pageErrors,
  }) => {
    void loggedIn
    await page.route(ORDERS_SUMMARY_API, (route) =>
      route.fulfill({ json: { data: emptyOrderSummary } }),
    )
    await page.route(ORDERS_API, (route) =>
      route.fulfill({ json: { data: [], meta: { ...pagination, total: 0 } } }),
    )
    await page.goto('/orders')
    // Overview tab empty state (total_orders === 0).
    await expect(
      page.getByRole('heading', { name: 'ยังไม่มีออเดอร์' }),
    ).toBeVisible()
    // Orders list tab empty state.
    await page.getByRole('tab', { name: 'รายการออเดอร์' }).click()
    await expect(
      page.getByRole('heading', { name: 'ไม่พบออเดอร์' }),
    ).toBeVisible()
    await expect(page.getByRole('row').filter({ hasText: 'E2E Product' })).toHaveCount(0)
    expect(pageErrors).toEqual([])
  })

  test('500: a failing summary request shows the error state, not an empty page', async ({
    page,
    loggedIn,
    pageErrors,
  }) => {
    void loggedIn
    await page.route(ORDERS_SUMMARY_API, (route) =>
      route.fulfill({ status: 500, json: { message: 'Server Error' } }),
    )
    await page.goto('/orders')
    await expect(
      page.getByText('เกิดข้อผิดพลาดในการโหลดข้อมูล'),
    ).toBeVisible({ timeout: ERROR_STATE_TIMEOUT })
    await expect(page.getByRole('button', { name: 'ลองใหม่' })).toBeVisible()
    // The error must not be masked as "no orders yet".
    await expect(
      page.getByRole('heading', { name: 'ยังไม่มีออเดอร์' }),
    ).toHaveCount(0)
    expect(pageErrors).toEqual([])
  })
})

test.describe('slips page', () => {
  test('populated: the slip table lists the known slip row', async ({
    page,
    loggedIn,
    pageErrors,
  }) => {
    void loggedIn
    await page.route(SLIPS_API, (route) =>
      route.fulfill({ json: slipsResponse }),
    )
    await page.goto('/slips')
    // PageHeader h1 title (src/pages/SlipsPage.tsx).
    await expect(
      page.getByRole('heading', { name: 'สลิป / การชำระเงิน' }),
    ).toBeVisible()
    // One row: time | customer | amount | trans_ref | account | status badge.
    const row = page.getByRole('row').filter({ hasText: 'E2ESLIP0001' })
    await expect(row).toBeVisible()
    await expect(row).toContainText('E2E Customer')
    await expect(row).toContainText('฿1,500.00')
    await expect(row).toContainText('ผ่าน')
    await expect(page.getByText('ยังไม่มีรายการสลิปวันนี้')).toHaveCount(0)
    expect(pageErrors).toEqual([])
  })

  test('empty: no slips today shows the empty state', async ({
    page,
    loggedIn,
    pageErrors,
  }) => {
    void loggedIn
    await page.route(SLIPS_API, (route) =>
      route.fulfill({ json: emptySlipsResponse }),
    )
    await page.goto('/slips')
    await expect(
      page.getByText('ยังไม่มีรายการสลิปวันนี้'),
    ).toBeVisible()
    await expect(page.getByRole('row').filter({ hasText: 'E2ESLIP0001' })).toHaveCount(0)
    expect(pageErrors).toEqual([])
  })

  test('500: a failing slips request shows the error state', async ({
    page,
    loggedIn,
    pageErrors,
  }) => {
    void loggedIn
    await page.route(SLIPS_API, (route) =>
      route.fulfill({ status: 500, json: { message: 'Server Error' } }),
    )
    await page.goto('/slips')
    // ErrorState renders role="alert" with the title "เกิดข้อผิดพลาด".
    const alert = page.getByRole('alert')
    await expect(alert).toBeVisible({ timeout: ERROR_STATE_TIMEOUT })
    await expect(alert).toContainText('เกิดข้อผิดพลาด')
    // The error must not be masked as an empty list.
    await expect(page.getByText('ยังไม่มีรายการสลิปวันนี้')).toHaveCount(0)
    expect(pageErrors).toEqual([])
  })
})
