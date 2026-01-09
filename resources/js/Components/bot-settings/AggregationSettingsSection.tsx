/**
 * AggregationSettingsSection - Message aggregation settings component
 * Part of 006-bots-refactor feature (T037)
 *
 * Handles:
 * - Multiple bubbles configuration (min/max/delimiter)
 * - Wait for multiple bubbles timing
 * - Smart aggregation with adaptive timing
 */

import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Slider } from '@/Components/ui/slider';
import { Input } from '@/Components/ui/input';
import { type SectionProps } from './types';

export function AggregationSettingsSection({
  formData,
  onChange,
  disabled = false,
}: SectionProps) {
  // Convert ms to seconds for display
  const waitSeconds = formData.wait_multiple_bubbles_ms / 1000;
  const smartMinSeconds = formData.smart_min_wait_ms / 1000;
  const smartMaxSeconds = formData.smart_max_wait_ms / 1000;

  return (
    <div className="space-y-6">
      {/* Multiple Bubbles */}
      <Card>
        <CardHeader>
          <CardTitle className="text-lg">การตอบแบบหลายบอลลูน</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center justify-between">
            <Label htmlFor="bubbles" className="font-semibold">
              เปิดใช้งาน Multiple Bubbles
            </Label>
            <Switch
              id="bubbles"
              checked={formData.multiple_bubbles_enabled}
              onCheckedChange={(checked) =>
                onChange('multiple_bubbles_enabled', checked)
              }
              disabled={disabled}
            />
          </div>

          {formData.multiple_bubbles_enabled && (
            <div className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="bubbles-min" className="font-semibold">
                  จำนวนบอลลูนขั้นต่ำ: {formData.multiple_bubbles_min}
                </Label>
                <Slider
                  id="bubbles-min"
                  min={1}
                  max={3}
                  step={1}
                  value={[formData.multiple_bubbles_min]}
                  onValueChange={(value) =>
                    onChange('multiple_bubbles_min', value[0])
                  }
                  disabled={disabled}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="bubbles-max" className="font-semibold">
                  จำนวนบอลลูนสูงสุด: {formData.multiple_bubbles_max}
                </Label>
                <Slider
                  id="bubbles-max"
                  min={2}
                  max={5}
                  step={1}
                  value={[formData.multiple_bubbles_max]}
                  onValueChange={(value) =>
                    onChange('multiple_bubbles_max', value[0])
                  }
                  disabled={disabled}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="bubbles-delimiter" className="font-semibold">
                  ตัวคั่นระหว่างบอลลูน
                </Label>
                <Input
                  id="bubbles-delimiter"
                  placeholder="\\n\\n (ขึ้นบรรทัดใหม่ 2 ครั้ง)"
                  value={formData.multiple_bubbles_delimiter}
                  onChange={(e) =>
                    onChange('multiple_bubbles_delimiter', e.target.value)
                  }
                  disabled={disabled}
                />
                <p className="text-xs text-muted-foreground">
                  ใช้ \n สำหรับขึ้นบรรทัดใหม่
                </p>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Wait Multiple Bubbles */}
      <Card>
        <CardHeader>
          <CardTitle className="text-lg">รออ่านหลายบอลลูน</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center justify-between">
            <Label htmlFor="wait-bubbles" className="font-semibold">
              เปิดใช้งาน
            </Label>
            <Switch
              id="wait-bubbles"
              checked={formData.wait_multiple_bubbles_enabled}
              onCheckedChange={(checked) =>
                onChange('wait_multiple_bubbles_enabled', checked)
              }
              disabled={disabled}
            />
          </div>

          {formData.wait_multiple_bubbles_enabled && (
            <div className="space-y-2">
              <Label htmlFor="wait-seconds" className="font-semibold">
                เวลารอ: {waitSeconds.toFixed(1)} วินาที
              </Label>
              <Slider
                id="wait-seconds"
                min={500}
                max={20000}
                step={500}
                value={[formData.wait_multiple_bubbles_ms]}
                onValueChange={(value) =>
                  onChange('wait_multiple_bubbles_ms', value[0])
                }
                disabled={disabled}
              />
            </div>
          )}

          {/* Smart Aggregation Section - Show only if wait_multiple_bubbles_enabled */}
          {formData.wait_multiple_bubbles_enabled && (
            <div className="space-y-4 mt-4 pl-4 border-l-2 border-blue-200">
              <h4 className="text-md font-medium text-gray-700 dark:text-gray-300">
                Smart Aggregation (ปรับเวลารออัตโนมัติ)
              </h4>

              {/* Toggle: Enable Smart Aggregation */}
              <div className="flex items-center justify-between">
                <div>
                  <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
                    เปิดใช้ Smart Aggregation
                  </span>
                  <p className="text-xs text-gray-500 dark:text-gray-400">
                    ปรับเวลารอตามความเร็วการพิมพ์ของลูกค้า
                  </p>
                </div>
                <Switch
                  id="smart-aggregation"
                  checked={formData.smart_aggregation_enabled}
                  onCheckedChange={(checked) =>
                    onChange('smart_aggregation_enabled', checked)
                  }
                  disabled={disabled}
                />
              </div>

              {/* Show advanced options when smart aggregation enabled */}
              {formData.smart_aggregation_enabled && (
                <div className="space-y-4 mt-2 pl-4">
                  {/* Min Wait Time Slider */}
                  <div className="space-y-2">
                    <Label
                      htmlFor="smart-min-wait"
                      className="text-sm text-gray-600 dark:text-gray-400"
                    >
                      เวลารอขั้นต่ำ: {smartMinSeconds.toFixed(1)} วินาที
                    </Label>
                    <Slider
                      id="smart-min-wait"
                      min={300}
                      max={3000}
                      step={100}
                      value={[formData.smart_min_wait_ms]}
                      onValueChange={(value) =>
                        onChange('smart_min_wait_ms', value[0])
                      }
                      disabled={disabled}
                    />
                  </div>

                  {/* Max Wait Time Slider */}
                  <div className="space-y-2">
                    <Label
                      htmlFor="smart-max-wait"
                      className="text-sm text-gray-600 dark:text-gray-400"
                    >
                      เวลารอสูงสุด: {smartMaxSeconds.toFixed(1)} วินาที
                    </Label>
                    <Slider
                      id="smart-max-wait"
                      min={1000}
                      max={10000}
                      step={500}
                      value={[formData.smart_max_wait_ms]}
                      onValueChange={(value) =>
                        onChange('smart_max_wait_ms', value[0])
                      }
                      disabled={disabled}
                    />
                  </div>

                  {/* Toggle: Early Trigger */}
                  <div className="flex items-center justify-between">
                    <div>
                      <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
                        ตอบทันทีสำหรับข้อความสมบูรณ์
                      </span>
                      <p className="text-xs text-gray-500 dark:text-gray-400">
                        ไม่ต้องรอถ้าข้อความจบด้วย "ครับ" "ค่ะ" หรือ "?"
                      </p>
                    </div>
                    <Switch
                      id="smart-early-trigger"
                      checked={formData.smart_early_trigger_enabled}
                      onCheckedChange={(checked) =>
                        onChange('smart_early_trigger_enabled', checked)
                      }
                      disabled={disabled}
                    />
                  </div>

                  {/* Toggle: Per-User Learning */}
                  <div className="flex items-center justify-between">
                    <div>
                      <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
                        เรียนรู้พฤติกรรมการพิมพ์
                      </span>
                      <p className="text-xs text-gray-500 dark:text-gray-400">
                        ปรับเวลารอตามความเร็วพิมพ์ของลูกค้าแต่ละคน
                      </p>
                    </div>
                    <Switch
                      id="smart-per-user-learning"
                      checked={formData.smart_per_user_learning_enabled}
                      onCheckedChange={(checked) =>
                        onChange('smart_per_user_learning_enabled', checked)
                      }
                      disabled={disabled}
                    />
                  </div>
                </div>
              )}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
