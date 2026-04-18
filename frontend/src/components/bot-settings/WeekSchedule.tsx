import { Plus, X } from 'lucide-react';
import { Switch } from '@/components/ui/switch';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { DayKey, ResponseHoursConfig } from '@/hooks/useResponseHours';

const DAY_LABELS: Record<DayKey, string> = {
  mon: 'จันทร์',
  tue: 'อังคาร',
  wed: 'พุธ',
  thu: 'พฤหัสบดี',
  fri: 'ศุกร์',
  sat: 'เสาร์',
  sun: 'อาทิตย์',
};

const DAY_ORDER: DayKey[] = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

interface WeekScheduleProps {
  schedule: ResponseHoursConfig;
  onDayToggle: (day: DayKey, enabled: boolean) => void;
  onSlotChange: (day: DayKey, slotIndex: number, field: 'start' | 'end', value: string) => void;
  onAddSlot: (day: DayKey) => void;
  onRemoveSlot: (day: DayKey, slotIndex: number) => void;
  onApplyToAllDays: () => void;
}

export function WeekSchedule({
  schedule,
  onDayToggle,
  onSlotChange,
  onAddSlot,
  onRemoveSlot,
  onApplyToAllDays,
}: WeekScheduleProps) {
  return (
    <div className="space-y-4">
      <div className="divide-y rounded-md border bg-background">
        {DAY_ORDER.map((day) => {
          const config = schedule[day];
          const isEnabled = config.enabled;
          return (
            <div
              key={day}
              className={cn(
                'flex flex-col gap-3 px-3 py-3 sm:flex-row sm:items-start transition-opacity',
                !isEnabled && 'opacity-60',
              )}
            >
              {/* Toggle + day label */}
              <div className="flex items-center gap-3 sm:w-32 shrink-0 pt-1">
                <Switch
                  checked={isEnabled}
                  onCheckedChange={(checked) => onDayToggle(day, checked)}
                  aria-label={`เปิดใช้งาน ${DAY_LABELS[day]}`}
                />
                <span className="text-sm font-medium">{DAY_LABELS[day]}</span>
              </div>

              {/* Slots */}
              <div className="flex-1 flex flex-wrap items-center gap-2">
                {config.slots.map((slot, idx) => (
                  <div
                    key={idx}
                    className="inline-flex items-center gap-1 rounded-md border bg-card px-2 py-1"
                  >
                    <Input
                      type="time"
                      value={slot.start}
                      disabled={!isEnabled}
                      onChange={(e) => onSlotChange(day, idx, 'start', e.target.value)}
                      className="h-7 w-24 border-0 shadow-none px-1 text-sm tabular-nums focus-visible:ring-0"
                    />
                    <span className="text-muted-foreground text-xs">–</span>
                    <Input
                      type="time"
                      value={slot.end}
                      disabled={!isEnabled}
                      onChange={(e) => onSlotChange(day, idx, 'end', e.target.value)}
                      className="h-7 w-24 border-0 shadow-none px-1 text-sm tabular-nums focus-visible:ring-0"
                    />
                    {config.slots.length > 1 && (
                      <button
                        type="button"
                        onClick={() => onRemoveSlot(day, idx)}
                        disabled={!isEnabled}
                        aria-label="ลบช่วงเวลา"
                        className="ml-0.5 inline-flex h-5 w-5 items-center justify-center rounded text-muted-foreground hover:bg-destructive/10 hover:text-destructive transition-colors disabled:opacity-50"
                      >
                        <X className="h-3 w-3" strokeWidth={1.75} />
                      </button>
                    )}
                  </div>
                ))}
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => onAddSlot(day)}
                  disabled={!isEnabled}
                  className="h-7 px-2 text-xs"
                >
                  <Plus className="h-3 w-3 mr-1" strokeWidth={2} />
                  ช่วงเวลา
                </Button>
              </div>
            </div>
          );
        })}
      </div>

      <div className="flex justify-end">
        <Button type="button" variant="outline" size="sm" onClick={onApplyToAllDays}>
          ใช้ค่าวันจันทร์กับทุกวัน
        </Button>
      </div>
    </div>
  );
}
