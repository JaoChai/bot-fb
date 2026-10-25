import { test as base, expect } from '@playwright/test'
import {
  bot,
  conversation,
  conversationsListResponse,
  dashboardBot,
  flow,
  loginResponse,
  messagesListResponse,
  order,
  orderSummary,
  pagination,
  productBreakdown,
  productStock,
  user,
  token,
} from './mock-data'

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
  // Each entry is "METHOD /pathname" of an /api call no explicit mock handled.
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
      // Catch-all first (lowest priority), FAIL-CLOSED: anything not mocked
      // explicitly is recorded in unmockedCalls and answered 501 (never an
      // empty-success body), and the teardown below fails the test. A page
      // that calls a wrong or new endpoint can therefore no longer pass by
      // silently rendering an empty list.
      await page.route('**/api/**', (route) => {
        const request = route.request()
        const call = `${request.method()} ${new URL(request.url()).pathname}`
        unmockedCalls.push(call)
        return route.fulfill({
          status: 501,
          json: { message: `Unmocked API call in e2e: ${call}` },
        })
      })

      // Auth — login is the FLAT contract envelope (LoginResponse in
      // contracts/openapi.yaml): { message, user, token, token_type }, never
      // wrapped in { data }.
      await page.route('**/api/auth/login', (route) =>
        route.fulfill({ json: loginResponse }),
      )
      await page.route('**/api/auth/user', (route) =>
        route.fulfill({ json: { data: user } }),
      )

      // Dashboard needs summary OBJECTS (DashboardPage reads
      // data?.summary.messages_today — an array would throw), plus one bot so
      // the "การเชื่อมต่อล่าสุด" list is populated. Shapes = DashboardData /
      // CostAnalyticsData from src/types/api.ts.
      await page.route('**/api/dashboard/summary', (route) =>
        route.fulfill({
          json: {
            data: {
              summary: {
                total_bots: 1,
                active_bots: 1,
                total_conversations: 1,
                active_conversations: 1,
                messages_today: 1,
                messages_yesterday: 0,
                vip_customers: 0,
                vip_total_spent: 0,
              },
              bots: [dashboardBot],
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

      // Orders — read by the dashboard (useOrderSummary, useOrdersByProduct,
      // RecentOrdersPreview -> useOrders { per_page: 5 }) and by the Orders
      // page. `*` stops at `/`, so each glob matches its own endpoint plus
      // query string only (hooks/useOrders.ts builds ?bot_id=&per_page=...).
      await page.route('**/api/orders/summary*', (route) =>
        route.fulfill({ json: { data: orderSummary } }),
      )
      await page.route('**/api/orders/by-product*', (route) =>
        route.fulfill({ json: { data: [productBreakdown] } }),
      )
      await page.route('**/api/orders*', (route) =>
        route.fulfill({ json: { data: [order], meta: pagination } }),
      )
      // Dashboard stock toggle (useProductStocks) — { data: ProductStock[] }.
      await page.route('**/api/product-stocks', (route) =>
        route.fulfill({ json: { data: [productStock] } }),
      )

      // Bots list (BotsPage / ChatPage / FlowEditorPage sidebars).
      // PaginatedResponse<Bot> from useKnowledgeBase.ts useBots().
      await page.route('**/api/bots', (route) =>
        route.fulfill({ json: { data: [bot], meta: pagination } }),
      )
      // Single bot detail — ApiResponse<Bot> (useConnections.ts).
      await page.route('**/api/bots/1', (route) =>
        route.fulfill({ json: { data: bot } }),
      )
      // Bot settings — ApiResponse<BotSettings> (useBotSettings.ts); minimal
      // object is fine, consumers null-check their fields.
      await page.route('**/api/bots/1/settings', (route) =>
        route.fulfill({ json: { data: { id: 1, bot_id: 1 } } }),
      )

      // Flows — PaginatedResponse<Flow> (useFlows.ts useFlows) and
      // ApiResponse<Flow> (useFlow) for the editor detail.
      await page.route('**/api/bots/1/flows', (route) =>
        route.fulfill({ json: { data: [flow], meta: pagination } }),
      )
      await page.route('**/api/bots/1/flows/1', (route) =>
        route.fulfill({ json: { data: flow } }),
      )
      // Knowledge bases for the editor's Knowledge tab —
      // apiGet<{ data: KnowledgeBaseListItem[] }> (useKnowledgeBase.ts).
      await page.route('**/api/knowledge-bases', (route) =>
        route.fulfill({ json: { data: [] } }),
      )

      // Chat — conversations list is ConversationsResponse
      // (hooks/chat/useConversationList.ts): { data, meta } with
      // status_counts. A `conversations*` glob stops at `/`, so it matches
      // the list URL's query string but NOT /tags, /notes, /mark-as-read,
      // /messages (each needs a different shape — narrow routes below win by
      // being registered later).
      await page.route('**/api/bots/1/conversations*', (route) =>
        route.fulfill({ json: conversationsListResponse }),
      )
      await page.route('**/api/bots/1/conversations/1/messages*', (route) =>
        route.fulfill({ json: messagesListResponse }),
      )
      // useBotTags → TagsResponse { data: string[] }.
      await page.route('**/api/bots/1/conversations/tags', (route) =>
        route.fulfill({ json: { data: [] } }),
      )
      // useNotes → NotesResponse { data: ConversationNote[] }.
      await page.route('**/api/bots/1/conversations/1/notes', (route) =>
        route.fulfill({ json: { data: [] } }),
      )
      // useMarkAsRead → ConversationResponse { data: Conversation }.
      await page.route('**/api/bots/1/conversations/1/mark-as-read', (route) =>
        route.fulfill({ json: { data: conversation } }),
      )

      // Block realtime so no websocket reaches a real host.
      await page.route(/pusher|reverb|sockjs/, (route) => route.abort())
      await provide()

      // Teardown (runs after the test body, also on failure): every API call
      // must have hit an explicit mock. Reports each distinct METHOD + path
      // with its hit count so the missing mock is obvious.
      const counts = new Map<string, number>()
      for (const call of unmockedCalls) {
        counts.set(call, (counts.get(call) ?? 0) + 1)
      }
      const report = [...counts].map(([call, n]) => `${call} (x${n})`)
      expect(
        report,
        'Unmocked API calls (add an explicit mock in e2e/fixtures.ts + mock-data.ts)',
      ).toEqual([])
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
