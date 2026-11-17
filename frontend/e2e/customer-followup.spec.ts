import { test, expect } from './fixtures'
import {
  conversation,
  message as messageFixture,
  messagesListResponse,
  pagination,
} from './mock-data'
import type { Page, TestInfo } from '@playwright/test'

// Customer follow-up settings journeys (F1a, D128). Pattern =
// outage-settings.spec.ts: a method-aware settings mock over the shared
// fail-closed fixtures, four width/theme combos, shot() attachments.
//
// Rule numbers here are SYNTHETIC (5/30/9/12, 09:30–21:15) — never the
// production values — so a copy of a production number into the component
// would fail the journey (D127).
//
// Every journey asserts the follow-up tab's layout health: no horizontal
// overflow, the active tab button fully inside the visible tab bar, the
// sticky save bar below the last rule row, no page errors, and no unmocked
// API call (fail-closed teardown in fixtures.ts).

const FOLLOW_UP_TAB = 'ตามลูกค้า'

const COMBOS = [
  { width: 375, height: 812, theme: 'light' },
  { width: 375, height: 812, theme: 'dark' },
  { width: 1280, height: 800, theme: 'light' },
  { width: 1280, height: 800, theme: 'dark' },
] as const

const DEFAULTS = {
  outage_recovery_message_th: 'E2E-DEF-TH-กลับมาแล้ว',
  outage_recovery_message_en: 'E2E-DEF-EN-back online',
  outage_down_message: 'E2E-DEF-DOWN-ขออภัย',
}

const RULES = {
  payment_wait_hours: 5,
  sale_wait_hours: 30,
  customer_gap_days: 9,
  daily_draft_cap: 12,
  approval_ttl_hours: 6,
  send_start: '09:30',
  send_end: '21:15',
  timezone: 'Asia/Bangkok',
}

function capabilities(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    supported: true,
    telegram_ready: true,
    automatic_allowed: true,
    blocked_reason: null,
    rules: { ...RULES },
    ...overrides,
  }
}

// followUp === undefined → the response carries NONE of the four keys, as the
// production API does before the backend ships (D123).
function initialSettings(followUp?: Record<string, unknown>): Record<string, unknown> {
  const base: Record<string, unknown> = {
    daily_message_limit: 500,
    per_user_limit: 30,
    outage_recovery_enabled: true,
    outage_recovery_message_th: null,
    outage_recovery_message_en: null,
    outage_down_message: null,
    outage_defaults: { ...DEFAULTS },
  }
  if (followUp === undefined) return base
  return { ...base, ...followUp }
}

interface PutFailure {
  status: number
  errors?: Record<string, unknown>
}

interface SettingsHandle {
  puts: Record<string, unknown>[]
  server: Record<string, unknown>
}

async function registerFollowUpSettings(
  page: Page,
  initial: Record<string, unknown>,
  failure?: PutFailure,
): Promise<SettingsHandle> {
  const state: SettingsHandle = { puts: [], server: { ...initial } }
  await page.route('**/api/bots/1/settings', async (route) => {
    if (route.request().method() === 'PUT') {
      const body = route.request().postDataJSON() as Record<string, unknown>
      state.puts.push(body)
      if (failure) {
        await route.fulfill({
          status: failure.status,
          json: { message: 'The given data was invalid.', errors: failure.errors ?? {} },
        })
        return
      }
      state.server = { ...state.server, ...body }
      await route.fulfill({ json: { data: { ...state.server } } })
      return
    }
    await route.fulfill({ json: { data: { ...state.server } } })
  })
  return state
}

async function setViewportAndTheme(page: Page, combo: (typeof COMBOS)[number]) {
  await page.setViewportSize({ width: combo.width, height: combo.height })
  await page.addInitScript(([theme]) => {
    localStorage.setItem('ui-storage', JSON.stringify({ state: { theme }, version: 0 }))
  }, [combo.theme])
}

async function shot(page: Page, testInfo: TestInfo, name: string) {
  const path = testInfo.outputPath(`${name}.png`)
  await page.screenshot({ path })
  await testInfo.attach(name, { path, contentType: 'image/png' })
}

async function openFollowUpTab(
  page: Page,
  combo: (typeof COMBOS)[number],
  initial: Record<string, unknown>,
  failure?: PutFailure,
): Promise<SettingsHandle> {
  await setViewportAndTheme(page, combo)
  const state = await registerFollowUpSettings(page, initial, failure)
  await page.goto('/bots/1/settings')
  // Theme persisted in ui-storage wins over colorScheme emulation: assert the root class.
  await expect(page.locator('html')).toHaveClass(new RegExp(combo.theme))
  await expect(page.getByRole('heading', { name: 'ตั้งค่าบอท' })).toBeVisible()
  await page.getByRole('button', { name: FOLLOW_UP_TAB }).click()
  await expect(page.getByRole('button', { name: FOLLOW_UP_TAB })).toHaveClass(/bg-accent/)
  return state
}

async function assertFollowUpHealth(page: Page, hasRules: boolean) {
  const overflow = await page.evaluate(() => ({
    sw: document.documentElement.scrollWidth,
    cw: document.documentElement.clientWidth,
  }))
  expect(
    overflow.sw,
    `document scrollWidth ${overflow.sw} must not exceed clientWidth ${overflow.cw}`,
  ).toBeLessThanOrEqual(overflow.cw)

  // The active tab button must sit fully inside the visible part of the tab
  // bar (the nav scrolls horizontally on phones). Filter to THE nav that
  // holds the tab buttons — the page also has a sidebar and a breadcrumb nav.
  const nav = page
    .locator('nav')
    .filter({ has: page.getByRole('button', { name: FOLLOW_UP_TAB }) })
  const active = page.getByRole('button', { name: FOLLOW_UP_TAB })
  const navBox = await nav.boundingBox()
  const btnBox = await active.boundingBox()
  expect(navBox).not.toBeNull()
  expect(btnBox).not.toBeNull()
  expect(btnBox!.x).toBeGreaterThanOrEqual(navBox!.x - 1)
  expect(btnBox!.x + btnBox!.width).toBeLessThanOrEqual(navBox!.x + navBox!.width + 1)

  // The sticky save bar must not cover the last rule row (or, when the rules
  // panel is absent, the mode rows). scroll-mb-24 sits on the ul/fieldset, and
  // scrollIntoView honors the TARGET's own margin — so scroll that container
  // and then measure its last row.
  let rowBox: { y: number; height: number } | null
  if (hasRules) {
    const list = page.getByRole('list').filter({ hasText: 'ห้องที่ปิดบอท และลูกค้าที่จ่ายแล้ว' })
    await list.evaluate((el) => el.scrollIntoView({ block: 'end', behavior: 'instant' }))
    const lastRow = page
      .getByRole('listitem')
      .filter({ hasText: 'ห้องที่ปิดบอท และลูกค้าที่จ่ายแล้ว' })
    rowBox = await lastRow.boundingBox()
  } else {
    const fieldset = page.locator('fieldset')
    await fieldset.evaluate((el) => el.scrollIntoView({ block: 'end', behavior: 'instant' }))
    const lastRow = page.getByTestId('follow-up-mode-automatic-row')
    rowBox = await lastRow.boundingBox()
  }
  const save = await page.getByRole('button', { name: 'บันทึกตั้งค่า' }).boundingBox()
  expect(rowBox).not.toBeNull()
  expect(save).not.toBeNull()
  expect(
    rowBox!.y + rowBox!.height,
    'save bar must sit below the last row, not on top of it',
  ).toBeLessThanOrEqual(save!.y + 1)
}

test.describe(`customer follow-up settings journeys`, () => {
  for (const combo of COMBOS) {
    test.describe(`${combo.width}-${combo.theme}`, () => {
      test('ready_bot_switch_on_saves_and_survives_reload', async ({
        page,
        loggedIn,
        pageErrors,
      }, testInfo) => {
        void loggedIn
        const state = await openFollowUpTab(
          page,
          combo,
          initialSettings({
            follow_up_enabled: false,
            follow_up_mode: 'approve',
            follow_up_started_at: null,
            follow_up_capabilities: capabilities(),
          }),
        )

        // Default-off, rules rendered from the mocked capability (synthetic numbers)
        await expect(page.getByRole('switch', { name: 'เปิดตามลูกค้า' })).not.toBeChecked()
        await expect(page.getByText('ตาม 2 กลุ่ม')).toBeVisible()
        await expect(page.getByText('ค้างจ่าย 5 ชั่วโมง · ลังเล 30 ชั่วโมง')).toBeVisible()
        await expect(page.getByText('ส่งเฉพาะ 09:30–21:15')).toBeVisible()
        await expect(page.getByText('ห่างกันอย่างน้อย 9 วัน')).toBeVisible()
        await expect(page.getByText('ร่างไม่เกิน 12 ข้อความต่อวัน')).toBeVisible()
        await expect(page.getByText('ยังเปิดไม่ได้')).toHaveCount(0)
        await assertFollowUpHealth(page, true)
        await shot(page, testInfo, `followup-default-off-${combo.width}-${combo.theme}`)

        await page.getByRole('switch', { name: 'เปิดตามลูกค้า' }).click()
        await shot(page, testInfo, `followup-switched-on-${combo.width}-${combo.theme}`)

        const put = page.waitForResponse(
          (r) => r.url().includes('/settings') && r.request().method() === 'PUT',
        )
        await page.getByRole('button', { name: 'บันทึกตั้งค่า' }).click()
        await put
        expect(state.puts).toHaveLength(1)
        expect(state.puts[0].follow_up_enabled).toBe(true)
        expect(state.puts[0]).not.toHaveProperty('follow_up_mode')
        expect(state.puts[0]).not.toHaveProperty('follow_up_started_at')
        expect(state.puts[0]).not.toHaveProperty('follow_up_capabilities')

        // Reload proves persistence through the captured server state
        await page.reload()
        await page.getByRole('button', { name: FOLLOW_UP_TAB }).click()
        await expect(page.getByRole('switch', { name: 'เปิดตามลูกค้า' })).toBeChecked()
        expect(pageErrors).toEqual([])
      })

      test('blocked_bots_show_the_notice_and_never_send_a_follow_up_key', async ({
        page,
        loggedIn,
        pageErrors,
      }, testInfo) => {
        void loggedIn
        const state = await openFollowUpTab(
          page,
          combo,
          initialSettings({
            follow_up_enabled: false,
            follow_up_mode: 'approve',
            follow_up_started_at: null,
            follow_up_capabilities: capabilities({
              supported: false,
              telegram_ready: false,
              automatic_allowed: false,
              blocked_reason: 'telegram_missing',
            }),
          }),
        )

        await expect(page.getByText('ยังเปิดไม่ได้')).toBeVisible()
        await expect(page.getByText('บอทนี้ยังไม่ได้ตั้งห้อง Telegram สำหรับแจ้งเตือน')).toBeVisible()
        await expect(page.getByRole('switch', { name: 'เปิดตามลูกค้า' })).toBeDisabled()
        await assertFollowUpHealth(page, true)
        await shot(page, testInfo, `followup-blocked-telegram-${combo.width}-${combo.theme}`)

        // Same bot, non-LINE channel reason
        state.server = {
          ...state.server,
          follow_up_capabilities: capabilities({
            supported: false,
            telegram_ready: false,
            automatic_allowed: false,
            blocked_reason: 'line_only',
          }),
        }
        await page.reload()
        await page.getByRole('button', { name: FOLLOW_UP_TAB }).click()
        await expect(page.getByText('ตอนนี้ตามลูกค้าได้เฉพาะบอท LINE')).toBeVisible()
        await expect(page.getByRole('switch', { name: 'เปิดตามลูกค้า' })).toBeDisabled()
        await assertFollowUpHealth(page, true)
        await shot(page, testInfo, `followup-blocked-line-only-${combo.width}-${combo.theme}`)

        // Saving the untouched form never carries a follow-up key (D124)
        const put = page.waitForResponse(
          (r) => r.url().includes('/settings') && r.request().method() === 'PUT',
        )
        await page.getByRole('button', { name: 'บันทึกตั้งค่า' }).click()
        await put
        expect(state.puts).toHaveLength(1)
        expect('follow_up_enabled' in state.puts[0]).toBe(false)
        expect('follow_up_mode' in state.puts[0]).toBe(false)
        expect(pageErrors).toEqual([])
      })

      test('put_422_shows_the_reason_and_keeps_the_switch_value', async ({
        page,
        loggedIn,
        pageErrors,
      }, testInfo) => {
        void loggedIn
        const state = await openFollowUpTab(
          page,
          combo,
          initialSettings({
            follow_up_enabled: false,
            follow_up_mode: 'approve',
            follow_up_started_at: null,
            follow_up_capabilities: capabilities(),
          }),
          { status: 422, errors: { follow_up_enabled: ['bot_not_in_pilot'] } },
        )

        await page.getByRole('switch', { name: 'เปิดตามลูกค้า' }).click()
        const put = page.waitForResponse(
          (r) => r.url().includes('/settings') && r.request().method() === 'PUT',
        )
        await page.getByRole('button', { name: 'บันทึกตั้งค่า' }).click()
        await put
        expect(state.puts).toHaveLength(1)
        expect(state.puts[0].follow_up_enabled).toBe(true)

        await expect(page.getByTestId('follow-up-enabled-error')).toHaveText(
          'ช่วงทดลองเปิดใช้เฉพาะบอทที่กำหนด',
        )
        // The typed value survives the failed save and the page stays dirty
        await expect(page.getByRole('switch', { name: 'เปิดตามลูกค้า' })).toBeChecked()
        await expect(page.getByText('มีการเปลี่ยนแปลง', { exact: true })).toBeVisible()
        await assertFollowUpHealth(page, true)
        await shot(page, testInfo, `followup-error-422-${combo.width}-${combo.theme}`)
        expect(pageErrors).toEqual([])
      })

      test('other_tab_save_carries_no_follow_up_key', async ({
        page,
        loggedIn,
        pageErrors,
      }) => {
        void loggedIn
        const state = await openFollowUpTab(
          page,
          combo,
          initialSettings({
            follow_up_enabled: false,
            follow_up_mode: 'approve',
            follow_up_started_at: null,
            follow_up_capabilities: capabilities(),
          }),
        )

        await page.getByRole('button', { name: 'เมื่อบอทล่ม' }).click()
        const down = page.getByRole('textbox', { name: 'ข้อความ', exact: true })
        await down.fill('แก้เฉพาะตอนบอทล่ม')
        const put = page.waitForResponse(
          (r) => r.url().includes('/settings') && r.request().method() === 'PUT',
        )
        await page.getByRole('button', { name: 'บันทึกตั้งค่า' }).click()
        await put
        expect(state.puts).toHaveLength(1)
        expect(state.puts[0].outage_down_message).toBe('แก้เฉพาะตอนบอทล่ม')
        expect('follow_up_enabled' in state.puts[0]).toBe(false)
        expect('follow_up_mode' in state.puts[0]).toBe(false)
        expect('follow_up_started_at' in state.puts[0]).toBe(false)
        expect('follow_up_capabilities' in state.puts[0]).toBe(false)
        expect(pageErrors).toEqual([])
      })

      test('response_without_follow_up_keys_shows_unavailable_and_other_tab_saves', async ({
        page,
        loggedIn,
        pageErrors,
      }, testInfo) => {
        void loggedIn
        // Production API today: no follow-up key at all (D123)
        const state = await openFollowUpTab(page, combo, initialSettings())

        await expect(page.getByText('ยังเปิดไม่ได้')).toBeVisible()
        await expect(page.getByText('ยังโหลดข้อมูลตามลูกค้าไม่ได้ ลองโหลดหน้าใหม่')).toBeVisible()
        await expect(page.getByRole('switch', { name: 'เปิดตามลูกค้า' })).toBeDisabled()
        await expect(page.getByText('ระบบทำงานอย่างไร')).toHaveCount(0)
        await assertFollowUpHealth(page, false)
        await shot(page, testInfo, `followup-unavailable-${combo.width}-${combo.theme}`)

        // Another tab still saves
        await page.getByRole('button', { name: 'เมื่อบอทล่ม' }).click()
        const down = page.getByRole('textbox', { name: 'ข้อความ', exact: true })
        await down.fill('บันทึกได้ตามปกติ')
        const put = page.waitForResponse(
          (r) => r.url().includes('/settings') && r.request().method() === 'PUT',
        )
        await page.getByRole('button', { name: 'บันทึกตั้งค่า' }).click()
        await put
        expect(state.puts).toHaveLength(1)
        expect(state.puts[0].outage_down_message).toBe('บันทึกได้ตามปกติ')
        expect('follow_up_enabled' in state.puts[0]).toBe(false)
        expect('follow_up_mode' in state.puts[0]).toBe(false)
        expect(pageErrors).toEqual([])
      })
    })
  }
})

// ---------------------------------------------------------------------------
// F1b: the Telegram card link /chat?botId=1&conversationId=77 opens exactly
// that room. Room 77 is OUTSIDE the fixtures' list (which carries room 1
// only), so the room must come from the deep-link fetch, never from falling
// back to another room. All room-77 routes are registered here — fixtures.ts
// does not mock them and its fail-closed teardown fails the test on any
// unmocked request. Synthetic customer names only.
// ---------------------------------------------------------------------------

const LINK_ROOM_ID = 77
const LINK_ROOM_NAME = 'E2E Link Customer'

function linkRoom(unreadCount: number, isHandover = false): Record<string, unknown> {
  return {
    id: LINK_ROOM_ID,
    bot_id: 1,
    customer_profile_id: LINK_ROOM_ID,
    external_customer_id: 'e2e-link-customer',
    channel_type: 'line',
    status: 'active',
    is_handover: isHandover,
    assigned_user_id: null,
    memory_notes: null,
    tags: [],
    context: null,
    current_flow_id: null,
    message_count: 3,
    unread_count: unreadCount,
    last_message_at: '2026-01-02T00:00:00.000000Z',
    bot_auto_enable_at: null,
    bot_auto_enable_remaining_seconds: null,
    context_cleared_at: null,
    created_at: '2026-01-02T00:00:00.000000Z',
    updated_at: '2026-01-02T00:00:00.000000Z',
    customer_profile: {
      id: LINK_ROOM_ID,
      external_id: 'e2e-link-customer',
      channel_type: 'line',
      display_name: LINK_ROOM_NAME,
      picture_url: null,
      phone: null,
      email: null,
      interaction_count: 3,
    },
    assigned_user: null,
    last_message: null,
    needs_response: false,
  }
}

async function registerLinkRoomRoutes(
  page: Page,
  options: {
    roomId?: number
    status?: number
    unreadCount?: number
    markReadHits?: { count: number }
  } = {},
) {
  const { roomId = LINK_ROOM_ID, status, unreadCount = 1, markReadHits } = options
  // The detail request carries ?messages_limit=1, so the pattern must end in
  // '*' — a bare '/77' glob never matches a URL with a query string, and the
  // fail-closed catch-all would swallow the call. '*' does not cross '/', so
  // /conversations/77/mark-as-read and /notes still need their own routes.
  await page.route(`**/api/bots/1/conversations/${roomId}*`, async (route) => {
    if (status !== undefined) {
      await route.fulfill({ status, json: { message: 'E2E link room failure.' } })
      return
    }
    await route.fulfill({ json: { data: linkRoom(unreadCount) } })
  })
  await page.route(`**/api/bots/1/conversations/${LINK_ROOM_ID}/messages*`, (route) =>
    route.fulfill({ json: messagesListResponse }),
  )
  await page.route(`**/api/bots/1/conversations/${LINK_ROOM_ID}/notes`, (route) =>
    route.fulfill({ json: { data: [] } }),
  )
  await page.route(`**/api/bots/1/conversations/${LINK_ROOM_ID}/mark-as-read`, async (route) => {
    if (markReadHits) markReadHits.count += 1
    await route.fulfill({ json: { data: linkRoom(0) } })
  })
}

test.describe('customer follow-up room link journeys', () => {
  for (const combo of COMBOS) {
    test.describe(`link-${combo.width}-${combo.theme}`, () => {
      test('link_open_opens_room77_and_marks_read_once', async ({
        page,
        loggedIn,
        pageErrors,
      }, testInfo) => {
        void loggedIn
        await setViewportAndTheme(page, combo)
        // Review 1 (e2e L1): seed the chat store with room 1 as the stored
        // selection — the link must open room 77 without ever fetching or
        // mounting room 1 (chat-store persists only selectedConversationId).
        await page.addInitScript(() => {
          localStorage.setItem(
            'chat-store',
            JSON.stringify({ state: { selectedConversationId: 1 }, version: 0 }),
          )
        })
        const markReadHits = { count: 0 }
        await registerLinkRoomRoutes(page, { unreadCount: 1, markReadHits })
        // Review 1 (e2e L1): request observers. Registered LAST they run
        // FIRST; each only records and route.fallback()s into the mocks above
        // (never fulfills), so the serving behavior is unchanged.
        // `*` does not cross '/', so /conversations/77* sees exactly the room
        // GETs (?messages_limit=1), not /77/mark-as-read or /77/messages.
        const roomGets: string[] = []
        await page.route('**/api/bots/1/conversations/77*', async (route) => {
          roomGets.push(route.request().url())
          await route.fallback()
        })
        const room1MessageGets: string[] = []
        await page.route('**/api/bots/1/conversations/1/messages*', async (route) => {
          room1MessageGets.push(route.request().url())
          await route.fallback()
        })
        const writes: string[] = []
        await page.route('**/api/**', async (route) => {
          if (route.request().method() !== 'GET') {
            writes.push(`${route.request().method()} ${new URL(route.request().url()).pathname}`)
          }
          await route.fallback()
        })

        await page.goto('/chat?botId=1&conversationId=77')

        // The linked room opens — its customer name appears in the chat header
        // (scoped to the centre panel: at 1280 the info panel carries an h3
        // with the same name) — and room 1 ('E2E Customer') never shows there.
        await expect(
          page
            .getByTestId('chat-center-panel')
            .getByRole('heading', { name: LINK_ROOM_NAME }),
        ).toBeVisible()
        await expect(
          page
            .getByTestId('chat-center-panel')
            .getByRole('heading', { name: 'E2E Customer' }),
        ).toHaveCount(0)
        await expect.poll(() => markReadHits.count).toBe(1)
        expect(new URL(page.url()).searchParams.get('conversationId')).toBe('77')

        // Exactly ONE room GET opens the link (?messages_limit=1); the D133
        // fresh-copy subscription adds exactly ONE more after the mark-as-read
        // mutation succeeds. No room-1 messages request ever fires (the stored
        // selection must not mount), and mark-as-read is the ONLY write.
        await expect.poll(() => roomGets.length).toBe(2)
        for (const url of roomGets) {
          expect(url).toContain('/api/bots/1/conversations/77?messages_limit=1')
        }
        expect(room1MessageGets).toHaveLength(0)
        expect(writes).toEqual(['POST /api/bots/1/conversations/77/mark-as-read'])

        const overflow = await page.evaluate(() => ({
          sw: document.documentElement.scrollWidth,
          cw: document.documentElement.clientWidth,
        }))
        expect(overflow.sw).toBeLessThanOrEqual(overflow.cw)
        await shot(page, testInfo, `link-open-${combo.width}-${combo.theme}`)
        expect(pageErrors).toEqual([])
      })

      test('link_not_found_shows_panel_and_button_returns_to_list', async ({
        page,
        loggedIn,
        pageErrors,
      }, testInfo) => {
        void loggedIn
        await setViewportAndTheme(page, combo)
        await registerLinkRoomRoutes(page, { roomId: 404, status: 404 })

        await page.goto('/chat?botId=1&conversationId=404')

        await expect(page.getByText('เปิดห้องแชตนี้ไม่ได้')).toBeVisible()
        await expect(page.getByText('ห้องนี้อาจถูกลบ หรือบัญชีนี้ไม่มีสิทธิ์ดู')).toBeVisible()
        // No room opened: neither the linked customer nor any other room
        await expect(page.getByRole('heading', { name: LINK_ROOM_NAME })).toHaveCount(0)
        await expect(page.getByRole('heading', { name: 'E2E Customer' })).toHaveCount(0)
        // phone: the panel replaces the list; desktop: the list stays beside it
        const list = page.getByTestId('chat-list-panel')
        if (combo.width < 768) {
          await expect(list).toBeHidden()
        } else {
          await expect(list).toBeVisible()
        }
        await shot(page, testInfo, `link-error-notfound-${combo.width}-${combo.theme}`)

        await page.getByRole('button', { name: 'กลับไปที่รายการแชต' }).click()
        const url = new URL(page.url())
        expect(url.searchParams.get('conversationId')).toBeNull()
        expect(url.searchParams.get('botId')).toBe('1')
        // Normal page back: desktop auto-selects room 1, phone shows the list
        if (combo.width >= 768) {
          await expect(
            page
              .getByTestId('chat-center-panel')
              .getByRole('heading', { name: 'E2E Customer' }),
          ).toBeVisible()
        } else {
          await expect(list).toBeVisible()
        }
        expect(pageErrors).toEqual([])
      })

      // Review 1 (e2e L3): a non-numeric conversationId is a bad link — the
      // error panel shows and NO room request is ever made.
      test('link_invalid_param_shows_panel_and_makes_no_room_request', async ({
        page,
        loggedIn,
        pageErrors,
      }, testInfo) => {
        void loggedIn
        await setViewportAndTheme(page, combo)
        // Observer scoped to THIS link's room endpoint only (single segment
        // after /conversations/): /conversations/tags is a real endpoint the
        // page may call and must not pollute the count. `*` does not cross '/',
        // so /abc/messages or /abc/mark-as-read are separate routes anyway.
        const roomDetailGets: string[] = []
        await page.route('**/api/bots/1/conversations/abc*', async (route) => {
          roomDetailGets.push(route.request().url())
          await route.fallback()
        })
        const writes: string[] = []
        await page.route('**/api/**', async (route) => {
          if (route.request().method() !== 'GET') {
            writes.push(`${route.request().method()} ${new URL(route.request().url()).pathname}`)
          }
          await route.fallback()
        })

        await page.goto('/chat?botId=1&conversationId=abc')

        await expect(page.getByText('เปิดห้องแชตนี้ไม่ได้')).toBeVisible()
        await expect(page.getByText('ห้องนี้อาจถูกลบ หรือบัญชีนี้ไม่มีสิทธิ์ดู')).toBeVisible()
        await expect(page.getByRole('button', { name: 'กลับไปที่รายการแชต' })).toBeVisible()
        // no room of any kind rendered (the panel's own title IS a heading —
        // so assert room names only)
        await expect(page.getByRole('heading', { name: LINK_ROOM_NAME })).toHaveCount(0)
        await expect(
          page
            .getByTestId('chat-center-panel')
            .getByRole('heading', { name: 'E2E Customer' }),
        ).toHaveCount(0)
        // settle: a bad link never calls the room endpoint and writes nothing
        await page.waitForTimeout(300)
        expect(roomDetailGets).toHaveLength(0)
        expect(writes).toEqual([])
        // the parameter stays — the bot auto-redirect must not wipe it
        const url = new URL(page.url())
        expect(url.searchParams.get('conversationId')).toBe('abc')
        expect(url.searchParams.get('botId')).toBe('1')

        const overflow = await page.evaluate(() => ({
          sw: document.documentElement.scrollWidth,
          cw: document.documentElement.clientWidth,
        }))
        expect(overflow.sw).toBeLessThanOrEqual(overflow.cw)
        await shot(page, testInfo, `link-error-invalid-${combo.width}-${combo.theme}`)
        expect(pageErrors).toEqual([])
      })

      // Review 1 (e2e L4): the handover tap updates the header of a room that
      // is NOT in the loaded list — no reload, only the toggle POST plus the
      // D133 fresh-copy refetch.
      test('link_handover_toggle_updates_header_without_reload', async ({
        page,
        loggedIn,
        pageErrors,
      }, testInfo) => {
        void loggedIn
        await setViewportAndTheme(page, combo)
        let handoverOn = false
        const markReadHits = { count: 0 }
        // stateful room routes: the detail answers is_handover per the toggle
        await page.route('**/api/bots/1/conversations/77*', async (route) => {
          await route.fulfill({ json: { data: linkRoom(1, handoverOn) } })
        })
        await page.route('**/api/bots/1/conversations/77/messages*', (route) =>
          route.fulfill({ json: messagesListResponse }),
        )
        await page.route('**/api/bots/1/conversations/77/notes', (route) =>
          route.fulfill({ json: { data: [] } }),
        )
        await page.route('**/api/bots/1/conversations/77/mark-as-read', async (route) => {
          markReadHits.count += 1
          await route.fulfill({ json: { data: linkRoom(0, handoverOn) } })
        })
        await page.route('**/api/bots/1/conversations/77/toggle-handover', async (route) => {
          handoverOn = !handoverOn
          await route.fulfill({ json: { data: linkRoom(1, handoverOn) } })
        })
        // detail-GET observer (registered last -> runs first, then falls back)
        const roomGets: string[] = []
        await page.route('**/api/bots/1/conversations/77*', async (route) => {
          roomGets.push(route.request().url())
          await route.fallback()
        })

        await page.goto('/chat?botId=1&conversationId=77')

        const centre = page.getByTestId('chat-center-panel')
        await expect(centre.getByRole('heading', { name: LINK_ROOM_NAME })).toBeVisible()
        await expect(centre.getByRole('button', { name: 'Take Over' })).toBeVisible()

        await centre.getByRole('button', { name: 'Take Over' }).click()

        // the header flips WITHOUT any reload — the toggle POST succeeded and
        // the D133 subscription refetched the room (is_handover true)
        await expect(centre.getByRole('button', { name: 'Enable Bot' })).toBeVisible()
        // room GETs: open + mark-as-read fresh-copy refetch + toggle refetch
        await expect.poll(() => roomGets.length).toBe(3)
        for (const url of roomGets) {
          expect(url).toContain('/api/bots/1/conversations/77?messages_limit=1')
        }
        expect(markReadHits.count).toBe(1)
        expect(new URL(page.url()).searchParams.get('conversationId')).toBe('77')

        const overflow = await page.evaluate(() => ({
          sw: document.documentElement.scrollWidth,
          cw: document.documentElement.clientWidth,
        }))
        expect(overflow.sw).toBeLessThanOrEqual(overflow.cw)
        await shot(page, testInfo, `link-handover-${combo.width}-${combo.theme}`)
        expect(pageErrors).toEqual([])
      })

      test('link_load_failure_shows_retry_panel_and_retry_recovers', async ({
        page,
        loggedIn,
        pageErrors,
      }, testInfo) => {
        void loggedIn
        await setViewportAndTheme(page, combo)
        let failing = true
        const markReadHits = { count: 0 }
        await page.route(`**/api/bots/1/conversations/${LINK_ROOM_ID}*`, async (route) => {
          if (failing) {
            await route.fulfill({ status: 500, json: { message: 'E2E link room failure.' } })
            return
          }
          await route.fulfill({ json: { data: linkRoom(0) } })
        })
        await page.route(`**/api/bots/1/conversations/${LINK_ROOM_ID}/messages*`, (route) =>
          route.fulfill({ json: messagesListResponse }),
        )
        await page.route(`**/api/bots/1/conversations/${LINK_ROOM_ID}/notes`, (route) =>
          route.fulfill({ json: { data: [] } }),
        )
        await page.route(`**/api/bots/1/conversations/${LINK_ROOM_ID}/mark-as-read`, async (route) => {
          markReadHits.count += 1
          await route.fulfill({ json: { data: linkRoom(0) } })
        })

        await page.goto('/chat?botId=1&conversationId=77')

        await expect(page.getByText('โหลดห้องแชตไม่สำเร็จ')).toBeVisible()
        await expect(page.getByText('ลองอีกครั้ง หรือกลับไปเลือกห้องจากรายการ')).toBeVisible()
        await shot(page, testInfo, `link-error-load-${combo.width}-${combo.theme}`)

        // Retry while still failing: the panel stays (second request happened)
        await page.getByRole('button', { name: 'ลองอีกครั้ง' }).click()
        await expect(page.getByText('โหลดห้องแชตไม่สำเร็จ')).toBeVisible()

        // Retry after the server recovers: the room opens, no mark-as-read
        // (the recovered copy is already read)
        failing = false
        await page.getByRole('button', { name: 'ลองอีกครั้ง' }).click()
        await expect(
          page
            .getByTestId('chat-center-panel')
            .getByRole('heading', { name: LINK_ROOM_NAME }),
        ).toBeVisible()
        expect(markReadHits.count).toBe(0)
        expect(pageErrors).toEqual([])
      })
    })
  }
})

// ---------------------------------------------------------------------------
// F2: the follow-up label (ข้อความตามลูกค้า) under the bot's follow-up
// messages. Same shape as outage-settings.spec.ts's badge journey: local
// envelope helpers (shared fixture files stay untouched), four width/theme
// combos, three channel types, a flagged row, an unflagged row whose TEXT
// equals the label, and an outage row (the two labels are independent,
// D136). The flagged message text is synthetic — never a production text.
const FU_LABEL = 'ข้อความตามลูกค้า'
const FU_OUTAGE_LABEL = 'ส่งอัตโนมัติหลังบอทล่ม'
const FU_MESSAGE_TEXT = 'E2E-FU ตอบกลับลูกค้าครับ'

function fuConversationsEnvelope(conv: Record<string, unknown>) {
  return {
    data: [conv],
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      ...pagination,
      from: 1,
      to: 1,
      path: 'http://localhost/api/bots/1/conversations',
      links: [],
      status_counts: {
        active: 1,
        closed: 0,
        handover: 0,
        needs_response: 0,
        waiting_customer: 0,
        total: 1,
      },
    },
  }
}

function fuMessagesEnvelope(messages: Record<string, unknown>[]) {
  return {
    data: messages,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      ...pagination,
      from: messages.length,
      to: messages.length,
      path: 'http://localhost/api/bots/1/conversations/1/messages',
      links: [],
    },
  }
}

test.describe(`follow-up badge journeys (F2)`, () => {
  for (const combo of COMBOS) {
    test.describe(`${combo.width}-${combo.theme}`, () => {
      for (const channelType of ['line', 'telegram', 'testing'] as const) {
        test(`badge_${channelType}_on_flagged_row_only_and_survives_reload`, async ({
          page,
          loggedIn,
          pageErrors,
        }, testInfo) => {
          void loggedIn
          await setViewportAndTheme(page, combo)
          const conv = { ...conversation, channel_type: channelType }
          const messages = [
            {
              ...messageFixture,
              id: 1,
              sender: 'user',
              content: 'สอบถามราคาครับ',
              created_at: '2026-11-17T09:00:00Z',
              updated_at: '2026-11-17T09:00:00Z',
            },
            {
              ...messageFixture,
              id: 2,
              sender: 'bot',
              content: FU_MESSAGE_TEXT,
              is_follow_up: true,
              created_at: '2026-11-17T09:10:00Z',
              updated_at: '2026-11-17T09:10:00Z',
            },
            {
              ...messageFixture,
              id: 3,
              sender: 'bot',
              content: FU_LABEL, // text matches the label but carries NO flag
              created_at: '2026-11-17T09:11:00Z',
              updated_at: '2026-11-17T09:11:00Z',
            },
            {
              ...messageFixture,
              id: 4,
              sender: 'bot',
              content: 'ระบบกลับมาใช้งานได้แล้วครับ',
              is_outage_notice: true,
              created_at: '2026-11-17T09:12:00Z',
              updated_at: '2026-11-17T09:12:00Z',
            },
          ]
          await page.route('**/api/bots/1/conversations*', (route) =>
            route.fulfill({
              json: fuConversationsEnvelope(conv as unknown as Record<string, unknown>),
            }),
          )
          await page.route('**/api/bots/1/conversations/1/messages*', (route) =>
            route.fulfill({ json: fuMessagesEnvelope(messages) }),
          )

          await page.goto('/chat?botId=1')
          await page.getByRole('button', { name: /E2E Customer/ }).click()
          await expect(page.getByText(FU_MESSAGE_TEXT)).toBeVisible()

          // Exactly one element with the label text inside a badge, and it
          // sits in the flagged row (the raw-text row and the outage row
          // never carry it). Two badges in total: follow-up + outage (D136).
          const fuBadges = page.locator('[data-slot="badge"]', { hasText: FU_LABEL })
          await expect(fuBadges).toHaveCount(1)
          const badges = page.locator('[data-slot="badge"]')
          await expect(badges).toHaveCount(2)
          await expect(badges.filter({ hasText: FU_OUTAGE_LABEL })).toHaveCount(1)

          // The badge's parent element is its bubble container in all three
          // bubble components: the flagged text and the badge share it.
          const fuBubble = fuBadges.first().locator('xpath=..')
          await expect(fuBubble.getByText(FU_MESSAGE_TEXT)).toBeVisible()

          // Bounding box: the badge sits inside its bubble.
          const badgeBox = await fuBadges.first().boundingBox()
          const bubbleBox = await fuBubble.boundingBox()
          expect(badgeBox).not.toBeNull()
          expect(bubbleBox).not.toBeNull()
          expect(badgeBox!.x).toBeGreaterThanOrEqual(bubbleBox!.x)
          expect(badgeBox!.y).toBeGreaterThanOrEqual(bubbleBox!.y)
          expect(badgeBox!.x + badgeBox!.width).toBeLessThanOrEqual(
            bubbleBox!.x + bubbleBox!.width + 1,
          )
          expect(badgeBox!.y + badgeBox!.height).toBeLessThanOrEqual(
            bubbleBox!.y + bubbleBox!.height + 1,
          )

          // Wraps without horizontal scroll.
          const overflow = await page.evaluate(() => ({
            sw: document.documentElement.scrollWidth,
            cw: document.documentElement.clientWidth,
          }))
          expect(overflow.sw).toBeLessThanOrEqual(overflow.cw)

          await shot(page, testInfo, `badge-${channelType}-${combo.width}-${combo.theme}`)

          // B2: history from REST — the same badges after a full reload. On
          // phone the reload lands on the list (the room does not reopen on
          // its own, as F1b's link journeys document), so the room is tapped
          // again first; desktop auto-selects room 1.
          await page.reload()
          if (combo.width < 768) {
            await page.getByRole('button', { name: /E2E Customer/ }).click()
          }
          await expect(page.getByText(FU_MESSAGE_TEXT)).toBeVisible()
          await expect(page.locator('[data-slot="badge"]', { hasText: FU_LABEL })).toHaveCount(1)
          await expect(page.locator('[data-slot="badge"]')).toHaveCount(2)
          await expect(badges.filter({ hasText: FU_OUTAGE_LABEL })).toHaveCount(1)

          expect(pageErrors).toEqual([])
        })
      }
    })
  }
})

// ---------------------------------------------------------------------------
// FX1 (D146): the blocked notice's title (ยังเปิดไม่ได้) and its reason line
// must read at 4.5:1 in BOTH themes. The ratio is measured from RENDERED
// PIXELS, never from a float blend: QA (q2-report §5) measured 4.493:1 in
// light mode from 8-bit pixels, while a float blend of the translucent
// background gives 4.51 and hides the miss. The element screenshot is decoded
// inside the page with canvas.getImageData (8-bit sRGB), the background is
// the modal pixel of the shot and the ink is the pixel furthest from it in
// WCAG luminance (the strongest glyph pixel).
// ---------------------------------------------------------------------------

const NOTICE_TITLE_TEXT = 'ยังเปิดไม่ได้'
const TELEGRAM_MISSING_REASON = 'บอทนี้ยังไม่ได้ตั้งห้อง Telegram สำหรับแจ้งเตือน'
const CONTRAST_FLOOR = 4.5

interface RenderedContrast {
  ink: string
  bg: string
  ratio: number
}

async function renderedContrast(
  page: Page,
  elementShotBase64: string,
): Promise<RenderedContrast> {
  return page.evaluate(async (base64) => {
    const img = new Image()
    img.src = `data:image/png;base64,${base64}`
    await new Promise<void>((resolve, reject) => {
      img.onload = () => resolve()
      img.onerror = () => reject(new Error('element screenshot could not be decoded'))
    })
    const canvas = document.createElement('canvas')
    canvas.width = img.naturalWidth
    canvas.height = img.naturalHeight
    const ctx = canvas.getContext('2d', { willReadFrequently: true })
    if (!ctx) throw new Error('canvas 2d context unavailable')
    ctx.drawImage(img, 0, 0)
    const data = ctx.getImageData(0, 0, canvas.width, canvas.height).data

    // Histogram over the 8-bit RGB triplets as rendered.
    const counts = new Map<string, number>()
    for (let i = 0; i < data.length; i += 4) {
      const key = `${data[i]},${data[i + 1]},${data[i + 2]}`
      counts.set(key, (counts.get(key) ?? 0) + 1)
    }
    let bgKey = ''
    let bgCount = 0
    for (const [key, count] of counts) {
      if (count > bgCount) {
        bgKey = key
        bgCount = count
      }
    }
    const channel = (value: number) => {
      const s = value / 255
      return s <= 0.04045 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
    }
    const luminance = (r: number, g: number, b: number) =>
      0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b)
    const [br, bgc, bb] = bgKey.split(',').map(Number)
    const bgLum = luminance(br, bgc, bb)
    // Ink = the rendered pixel furthest from the background (glyph core, in
    // either theme direction).
    let inkKey = bgKey
    let furthest = -1
    for (const [key] of counts) {
      const [r, g, b] = key.split(',').map(Number)
      const distance = Math.abs(luminance(r, g, b) - bgLum)
      if (distance > furthest) {
        furthest = distance
        inkKey = key
      }
    }
    const [ir, ig, ib] = inkKey.split(',').map(Number)
    const inkLum = luminance(ir, ig, ib)
    const lighter = Math.max(bgLum, inkLum)
    const darker = Math.min(bgLum, inkLum)
    return {
      ink: `rgb(${inkKey})`,
      bg: `rgb(${bgKey})`,
      ratio: (lighter + 0.05) / (darker + 0.05),
    }
  }, elementShotBase64)
}

test.describe('blocked notice contrast (FX1)', () => {
  test('blocked_notice_title_contrast_at_least_4_5', async ({
    page,
    loggedIn,
    pageErrors,
  }) => {
    void loggedIn
    const measurements: string[] = []
    const under: string[] = []
    for (const combo of COMBOS) {
      await openFollowUpTab(
        page,
        combo,
        initialSettings({
          follow_up_enabled: false,
          follow_up_mode: 'approve',
          follow_up_started_at: null,
          follow_up_capabilities: capabilities({
            supported: false,
            telegram_ready: false,
            automatic_allowed: false,
            blocked_reason: 'telegram_missing',
          }),
        }),
      )
      const notice = page.getByTestId('follow-up-unavailable')
      const label = `${combo.width}-${combo.theme}`
      const targets = [
        ['title', notice.getByText(NOTICE_TITLE_TEXT, { exact: true })],
        ['reason', notice.getByText(TELEGRAM_MISSING_REASON, { exact: true })],
      ] as const
      for (const [what, element] of targets) {
        const shot = await element.screenshot()
        const { ink, bg, ratio } = await renderedContrast(page, shot.toString('base64'))
        const line = `${what} ${label}: ink ${ink} on bg ${bg} = ${ratio.toFixed(4)}:1`
        console.log(`[contrast] ${line}`)
        measurements.push(line)
        if (ratio < CONTRAST_FLOOR) under.push(`${line} — UNDER ${CONTRAST_FLOOR}`)
      }
    }
    expect(
      under,
      `measured from rendered pixels (element screenshots, 8-bit sRGB):\n${measurements.join('\n')}`,
    ).toEqual([])
    expect(pageErrors).toEqual([])
  })
})

// ---------------------------------------------------------------------------
// FX2 (D148/D149): the blocked notice moves INSIDE the first panel (mock
// frame 2) and the mode rows take the mock's states by their own radio:
// selected = border-primary bg-primary/5, locked = bg-muted + muted label,
// idle = card bg as today. data-state on each row lets the assertions read
// the state without class names.
// ---------------------------------------------------------------------------

const LOCKED_ROW_TITLE_TEXT = 'ส่งเองเมื่อ Luna มั่นใจ'
const LOCKED_ROW_HELPER_TEXT = 'เปิดได้หลังครบช่วงทดลอง'

test.describe('FX2 notice placement and option row states', () => {
  test('notice_inside_panel_and_rows_styled', async ({
    page,
    loggedIn,
    pageErrors,
    unmockedCalls,
  }) => {
    void loggedIn
    for (const combo of COMBOS) {
      // (a) Blocked bot: the notice sits inside the first panel, above the
      // switch, and nothing overflows horizontally.
      await openFollowUpTab(
        page,
        combo,
        initialSettings({
          follow_up_enabled: false,
          follow_up_mode: 'approve',
          follow_up_started_at: null,
          follow_up_capabilities: capabilities({
            supported: false,
            telegram_ready: false,
            automatic_allowed: false,
            blocked_reason: 'telegram_missing',
          }),
        }),
      )
      const notice = page.getByTestId('follow-up-unavailable')
      const switchEl = page.locator('#follow-up-enabled')
      const panel = page.locator('section').filter({ has: switchEl }).first()
      const [nb, pb, sb] = await Promise.all([
        notice.boundingBox(),
        panel.boundingBox(),
        switchEl.boundingBox(),
      ])
      expect(nb, 'notice box').not.toBeNull()
      expect(pb, 'first panel box').not.toBeNull()
      expect(sb, 'switch box').not.toBeNull()
      expect(nb!.x).toBeGreaterThanOrEqual(pb!.x - 1)
      expect(nb!.y).toBeGreaterThanOrEqual(pb!.y - 1)
      expect(
        nb!.x + nb!.width,
        'notice must sit inside the panel horizontally',
      ).toBeLessThanOrEqual(pb!.x + pb!.width + 1)
      expect(nb!.y + nb!.height, 'notice must sit inside the panel').toBeLessThanOrEqual(
        pb!.y + pb!.height + 1,
      )
      expect(nb!.y + nb!.height, 'notice must sit above the switch').toBeLessThanOrEqual(
        sb!.y + 1,
      )
      const overflow = await page.evaluate(() => ({
        sw: document.documentElement.scrollWidth,
        cw: document.documentElement.clientWidth,
      }))
      expect(
        overflow.sw,
        `document scrollWidth ${overflow.sw} must not exceed clientWidth ${overflow.cw}`,
      ).toBeLessThanOrEqual(overflow.cw)

      // (b) Ready bot, switch on, automatic locked by the pilot window: the
      // selected approve row carries the primary border and a tint, and the
      // locked automatic row the muted background.
      await openFollowUpTab(
        page,
        combo,
        initialSettings({
          follow_up_enabled: true,
          follow_up_mode: 'approve',
          follow_up_started_at: null,
          follow_up_capabilities: capabilities({ automatic_allowed: false }),
        }),
      )
      const approveRow = page.getByTestId('follow-up-mode-approve-row')
      const automaticRow = page.getByTestId('follow-up-mode-automatic-row')
      await expect(approveRow).toHaveAttribute('data-state', 'selected')
      await expect(automaticRow).toHaveAttribute('data-state', 'locked')
      const tokens = await page.evaluate(() => {
        const probe = document.createElement('div')
        probe.style.display = 'none'
        probe.style.borderTopColor = 'var(--primary)'
        probe.style.backgroundColor = 'var(--muted)'
        document.body.appendChild(probe)
        const cs = getComputedStyle(probe)
        const resolved = { primary: cs.borderTopColor, muted: cs.backgroundColor }
        probe.remove()
        return resolved
      })
      const approveColors = await approveRow.evaluate((el) => {
        const cs = getComputedStyle(el)
        return { border: cs.borderTopColor, bg: cs.backgroundColor }
      })
      const automaticBg = await automaticRow.evaluate((el) => getComputedStyle(el).backgroundColor)
      expect(approveColors.border, 'selected border must be --primary').toBe(tokens.primary)
      expect(approveColors.bg, 'selected bg must differ from the locked row').not.toBe(
        automaticBg,
      )
      expect(automaticBg, 'locked bg must be --muted').toBe(tokens.muted)

      // (c) Switch off through the real switch: both rows report locked.
      await page.getByRole('switch', { name: 'เปิดตามลูกค้า' }).click()
      await expect(approveRow).toHaveAttribute('data-state', 'locked')
      await expect(automaticRow).toHaveAttribute('data-state', 'locked')
    }
    expect(pageErrors).toEqual([])
    expect(unmockedCalls).toEqual([])
  })

  test('locked_row_text_contrast_at_least_4_5', async ({
    page,
    loggedIn,
    pageErrors,
    unmockedCalls,
  }) => {
    void loggedIn
    const measurements: string[] = []
    const under: string[] = []
    for (const combo of COMBOS) {
      await openFollowUpTab(
        page,
        combo,
        initialSettings({
          follow_up_enabled: true,
          follow_up_mode: 'approve',
          follow_up_started_at: null,
          follow_up_capabilities: capabilities({ automatic_allowed: false }),
        }),
      )
      const row = page.getByTestId('follow-up-mode-automatic-row')
      // Positive control: measure the LOCKED styling only.
      await expect(row, 'the measured row must be the locked design').toHaveAttribute(
        'data-state',
        'locked',
      )
      const label = `${combo.width}-${combo.theme}`
      const targets = [
        ['title', row.getByText(LOCKED_ROW_TITLE_TEXT, { exact: true })],
        ['helper', row.getByText(LOCKED_ROW_HELPER_TEXT, { exact: true })],
      ] as const
      for (const [what, element] of targets) {
        const shot = await element.screenshot()
        const { ink, bg, ratio } = await renderedContrast(page, shot.toString('base64'))
        const line = `${what} ${label}: ink ${ink} on bg ${bg} = ${ratio.toFixed(4)}:1`
        console.log(`[contrast] ${line}`)
        measurements.push(line)
        if (ratio < CONTRAST_FLOOR) under.push(`${line} — UNDER ${CONTRAST_FLOOR}`)
      }
    }
    expect(
      under,
      `measured from rendered pixels (element screenshots, 8-bit sRGB):\n${measurements.join('\n')}`,
    ).toEqual([])
    expect(pageErrors).toEqual([])
    expect(unmockedCalls).toEqual([])
  })
})
