import { Clock, Plus, Trash2, Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { SettingSection, SettingRow } from '@/components/connections';
import {
  DAYS,
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
    <div className="space-y-6">
      <div className="border rounded-lg p-5 space-y-5">
        <SettingSection
          icon={Clock}
          title="Response Hours"
          description="บอทจะตอบตามวันและเวลาที่กำหนด"
        >
          <SettingRow label="เปิดใช้งาน Response Hours" htmlFor="response-hours-toggle">
            <Switch
              id="response-hours-toggle"
              checked={response_hours_enabled}
              onCheckedChange={(checked) => onChange('response_hours_enabled', checked)}
            />
          </SettingRow>
        </SettingSection>

        {response_hours_enabled && (
          <div className="space-y-5 border-t pt-5">
            {/* Timezone */}
            <SettingRow label="Timezone" htmlFor="timezone-select" orientation="vertical">
              <Select
                value={response_hours_timezone}
                onValueChange={(value) => onChange('response_hours_timezone', value)}
              >
                <SelectTrigger id="timezone-select" className="w-full sm:w-72">
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

            {/* Day Schedule */}
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-foreground">ตารางเวลา</span>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={onApplyToAllDays}
                  className="h-8 text-xs gap-1.5"
                >
                  <Copy className="h-3 w-3" />
                  ใช้เวลาจันทร์กับทุกวัน
                </Button>
              </div>

              <div className="rounded-lg border divide-y">
                {DAYS.map((day) => {
                  const schedule = response_hours[day.key];
                  return (
                    <div key={day.key} className="px-4 py-3 space-y-2">
                      <div className="flex items-center gap-3 min-h-[40px]">
                        <Switch
                          checked={schedule.enabled}
                          onCheckedChange={(checked) => onDayToggle(day.key, checked)}
                        />
                        <span
                          className={`w-20 text-sm font-medium transition-colors duration-150 ${
                            !schedule.enabled ? 'text-muted-foreground' : 'text-foreground'
                          }`}
                        >
                          {day.label}
                        </span>

                        {schedule.enabled ? (
                          <div className="flex-1 space-y-1.5">
                            {schedule.slots.map((slot, slotIndex) => (
                              <div key={slotIndex} className="flex items-center gap-2">
                                <Input
                                  type="time"
                                  value={slot.start}
                                  onChange={(e) =>
                                    onSlotChange(day.key, slotIndex, 'start', e.target.value)
                                  }
                                  className="w-28 h-8 text-sm"
                                />
                                <span className="text-muted-foreground text-xs">—</span>
                                <Input
                                  type="time"
                                  value={slot.end}
                                  onChange={(e) =>
                                    onSlotChange(day.key, slotIndex, 'end', e.target.value)
                                  }
                                  className="w-28 h-8 text-sm"
                                />
                                {schedule.slots.length > 1 && (
                                  <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() => onRemoveSlot(day.key, slotIndex)}
                                    className="h-8 w-8 text-muted-foreground hover:text-destructive transition-colors duration-150"
                                  >
                                    <Trash2 className="h-3.5 w-3.5" />
                                  </Button>
                                )}
                              </div>
                            ))}
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              onClick={() => onAddSlot(day.key)}
                              className="h-7 text-xs text-muted-foreground gap-1 px-2"
                            >
                              <Plus className="h-3 w-3" />
                              เพิ่มช่วงเวลา
                            </Button>
                          </div>
                        ) : (
                          <span className="text-sm text-muted-foreground">ปิด</span>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>

            {/* Offline Message */}
            <SettingRow
              label="ข้อความนอกเวลาทำการ"
              htmlFor="offline-message"
              orientation="vertical"
              description="เว้นว่างไว้เพื่อไม่ตอบกลับนอกเวลาทำการ"
            >
              <Textarea
                id="offline-message"
                placeholder="ตัวอย่าง: ขณะนี้อยู่นอกเวลาทำการ กรุณาติดต่อใหม่ในเวลา 09:00-18:00 น. วันจันทร์-ศุกร์ครับ"
                value={offline_message}
                onChange={(e) => onChange('offline_message', e.target.value)}
                rows={2}
                className="transition-colors duration-150"
              />
            </SettingRow>
          </div>
        )}
      </div>
    </div>
  );
}
