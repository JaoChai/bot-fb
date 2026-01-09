/**
 * ResponseHoursSection - Business hours configuration component
 * Part of 006-bots-refactor feature (T038)
 *
 * Handles:
 * - Response hours enable/disable
 * - Weekly schedule with multiple time slots per day
 * - Timezone selection
 * - Offline message configuration
 */

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Clock, Plus, Trash2, Copy } from 'lucide-react';
import {
  type ResponseHoursSectionProps,
  DAYS,
  TIMEZONES,
} from './types';

export function ResponseHoursSection({
  formData,
  onChange,
  onDayToggle,
  onSlotChange,
  onAddSlot,
  onRemoveSlot,
  onApplyToAllDays,
  disabled = false,
}: ResponseHoursSectionProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-lg flex items-center gap-2">
          <Clock className="h-5 w-5" />
          Response Hours
        </CardTitle>
        <p className="text-sm text-muted-foreground mt-2">
          บอทจะตอบตามวันและเวลาที่กำหนด
        </p>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex items-center justify-between">
          <Label htmlFor="response-hours" className="font-semibold">
            เปิดใช้งาน Response Hours
          </Label>
          <Switch
            id="response-hours"
            checked={formData.response_hours_enabled}
            onCheckedChange={(checked) =>
              onChange('response_hours_enabled', checked)
            }
            disabled={disabled}
          />
        </div>

        {formData.response_hours_enabled && (
          <div className="space-y-4 pt-4 border-t">
            {/* Timezone Selection */}
            <div className="space-y-2">
              <Label className="font-semibold">Timezone</Label>
              <Select
                value={formData.response_hours_timezone}
                onValueChange={(value) =>
                  onChange('response_hours_timezone', value)
                }
                disabled={disabled}
              >
                <SelectTrigger className="w-full sm:w-64">
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
            </div>

            {/* Day Schedule */}
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <Label className="font-semibold">ตารางเวลา</Label>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={onApplyToAllDays}
                  className="text-xs"
                  disabled={disabled}
                >
                  <Copy className="h-3 w-3 mr-1" />
                  ใช้เวลาจันทร์กับทุกวัน
                </Button>
              </div>

              <div className="space-y-2 rounded-lg border p-3">
                {DAYS.map((day) => (
                  <div key={day.key} className="space-y-2">
                    <div className="flex items-center gap-3">
                      <Switch
                        checked={formData.response_hours[day.key].enabled}
                        onCheckedChange={(checked) =>
                          onDayToggle(day.key, checked)
                        }
                        disabled={disabled}
                      />
                      <span
                        className={`w-20 text-sm font-medium ${
                          !formData.response_hours[day.key].enabled
                            ? 'text-muted-foreground'
                            : ''
                        }`}
                      >
                        {day.label}
                      </span>

                      {formData.response_hours[day.key].enabled ? (
                        <div className="flex-1 space-y-1">
                          {formData.response_hours[day.key].slots.map(
                            (slot, slotIndex) => (
                              <div
                                key={slotIndex}
                                className="flex items-center gap-2"
                              >
                                <Input
                                  type="time"
                                  value={slot.start}
                                  onChange={(e) =>
                                    onSlotChange(
                                      day.key,
                                      slotIndex,
                                      'start',
                                      e.target.value
                                    )
                                  }
                                  className="w-28"
                                  disabled={disabled}
                                />
                                <span className="text-muted-foreground">-</span>
                                <Input
                                  type="time"
                                  value={slot.end}
                                  onChange={(e) =>
                                    onSlotChange(
                                      day.key,
                                      slotIndex,
                                      'end',
                                      e.target.value
                                    )
                                  }
                                  className="w-28"
                                  disabled={disabled}
                                />
                                {formData.response_hours[day.key].slots.length >
                                  1 && (
                                  <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    onClick={() =>
                                      onRemoveSlot(day.key, slotIndex)
                                    }
                                    className="h-8 w-8 text-muted-foreground hover:text-destructive"
                                    disabled={disabled}
                                  >
                                    <Trash2 className="h-4 w-4" />
                                  </Button>
                                )}
                              </div>
                            )
                          )}
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => onAddSlot(day.key)}
                            className="text-xs text-muted-foreground"
                            disabled={disabled}
                          >
                            <Plus className="h-3 w-3 mr-1" />
                            เพิ่มช่วงเวลา
                          </Button>
                        </div>
                      ) : (
                        <span className="text-sm text-muted-foreground">
                          ปิด
                        </span>
                      )}
                    </div>
                    {day.key !== 'sun' && <div className="border-b" />}
                  </div>
                ))}
              </div>
            </div>

            {/* Offline Message */}
            <div className="space-y-2">
              <Label htmlFor="offline-message" className="font-semibold">
                ข้อความนอกเวลาทำการ
              </Label>
              <Textarea
                id="offline-message"
                placeholder="ตัวอย่าง: ขณะนี้อยู่นอกเวลาทำการ กรุณาติดต่อใหม่ในเวลา 09:00-18:00 น. วันจันทร์-ศุกร์ครับ"
                value={formData.offline_message ?? ''}
                onChange={(e) =>
                  onChange('offline_message', e.target.value || null)
                }
                rows={2}
                disabled={disabled}
              />
              <p className="text-xs text-muted-foreground">
                เว้นว่างไว้ = บอทจะไม่ตอบนอกเวลาทำการ
              </p>
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
