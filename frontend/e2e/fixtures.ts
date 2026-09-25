import { test as base, expect } from '@playwright/test'
import { user, token } from './mock-data'

type Fixtures = {
  mockApi: void
  pageErrors: string[]
  unmockedCalls: string[]
  loggedIn: void
}

/**
 * Shared API mocks for e2e.
 *
 * Route priority: Playwright gives the most recently registered route
 * priority, so specific routes registered later (below or in a test)
 * override the catch-all.
 *
 * Response shapes follow src/types/api.ts. Note the login endpoint is FLAT
 * ({ user, token } = AuthResponse), matching what useAuth.ts consumes and
 * what the Laravel backend actually returns.
 */
// Fixture callbacks name the param `provide` instead of `use`: eslint's
// react-hooks/rules-of-hooks mistakes Playwright's `use` for React's use().
export const test = base.extend<Fixtures>({
  // Playwright requires a destructured first arg even when no fixtures are used.
  unmockedCalls: async ({}, provide) => {
    await provide([])
  },

  pageErrors: async ({ page }, provide) => {
    const errors: string[] = []
    page.on('pageerror', (e) => errors.push(e.message))
    await provide(errors)
  },

  mockApi: [
    async ({ page, unmockedCalls }, provide) => {
      // Catch-all first (lowest priority): anything not mocked explicitly
      // gets an empty ApiListResponse and is recorded in unmockedCalls.
      await page.route('**/api/**', (route) => {
        unmockedCalls.push(new URL(route.request().url()).pathname)
        return route.fulfill({ json: { data: [] } })
      })

      // Auth — login is a FLAT AuthResponse (api.ts), NOT wrapped in { data }.
      await page.route('**/api/auth/login', (route) =>
        route.fulfill({ json: { user, token } }),
      )
      await page.route('**/api/auth/user', (route) =>
        route.fulfill({ json: { data: user } }),
      )

      // Dashboard needs summary OBJECTS (DashboardPage reads
      // data?.summary.messages_today — an array would throw), plus an empty
      // bots list. Shapes = DashboardData / CostAnalyticsData from
      // src/types/api.ts.
      await page.route('**/api/dashboard/summary', (route) =>
        route.fulfill({
          json: {
            data: {
              summary: {
                total_bots: 0,
                active_bots: 0,
                total_conversations: 0,
                active_conversations: 0,
                messages_today: 0,
                messages_yesterday: 0,
                vip_customers: 0,
                vip_total_spent: 0,
              },
              bots: [],
            },
          },
        }),
      )
      await page.route('**/api/analytics/costs**', (route) =>
        route.fulfill({
          json: {
            data: {
              summary: {
                total_responses: 0,
                total_cost: 0,
                total_prompt_tokens: 0,
                total_completion_tokens: 0,
                avg_cost_per_response: 0,
                today_cost: 0,
                week_cost: 0,
                month_cost: 0,
              },
              by_model: [],
              time_series: [],
              by_bot: null,
              period: { from: '', to: '', group_by: 'day' },
            },
          },
        }),
      )

      // All other dashboard reads are null-safe with defaults, so the
      // catch-all { data: [] } is enough for them (orders summary,
      // orders list, product stocks).

      // Block realtime so no websocket reaches a real host.
      await page.route(/pusher|reverb|sockjs/, (route) => route.abort())
      await provide()
    },
    { auto: true },
  ],

  loggedIn: async ({ page }, provide) => {
    await page.addInitScript(
      ([u, t]) => {
        localStorage.setItem('auth_token', t as string)
        localStorage.setItem(
          'auth-storage',
          JSON.stringify({
            state: { user: u, token: t, isAuthenticated: true },
            version: 0,
          }),
        )
      },
      [user, token] as const,
    )
    await provide()
  },
})

export { expect }
