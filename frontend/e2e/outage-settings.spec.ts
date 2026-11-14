import { test, expect } from './fixtures'
import type { Page, TestInfo } from '@playwright/test'
import { conversation, message as messageFixture, pagination } from './mock-data'

// Outage settings journeys (plan Task F3). Everything the journeys need is
// mocked here on top of the shared fail-closed fixtures; the shared fixture
// files stay untouched. Synthetic defaults are declared ONCE below — never the
// production copy.
//
// Server-state pattern: the settings route is method-aware (GET returns the
// captured state, PUT merges the body into it), so a reload proves real
// persistence through the mock instead of a no-op stub.
//
// Thai-only (owner order 14 Nov, decision D5): the tab has no English field
// and the PUT never carries outage_recovery_message_en. The mocked API keeps
// accepting and returning the English fields, as the real API does.

const OUTAGE_TAB = 'เมื่อบอทล่ม'
const BADGE_LABEL = 'ส่งอัตโนมัติหลังบอทล่ม'
const RULE_MESSAGE = 'ใช้ได้เฉพาะข้อความธรรมดา ห้ามใส่แท็ก HTML หรือ {ตัวแปร}'

const DEFAULTS = {
  outage_recovery_message_th: 'E2E-DEF-TH-กลับมาแล้ว',
  outage_recovery_message_en: 'E2E-DEF-EN-back online',
  outage_down_message: 'E2E-DEF-DOWN-ขออภัย',
}

const COMBOS = [
  { width: 375, height: 812, theme: 'light' },
  { width: 375, height: 812, theme: 'dark' },
  { width: 1280, height: 800, theme: 'light' },
  { width: 1280, height: 800, theme: 'dark' },
] as const

function initialSettings(): Record<string, unknown> {
  return {
    daily_message_limit: 500,
    per_user_limit: 30,
    outage_recovery_enabled: true,
    outage_recovery_message_th: null,
    outage_recovery_message_en: null,
    outage_down_message: null,
    outage_defaults: { ...DEFAULTS },
  }
}

interface SettingsHandle {
  puts: Record<string, unknown>[]
  server: Record<string, unknown>
}

async function registerMethodAwareSettings(
  page: Page,
  initial: Record<string, unknown>,
  putStatus = 200,
): Promise<SettingsHandle> {
  const state: SettingsHandle = { puts: [], server: { ...initial } }
  await page.route('**/api/bots/1/settings', async (route) => {
    if (route.request().method() === 'PUT') {
      const body = route.request().postDataJSON() as Record<string, unknown>
      state.puts.push(body)
      if (putStatus !== 200) {
        await route.fulfill({
          status: putStatus,
          json: {
            message: 'The given data was invalid.',
            errors: { outage_recovery_message_th: [RULE_MESSAGE] },
          },
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

async function openOutageTab(
  page: Page,
  combo: (typeof COMBOS)[number],
  initial: Record<string, unknown>,
  putStatus = 200,
): Promise<SettingsHandle> {
  await setViewportAndTheme(page, combo)
  const state = await registerMethodAwareSettings(page, initial, putStatus)
  await page.goto('/bots/1/settings')
  // Theme persisted in ui-storage wins over colorScheme emulation: assert the root class.
  await expect(page.locator('html')).toHaveClass(new RegExp(combo.theme))
  await expect(page.getByRole('heading', { name: 'ตั้งค่าบอท' })).toBeVisible()
  await page.getByRole('button', { name: OUTAGE_TAB }).click()
  await expect(page.getByRole('button', { name: OUTAGE_TAB })).toHaveClass(/bg-accent/)
  return state
}

async function assertEditorHealth(page: Page) {
  const overflow = await page.evaluate(() => ({
    sw: document.documentElement.scrollWidth,
    cw: document.documentElement.clientWidth,
  }))
  expect(
    overflow.sw,
    `document scrollWidth ${overflow.sw} must not exceed clientWidth ${overflow.cw}`,
  ).toBeLessThanOrEqual(overflow.cw)

  // The sticky save bar must not cover the last field (down editor). Focus
  // scrolling in browsers honors scroll-margin, so scroll the way a browser
  // does on focus (block:'end' applies the editor's scroll-mb-24) and then
  // assert the field sits fully above the bar.
  const down = page.getByRole('textbox', { name: 'ข้อความ', exact: true })
  await down.scrollIntoViewIfNeeded()
  await down.evaluate((el) => el.scrollIntoView({ block: 'end', behavior: 'instant' }))
  const field = await down.boundingBox()
  const save = await page.getByRole('button', { name: 'บันทึกตั้งค่า' }).boundingBox()
  expect(field).not.toBeNull()
  expect(save).not.toBeNull()
  expect(
    field!.y + field!.height,
    'save bar must sit below the last field, not on top of it',
  ).toBeLessThanOrEqual(save!.y + 1)
}

async function shot(page: Page, testInfo: TestInfo, name: string) {
  const path = testInfo.outputPath(`${name}.png`)
  await page.screenshot({ path })
  await testInfo.attach(name, { path, contentType: 'image/png' })
}

function conversationsEnvelope(conv: Record<string, unknown>) {
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

function messagesEnvelope(messages: Record<string, unknown>[]) {
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

test.describe(`outage settings journeys`, () => {
  for (const combo of COMBOS) {
    test.describe(`${combo.width}-${combo.theme}`, () => {
      test('edits_saves_reloads_and_resets_th_and_down_texts', async ({
        page,
        loggedIn,
        pageErrors,
      }, testInfo) => {
        void loggedIn
        // The mocked API carries a stored English text, as the real API may
        // (D5); the tab must load, edit and save the Thai texts without ever
        // showing or sending it.
        const state = await openOutageTab(page, combo, {
          ...initialSettings(),
          outage_recovery_message_en: 'E2E-STORED-EN-back online',
        })
        const th = page.getByRole('textbox', { name: 'ข้อความภาษาไทย' })
        const down = page.getByRole('textbox', { name: 'ข้อความ', exact: true })

        // No English field on the tab: no textbox, no #outage-recovery-en, no
        // reset action — even with the switch on and a stored value returned
        await expect(page.getByRole('textbox', { name: 'ข้อความภาษาอังกฤษ' })).toHaveCount(0)
        await expect(page.locator('#outage-recovery-en')).toHaveCount(0)
        await expect(
          page.getByRole('button', { name: 'คืนค่ามาตรฐาน ข้อความภาษาอังกฤษ' }),
        ).toHaveCount(0)

        // Null stored values hydrate to the API defaults
        await expect(th).toHaveValue(DEFAULTS.outage_recovery_message_th)
        await expect(down).toHaveValue(DEFAULTS.outage_down_message)
        await assertEditorHealth(page)
        await shot(page, testInfo, `outage-default-${combo.width}-${combo.theme}`)

        await th.fill('ทดสอบกลับมาแล้ว')
        await down.fill('ขออภัย ตอบไม่ได้ตอนนี้')
        await shot(page, testInfo, `outage-edited-${combo.width}-${combo.theme}`)

        const firstPut = page.waitForResponse(
          (r) => r.url().includes('/settings') && r.request().method() === 'PUT',
        )
        await page.getByRole('button', { name: 'บันทึกตั้งค่า' }).click()
        await firstPut
        expect(state.puts[0]).not.toHaveProperty('outage_defaults')
        expect(state.puts[0].outage_recovery_message_th).toBe('ทดสอบกลับมาแล้ว')
        expect(state.puts[0]).not.toHaveProperty('outage_recovery_message_en')

        // Reload proves persistence through the captured server state
        await page.reload()
        await page.getByRole('button', { name: OUTAGE_TAB }).click()
        await expect(th).toHaveValue('ทดสอบกลับมาแล้ว')

        // Reset TH to the API default → the next save sends null (inheritance)
        await page.getByRole('button', { name: 'คืนค่ามาตรฐาน ข้อความภาษาไทย' }).click()
        const secondPut = page.waitForResponse(
          (r) => r.url().includes('/settings') && r.request().method() === 'PUT',
        )
        await page.getByRole('button', { name: 'บันทึกตั้งค่า' }).click()
        await secondPut
        expect(state.puts[1].outage_recovery_message_th).toBeNull()
        expect(state.puts[1]).not.toHaveProperty('outage_recovery_message_en')

        await page.reload()
        await page.getByRole('button', { name: OUTAGE_TAB }).click()
        await expect(th).toHaveValue(DEFAULTS.outage_recovery_message_th)
        expect(pageErrors).toEqual([])
      })

      test('off_keeps_values_and_down_editor_editable', async ({
        page,
        loggedIn,
        pageErrors,
      }, testInfo) => {
        void loggedIn
        const state = await openOutageTab(page, combo, {
          ...initialSettings(),
          outage_recovery_message_th: 'ทดสอบกลับมาแล้ว',
        })
        const th = page.getByRole('textbox', { name: 'ข้อความภาษาไทย' })
        const down = page.getByRole('textbox', { name: 'ข้อความ', exact: true })

        await page.getByRole('switch').click()
        await expect(th).toBeDisabled()
        await expect(th).toHaveValue('ทดสอบกลับมาแล้ว')
        // No English field with the switch off either
        await expect(page.getByRole('textbox', { name: 'ข้อความภาษาอังกฤษ' })).toHaveCount(0)
        await expect(
          page.getByRole('button', { name: 'คืนค่ามาตรฐาน ข้อความภาษาอังกฤษ' }),
        ).toHaveCount(0)
        await expect(page.getByTestId('outage-preview')).toHaveAttribute(
          'aria-disabled',
          'true',
        )
        await expect(down).toBeEnabled()
        await down.fill('ยังพิมพ์ได้ตอนบอทล่ม')
        await assertEditorHealth(page)
        await shot(page, testInfo, `outage-off-${combo.width}-${combo.theme}`)

        const put = page.waitForResponse(
          (r) => r.url().includes('/settings') && r.request().method() === 'PUT',
        )
        await page.getByRole('button', { name: 'บันทึกตั้งค่า' }).click()
        await put
        expect(state.puts[0].outage_recovery_enabled).toBe(false)
        expect(state.puts[0].outage_recovery_message_th).toBe('ทดสอบกลับมาแล้ว')
        expect(state.puts[0]).not.toHaveProperty('outage_recovery_message_en')

        await page.reload()
        await page.getByRole('button', { name: OUTAGE_TAB }).click()
        await expect(th).toBeDisabled()
        await expect(down).toHaveValue('ยังพิมพ์ได้ตอนบอทล่ม')
        expect(pageErrors).toEqual([])
      })

      test('save_failure_keeps_draft_and_overlimit_never_calls_put', async ({
        page,
        loggedIn,
        pageErrors,
      }, testInfo) => {
        void loggedIn
        const state = await openOutageTab(page, combo, initialSettings(), 422)
        const th = page.getByRole('textbox', { name: 'ข้อความภาษาไทย' })

        // 501 code points: destructive counter, visible message, save blocked
        await th.fill('ก'.repeat(501))
        await expect(page.getByTestId('outage-recovery-th-error')).toHaveText(
          'ข้อความยาวเกิน 500 ตัวอักษร',
        )
        await expect(
          page.getByTestId('outage-recovery-th-counter'),
        ).toContainText('501 / 500')
        await expect(page.getByRole('button', { name: 'บันทึกตั้งค่า' })).toBeDisabled()
        await shot(page, testInfo, `outage-overlimit-${combo.width}-${combo.theme}`)

        // No PUT may have been attempted for the over-limit click
        expect(state.puts).toEqual([])

        // Under the limit again: save fires, 422 keeps the draft and shows the rule
        await th.fill('<b>โฆษณา</b>')
        await expect(page.getByRole('button', { name: 'บันทึกตั้งค่า' })).toBeEnabled()
        await page.getByRole('button', { name: 'บันทึกตั้งค่า' }).click()
        await expect(page.getByTestId('outage-recovery-th-error')).toHaveText(RULE_MESSAGE)
        await expect(th).toHaveValue('<b>โฆษณา</b>')
        await shot(page, testInfo, `outage-save-error-${combo.width}-${combo.theme}`)

        // The app retries mutations once on failure (src/lib/query.ts retry: 1),
        // so the 422 fires up to two PUTs. What the rule forbids is the
        // over-limit click ever reaching the wire: every attempted PUT must
        // carry the under-limit draft, never the 501-char text — and never the
        // English key.
        expect(state.puts.length).toBeGreaterThanOrEqual(1)
        for (const put of state.puts) {
          expect(put.outage_recovery_message_th).toBe('<b>โฆษณา</b>')
          expect(put).not.toHaveProperty('outage_recovery_message_en')
        }
        expect(
          state.puts.some((p) => p.outage_recovery_message_th === 'ก'.repeat(501)),
        ).toBe(false)
        expect(pageErrors).toEqual([])
      })

      test('null_values_use_server_defaults', async ({ page, loggedIn, pageErrors }) => {
        void loggedIn
        const state = await openOutageTab(page, combo, initialSettings())

        // Save from ANOTHER tab without editing: the two texts go out as null
        // and the English key is never sent
        await page.getByRole('button', { name: 'ข้อจำกัด' }).click()
        const put = page.waitForResponse(
          (r) => r.url().includes('/settings') && r.request().method() === 'PUT',
        )
        await page.getByRole('button', { name: 'บันทึกตั้งค่า' }).click()
        await put
        expect(state.puts[0].outage_recovery_message_th).toBeNull()
        expect(state.puts[0].outage_down_message).toBeNull()
        expect(state.puts[0]).not.toHaveProperty('outage_defaults')
        expect(state.puts[0]).not.toHaveProperty('outage_recovery_message_en')

        // Reload: the defaults are still what the fields show
        await page.reload()
        await page.getByRole('button', { name: OUTAGE_TAB }).click()
        await expect(
          page.getByRole('textbox', { name: 'ข้อความภาษาไทย' }),
        ).toHaveValue(DEFAULTS.outage_recovery_message_th)
        await assertEditorHealth(page)
        expect(pageErrors).toEqual([])
      })

      test('badge_on_notice_not_down_reply_in_each_channel', async ({
        page,
        loggedIn,
        pageErrors,
      }, testInfo) => {
        void loggedIn
        await setViewportAndTheme(page, combo)
        const channels = ['line', 'telegram', 'testing'] as const

        for (const channelType of channels) {
          const conv = { ...conversation, channel_type: channelType }
          const messages = [
            {
              ...messageFixture,
              id: 1,
              sender: 'user',
              content: 'ห้องล่มหรือเปล่าครับ',
              created_at: '2026-11-13T10:00:00Z',
              updated_at: '2026-11-13T10:00:00Z',
            },
            {
              ...messageFixture,
              id: 2,
              sender: 'bot',
              content: 'ระบบกลับมาแล้ว รบกวนพิมพ์ข้อความใหม่',
              is_outage_notice: true,
              created_at: '2026-11-13T10:05:00Z',
              updated_at: '2026-11-13T10:05:00Z',
            },
            {
              ...messageFixture,
              id: 3,
              sender: 'bot',
              content: BADGE_LABEL, // text matches the label but carries NO flag
              created_at: '2026-11-13T10:06:00Z',
              updated_at: '2026-11-13T10:06:00Z',
            },
          ]
          // Later registrations win over the shared fixtures.
          await page.route('**/api/bots/1/conversations*', (route) =>
            route.fulfill({ json: conversationsEnvelope(conv as unknown as Record<string, unknown>) }),
          )
          await page.route('**/api/bots/1/conversations/1/messages*', (route) =>
            route.fulfill({ json: messagesEnvelope(messages) }),
          )

          await page.goto('/chat?botId=1')
          await page.getByRole('button', { name: /E2E Customer/ }).click()
          await expect(page.getByText('ระบบกลับมาแล้ว รบกวนพิมพ์ข้อความใหม่')).toBeVisible()

          // Exactly one badge: on the flagged notice row only — never the user
          // row, never the unflagged row whose text merely matches the label.
          const badges = page.locator('[data-slot="badge"]')
          await expect(badges).toHaveCount(1)
          await expect(badges.first()).toHaveText(BADGE_LABEL)

          const overflow = await page.evaluate(() => ({
            sw: document.documentElement.scrollWidth,
            cw: document.documentElement.clientWidth,
          }))
          expect(overflow.sw).toBeLessThanOrEqual(overflow.cw)

          if (
            (channelType === 'line' && combo.width === 375 && combo.theme === 'light') ||
            (channelType === 'telegram' && combo.width === 1280 && combo.theme === 'light') ||
            (channelType === 'testing' && combo.width === 375 && combo.theme === 'dark')
          ) {
            await shot(page, testInfo, `badge-${channelType}-${combo.width}-${combo.theme}`)
          }
        }
        expect(pageErrors).toEqual([])
      })
    })
  }
})
