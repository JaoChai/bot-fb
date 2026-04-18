import { Clock, MessageSquareOff } from 'lucide-react';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { SettingRow } from '@/components/connections';
import { Panel } from '@/components/common';
import { WeekSchedule } from './WeekSchedule';
import {
  TIMEZONES,
  type DayKey,
  type ResponseHoursConfig,
} from '@/hooks/useResponseHours';

interface ResponseHoursTabProps {
  response_hours_enabled: boolean;
  response_hours: ResponseHoursConfig;
  response_hours_timezone: string;
  offline_message: string;
  onChange: (field: string, value: unknown) => void;
  onDayToggle: (day: DayKey, enabled: boolean) => void;
  onSlotChange: (day: DayKey, slotIndex: number, field: 'start' | 'end', value: string) => void;
  onAddSlot: (day: DayKey) => void;
  onRemoveSlot: (day: DayKey, slotIndex: number) => void;
  onApplyToAllDays: () => void;
}

export function ResponseHoursTab({
  response_hours_enabled,
  response_hours,
  response_hours_timezone,
  offline_message,
  onChange,
  onDayToggle,
  onSlotChange,
  onAddSlot,
  onRemoveSlot,
  onApplyToAllDays,
}: ResponseHoursTabProps) {
  return (
    <>
      <Panel
        icon={Clock}
        title="เวลาทำการ"
        description="กำหนดช่วงเวลาที่บอทจะตอบลูกค้าโดยอัตโนมัติ"
      >
        <div className="space-y-4">
          <SettingRow
            label="เปิดใช้งานเวลาทำการ"
            description="ถ้าปิด บอทจะตอบตลอด 24 ชั่วโมง"
            htmlFor="response-hours-enabled"
          >
            <Switch
              id="response-hours-enabled"
              checked={response_hours_enabled}
              onCheckedChange={(checked) => onChange('response_hours_enabled', checked)}
            />
          </SettingRow>

          {response_hours_enabled && (
            <div className="ml-4 pl-4 border-l-2 border-muted space-y-4 mt-2">
              <SettingRow label="เขตเวลา" htmlFor="timezone-select" orientation="vertical">
                <Select
                  value={response_hours_timezone}
                  onValueChange={(v) => onChange('response_hours_timezone', v)}
                >
                  <SelectTrigger id="timezone-select" className="max-w-xs">
                    <SelectValue placeholder="เลือก timezone" />
                  </SelectTrigger>
                  <SelectContent>
                    {TIMEZONES.map((tz) => (
                      <SelectItem key={tz.value} value={tz.value}>
                        {tz.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </SettingRow>
            </div>
          )}
        </div>
      </Panel>

      {response_hours_enabled && (
        <>
          <Panel
            title="ตารางเวลาตอบกลับ"
            description="เลือกวันและกำหนดช่วงเวลาที่บอทจะตอบ"
          >
            <WeekSchedule
              schedule={response_hours}
              onDayToggle={onDayToggle}
              onSlotChange={onSlotChange}
              onAddSlot={onAddSlot}
              onRemoveSlot={onRemoveSlot}
              onApplyToAllDays={onApplyToAllDays}
            />
          </Panel>

          <Panel
            icon={MessageSquareOff}
            title="ข้อความนอกเวลาทำการ"
            description="ส่งข้อความนี้ให้ลูกค้าเมื่อนอกเวลาทำการ"
          >
            <SettingRow
              label="ข้อความ"
              description="เว้นว่างถ้าไม่ต้องการตอบอะไรเลยนอกเวลาทำการ"
              htmlFor="offline-message"
              orientation="vertical"
            >
              <Textarea
                id="offline-message"
                value={offline_message}
                onChange={(e) => onChange('offline_message', e.target.value)}
                placeholder="ตัวอย่าง: ขณะนี้อยู่นอกเวลาทำการ กรุณาติดต่อใหม่ในเวลา 09:00-18:00 น. วันจันทร์-ศุกร์ครับ"
                rows={3}
                className="max-w-lg"
              />
            </SettingRow>
          </Panel>
        </>
      )}
    </>
  );
}
