/* eslint-disable react-refresh/only-export-components -- the reason tables
   (D129) are one source of truth shared by this tab and the page's 422
   mapping; the same file-top disable pattern is used by ui/badge.tsx and
   ui/button.tsx. */
import { Check, Info, MessageCircle } from 'lucide-react';
import { Switch } from '@/components/ui/switch';
import { Panel } from '@/components/common';
import { SettingRow } from '@/components/connections';
import type { CustomerFollowUpCapabilities } from '@/types/api';

type FollowUpMode = 'approve' | 'automatic';
type FollowUpField = 'follow_up_enabled' | 'follow_up_mode';

interface FollowUpSettingsTabProps {
  follow_up_enabled: boolean;
  follow_up_mode: FollowUpMode;
  /** API follow_up_capabilities; undefined means the response did not carry it (D123) */
  capabilities?: CustomerFollowUpCapabilities;
  /** Mode as stored on the server; a stored automatic stays selectable (D125) */
  storedMode: FollowUpMode;
  errors?: Partial<Record<FollowUpField, string>>;
  onChange: (field: string, value: unknown) => void;
}

// Reason lines (D129). ONE table holds all 7 known codes and is shared by
// the notice (blocked_reason) and the 422 mapping (follow_up_enabled).
const SYSTEM_UNSET_LINE = 'ระบบยังตั้งค่าไม่ครบ ติดต่อผู้ดูแลระบบ';
const REASON_LINES: Record<string, string> = {
  line_only: 'ตอนนี้ตามลูกค้าได้เฉพาะบอท LINE',
  bot_not_in_pilot: 'ช่วงทดลองเปิดใช้เฉพาะบอทที่กำหนด',
  telegram_missing: 'บอทนี้ยังไม่ได้ตั้งห้อง Telegram สำหรับแจ้งเตือน',
  telegram_allowlist_missing: 'บอทนี้ยังไม่ได้ตั้งรายชื่อคนที่กดอนุมัติได้',
  webhook_secret_missing: SYSTEM_UNSET_LINE,
  writer_missing: SYSTEM_UNSET_LINE,
  admin_url_missing: SYSTEM_UNSET_LINE,
};

export const FOLLOW_UP_UNAVAILABLE_LINE = 'ยังโหลดข้อมูลตามลูกค้าไม่ได้ ลองโหลดหน้าใหม่';
export const FOLLOW_UP_SAVE_FAILED_LINE = 'บันทึกไม่สำเร็จ ลองใหม่อีกครั้ง';

/** Notice line for a capabilities.blocked_reason code (unknown codes fall back). */
export function followUpBlockedNoticeLine(code: string | null | undefined): string {
  return (code && REASON_LINES[code]) || SYSTEM_UNSET_LINE;
}

const MODE_LINES: Record<string, string> = {
  switch_off: 'ต้องเปิดตามลูกค้าก่อน จึงเลือกส่งเองได้',
  automatic_not_allowed: 'ยังเลือกส่งเองไม่ได้ ต้องครบช่วงทดลองก่อน',
};

/** 422 line under the field's control; anything unmapped answers "save failed". */
export function followUpSaveErrorLine(field: FollowUpField, code: string): string {
  if (field === 'follow_up_mode') return MODE_LINES[code] ?? FOLLOW_UP_SAVE_FAILED_LINE;
  return REASON_LINES[code] ?? FOLLOW_UP_SAVE_FAILED_LINE;
}

interface FollowUpFact {
  strong: string;
  rest?: string;
}

// The numbers come from the capability rules, never from literals (D127).
function followUpFacts(rules: CustomerFollowUpCapabilities['rules']): FollowUpFact[] {
  return [
    { strong: 'ตาม 2 กลุ่ม', rest: 'ค้างที่ขั้นจ่ายเงิน และสนใจแต่ยังลังเล' },
    {
      strong: 'รอก่อนตาม',
      rest: `ค้างจ่าย ${rules.payment_wait_hours} ชั่วโมง · ลังเล ${rules.sale_wait_hours} ชั่วโมง`,
    },
    { strong: `ส่งเฉพาะ ${rules.send_start}–${rules.send_end}`, rest: 'นอกเวลานี้ไม่ส่ง' },
    { strong: 'เงียบ 1 รอบ ตาม 1 ข้อความ', rest: `ห่างกันอย่างน้อย ${rules.customer_gap_days} วัน` },
    { strong: `ร่างไม่เกิน ${rules.daily_draft_cap} ข้อความต่อวัน` },
    { strong: 'ไม่ตาม', rest: 'ห้องที่ปิดบอท และลูกค้าที่จ่ายแล้ว' },
  ];
}

export function FollowUpSettingsTab({
  follow_up_enabled,
  follow_up_mode,
  capabilities,
  storedMode,
  errors,
  onChange,
}: FollowUpSettingsTabProps) {
  // Not ready = capabilities absent, or the server says supported: false (D123)
  const ready = !!capabilities && capabilities.supported;
  const noticeLine = capabilities
    ? followUpBlockedNoticeLine(capabilities.blocked_reason)
    : FOLLOW_UP_UNAVAILABLE_LINE;
  // A stored automatic stays selectable even when the pilot window closed (D125)
  const automaticSelectable = !!capabilities?.automatic_allowed || storedMode === 'automatic';

  return (
    <div className="space-y-6">
      {!ready && (
        <div
          data-testid="follow-up-unavailable"
          className="rounded-lg border border-amber-500/40 bg-amber-500/10 px-4 py-3"
        >
          <p className="text-sm font-semibold text-amber-700 dark:text-amber-400">ยังเปิดไม่ได้</p>
          <p className="mt-0.5 text-sm text-muted-foreground">{noticeLine}</p>
        </div>
      )}

      <Panel
        icon={MessageCircle}
        title="ตามลูกค้าที่เงียบไป"
        description="ร่างข้อความให้เจ้านายตรวจ ก่อนตามลูกค้าที่คุยค้างไว้"
      >
        <div className="space-y-5">
          <div className="space-y-1">
            <SettingRow
              label="เปิดตามลูกค้า"
              htmlFor="follow-up-enabled"
              description="ปิดอยู่เป็นค่าเริ่มต้น เปิดเมื่อพร้อมเท่านั้น"
            >
              <Switch
                id="follow-up-enabled"
                checked={follow_up_enabled}
                // Off + not ready locks the switch; an ON switch can always go off (D125)
                disabled={!follow_up_enabled && !ready}
                aria-invalid={errors?.follow_up_enabled ? true : undefined}
                aria-describedby={errors?.follow_up_enabled ? 'follow-up-enabled-error' : undefined}
                onCheckedChange={(checked) => {
                  onChange('follow_up_enabled', checked);
                  // Turning off resets the mode to approve, like the server does (D125)
                  if (!checked) onChange('follow_up_mode', 'approve');
                }}
              />
            </SettingRow>
            {errors?.follow_up_enabled && (
              <p
                id="follow-up-enabled-error"
                data-testid="follow-up-enabled-error"
                className="text-xs text-destructive"
              >
                {errors.follow_up_enabled}
              </p>
            )}
          </div>

          <fieldset
            disabled={!follow_up_enabled}
            className="ml-4 scroll-mb-24 pl-4 border-l-2 border-muted space-y-3 mt-2 min-w-0"
          >
            <legend className="px-1 text-xs font-semibold text-muted-foreground">วิธีส่ง</legend>

            <div
              data-testid="follow-up-mode-approve-row"
              className="flex min-h-11 items-start gap-3 rounded-lg border bg-card px-3 py-2"
            >
              <input
                id="follow-up-mode-approve"
                type="radio"
                name="follow-up-mode"
                value="approve"
                checked={follow_up_mode === 'approve'}
                onChange={() => onChange('follow_up_mode', 'approve')}
                disabled={!follow_up_enabled}
                aria-describedby={errors?.follow_up_mode ? 'follow-up-mode-error' : undefined}
                className="mt-1 size-4 shrink-0 accent-primary"
              />
              <div className="min-w-0">
                <label
                  htmlFor="follow-up-mode-approve"
                  className="block cursor-pointer text-sm font-medium text-foreground"
                >
                  เจ้านายอนุมัติทุกข้อความ
                </label>
                <p className="text-xs text-muted-foreground">
                  ร่างเด้งเข้า Telegram ให้กด ส่ง / ข้าม / แก้ ก่อนทุกครั้ง
                </p>
              </div>
            </div>

            <div
              data-testid="follow-up-mode-automatic-row"
              className="flex min-h-11 items-start gap-3 rounded-lg border bg-card px-3 py-2"
            >
              <input
                id="follow-up-mode-automatic"
                type="radio"
                name="follow-up-mode"
                value="automatic"
                checked={follow_up_mode === 'automatic'}
                onChange={() => onChange('follow_up_mode', 'automatic')}
                disabled={!follow_up_enabled || !automaticSelectable}
                aria-describedby={errors?.follow_up_mode ? 'follow-up-mode-error' : undefined}
                className="mt-1 size-4 shrink-0 accent-primary"
              />
              <div className="min-w-0">
                <label
                  htmlFor="follow-up-mode-automatic"
                  className="block cursor-pointer text-sm font-medium text-foreground"
                >
                  ส่งเองเมื่อ Luna มั่นใจ
                </label>
                {!automaticSelectable && (
                  <p className="text-xs text-muted-foreground">เปิดได้หลังครบช่วงทดลอง</p>
                )}
              </div>
            </div>

            {errors?.follow_up_mode && (
              <p
                id="follow-up-mode-error"
                data-testid="follow-up-mode-error"
                className="text-xs text-destructive"
              >
                {errors.follow_up_mode}
              </p>
            )}
          </fieldset>
        </div>
      </Panel>

      {capabilities && (
        <Panel
          icon={Info}
          title="ระบบทำงานอย่างไร"
          description="กติกาเหล่านี้ตั้งไว้ตายตัว แก้ในหน้านี้ไม่ได้"
        >
          <ul className="space-y-3 scroll-mb-24">
            {followUpFacts(capabilities.rules).map((fact) => (
              <li key={fact.strong} className="flex items-start gap-2 text-sm text-muted-foreground">
                <Check className="size-4 shrink-0 mt-0.5 text-foreground" strokeWidth={2} />
                <span>
                  <b className="text-foreground">{fact.strong}</b>
                  {fact.rest ? ` ${fact.rest}` : ''}
                </span>
              </li>
            ))}
          </ul>
        </Panel>
      )}
    </div>
  );
}
