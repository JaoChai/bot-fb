import { Check, Info, MessageCircle, RotateCcw, TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Panel } from '@/components/common';
import { SettingRow } from '@/components/connections';
import { cn } from '@/lib/utils';
import type { OutageTextDefaults } from '@/types/api';

export const OUTAGE_MAX_CODE_POINTS = 500;

const OVER_LIMIT_MESSAGE = 'ข้อความยาวเกิน 500 ตัวอักษร';

/** Counter (and limit) counts Unicode code points, so 500 emoji are 500. */
export function countCodePoints(value: string): number {
  return Array.from(value).length;
}

export function isOverOutageLimit(value: string): boolean {
  return countCodePoints(value) > OUTAGE_MAX_CODE_POINTS;
}

interface OutageSettingsTabProps {
  outage_recovery_enabled: boolean;
  outage_recovery_message_th: string;
  outage_recovery_message_en: string;
  outage_down_message: string;
  defaults: OutageTextDefaults;
  errors?: Partial<Record<keyof OutageTextDefaults, string>>;
  onChange: (field: string, value: unknown) => void;
}

interface OutageTextFieldProps {
  id: string;
  label: string;
  description?: string;
  value: string;
  serverError?: string;
  resetLabel: string;
  disabled?: boolean;
  onReset: () => void;
  onChange: (value: string) => void;
}

function OutageTextField({
  id,
  label,
  description,
  value,
  serverError,
  resetLabel,
  disabled,
  onReset,
  onChange,
}: OutageTextFieldProps) {
  const length = countCodePoints(value);
  const overLimit = length > OUTAGE_MAX_CODE_POINTS;
  const error = serverError ?? (overLimit ? OVER_LIMIT_MESSAGE : undefined);

  return (
    <div className="space-y-2 min-w-0">
      <div className="flex items-start justify-between gap-3 min-w-0">
        <div className="space-y-0.5 min-w-0">
          <label htmlFor={id} className="text-sm font-medium text-foreground">
            {label}
          </label>
          {description && <p className="text-xs text-muted-foreground">{description}</p>}
        </div>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-11 shrink-0 text-muted-foreground"
          aria-label={resetLabel}
          onClick={onReset}
          disabled={disabled}
        >
          <RotateCcw className="size-3.5" strokeWidth={1.5} />
          คืนค่ามาตรฐาน
        </Button>
      </div>
      <Textarea
        id={id}
        value={value}
        rows={3}
        disabled={disabled}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${id}-hint ${id}-error` : `${id}-hint`}
        onChange={(e) => onChange(e.target.value)}
      />
      <div
        id={`${id}-hint`}
        className="flex items-center justify-between gap-2 text-xs text-muted-foreground"
      >
        <span>เว้นว่าง = ใช้ข้อความมาตรฐาน</span>
        <span
          data-testid={`${id}-counter`}
          className={cn(overLimit && 'text-destructive')}
        >
          {length} / {OUTAGE_MAX_CODE_POINTS}
        </span>
      </div>
      {error && (
        <p id={`${id}-error`} data-testid={`${id}-error`} className="text-xs text-destructive">
          {error}
        </p>
      )}
    </div>
  );
}

const OUTAGE_FACTS: { strong: string; rest: string }[] = [
  { strong: 'ห้องละ 1 ครั้ง', rest: 'ต่อการล่ม 1 รอบ' },
  { strong: 'ส่งเฉพาะ 08:00–22:00', rest: 'ถ้ากลับมาตอนดึก รอส่งตอนเช้า' },
  { strong: 'ข้ามห้องที่ปิดบอท', rest: 'หรือมีแอดมินตอบไปแล้ว' },
  { strong: 'แจ้งเจ้าของทาง Telegram', rest: 'ทั้งตอนล่มและตอนกลับมา' },
];

export function OutageSettingsTab({
  outage_recovery_enabled,
  outage_recovery_message_th,
  outage_recovery_message_en,
  outage_down_message,
  defaults,
  errors,
  onChange,
}: OutageSettingsTabProps) {
  const previewText =
    outage_recovery_message_th.trim() !== ''
      ? outage_recovery_message_th
      : defaults.outage_recovery_message_th;

  return (
    <div className="space-y-6">
      <Panel
        icon={MessageCircle}
        title="ข้อความตามลูกค้าเมื่อบอทกลับมา"
        description="ลูกค้าที่ทักมาตอนบอทตอบไม่ได้ จะได้ข้อความนี้เมื่อบอทกลับมาใช้งานได้"
      >
        <div className="space-y-5">
          <SettingRow
            label="ส่งข้อความตามลูกค้าอัตโนมัติ"
            htmlFor="outage-recovery-enabled"
            description="ถ้าปิด ระบบยังแจ้งเตือนเจ้าของทาง Telegram แต่ไม่ส่งอะไรหาลูกค้า"
          >
            <Switch
              id="outage-recovery-enabled"
              checked={outage_recovery_enabled}
              onCheckedChange={(checked) => onChange('outage_recovery_enabled', checked)}
            />
          </SettingRow>

          <fieldset
            disabled={!outage_recovery_enabled}
            className="ml-4 pl-4 border-l-2 border-muted space-y-5 mt-2 min-w-0"
          >
            <OutageTextField
              id="outage-recovery-th"
              label="ข้อความภาษาไทย"
              value={outage_recovery_message_th}
              serverError={errors?.outage_recovery_message_th}
              resetLabel="คืนค่ามาตรฐาน ข้อความภาษาไทย"
              onReset={() =>
                onChange('outage_recovery_message_th', defaults.outage_recovery_message_th)
              }
              onChange={(v) => onChange('outage_recovery_message_th', v)}
            />
            <OutageTextField
              id="outage-recovery-en"
              label="ข้อความภาษาอังกฤษ"
              description="ต่อท้ายข้อความไทย เฉพาะลูกค้าที่พิมพ์มาเป็นภาษาอังกฤษ"
              value={outage_recovery_message_en}
              serverError={errors?.outage_recovery_message_en}
              resetLabel="คืนค่ามาตรฐาน ข้อความภาษาอังกฤษ"
              onReset={() =>
                onChange('outage_recovery_message_en', defaults.outage_recovery_message_en)
              }
              onChange={(v) => onChange('outage_recovery_message_en', v)}
            />
            <div
              data-testid="outage-preview"
              aria-disabled={!outage_recovery_enabled}
              className={cn(
                'rounded-lg border bg-muted/40 p-3 space-y-2',
                !outage_recovery_enabled && 'opacity-60'
              )}
            >
              <div className="text-xs font-medium text-muted-foreground">ตัวอย่างที่ลูกค้าเห็น</div>
              <div className="rounded-2xl rounded-bl-sm border bg-card px-3 py-2 text-sm whitespace-pre-wrap break-words max-w-full">
                {previewText}
              </div>
            </div>
          </fieldset>
        </div>
      </Panel>

      <Panel
        icon={TriangleAlert}
        title="ข้อความตอนบอทยังตอบไม่ได้"
        description="บอทตอบข้อความนี้แทนคำตอบปกติ ระหว่างที่ระบบยังขัดข้อง"
      >
        <div className="space-y-2">
          <OutageTextField
            id="outage-down"
            label="ข้อความ"
            value={outage_down_message}
            serverError={errors?.outage_down_message}
            resetLabel="คืนค่ามาตรฐาน ข้อความ"
            onReset={() => onChange('outage_down_message', defaults.outage_down_message)}
            onChange={(v) => onChange('outage_down_message', v)}
          />
        </div>
      </Panel>

      <Panel
        icon={Info}
        title="ระบบทำงานอย่างไร"
        description="กติกาเหล่านี้ตั้งไว้ตายตัว แก้ในหน้านี้ไม่ได้"
      >
        <ul className="space-y-3">
          {OUTAGE_FACTS.map((fact) => (
            <li key={fact.strong} className="flex items-start gap-2 text-sm text-muted-foreground">
              <Check className="size-4 shrink-0 mt-0.5 text-foreground" strokeWidth={2} />
              <span>
                <b className="text-foreground">{fact.strong}</b> {fact.rest}
              </span>
            </li>
          ))}
        </ul>
      </Panel>
    </div>
  );
}
