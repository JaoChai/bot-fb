import { test, expect } from './fixtures'
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
