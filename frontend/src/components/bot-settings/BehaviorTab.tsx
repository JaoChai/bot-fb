import { Users, Layers, Timer, Zap } from 'lucide-react';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';
import { Panel } from '@/components/common';
import { SettingRow } from '@/components/connections';

interface BehaviorTabProps {
  hitl_enabled: boolean;
  multiple_bubbles_enabled: boolean;
  multiple_bubbles_min: number;
  multiple_bubbles_max: number;
  wait_multiple_bubbles_enabled: boolean;
  wait_multiple_bubbles_seconds: number;
  smart_aggregation_enabled: boolean;
  smart_min_wait_seconds: number;
  smart_max_wait_seconds: number;
  smart_early_trigger_enabled: boolean;
  smart_per_user_learning_enabled: boolean;
  onChange: (field: string, value: unknown) => void;
}

export function BehaviorTab({
  hitl_enabled,
  multiple_bubbles_enabled,
  multiple_bubbles_min,
  multiple_bubbles_max,
  wait_multiple_bubbles_enabled,
  wait_multiple_bubbles_seconds,
  smart_aggregation_enabled,
  smart_min_wait_seconds,
  smart_max_wait_seconds,
  smart_early_trigger_enabled,
  smart_per_user_learning_enabled,
  onChange,
}: BehaviorTabProps) {
  return (
    <div className="space-y-6">
      {/* HITL */}
      <Panel
        icon={Users}
        title="HITL (Human in the Loop)"
        description="อนุญาตให้ผู้คนแทรกแซงในการสนทนา"
      >
        <div className="px-5 py-4">
          <SettingRow label="เปิดใช้งาน HITL" htmlFor="hitl-toggle">
            <Switch
              id="hitl-toggle"
              checked={hitl_enabled}
              onCheckedChange={(checked) => onChange('hitl_enabled', checked)}
            />
          </SettingRow>
        </div>
      </Panel>

      {/* Multiple Bubbles */}
      <Panel
        icon={Layers}
        title="การตอบแบบหลายบอลลูน"
        description="แบ่งคำตอบออกเป็นหลายข้อความ"
      >
        <div className="px-5 py-4 space-y-4">
          <SettingRow label="เปิดใช้งาน Multiple Bubbles" htmlFor="bubbles-toggle">
            <Switch
              id="bubbles-toggle"
              checked={multiple_bubbles_enabled}
              onCheckedChange={(checked) => onChange('multiple_bubbles_enabled', checked)}
            />
          </SettingRow>

          {multiple_bubbles_enabled && (
            <div className="ml-4 pl-4 border-l-2 border-muted space-y-4 mt-2">
              <div className="space-y-2">
                <Label htmlFor="bubbles-min" className="text-sm font-medium">
                  จำนวนบอลลูนขั้นต่ำ:{' '}
                  <span className="font-semibold tabular-nums">{multiple_bubbles_min}</span>
                </Label>
                <Slider
                  id="bubbles-min"
                  min={1}
                  max={3}
                  step={1}
                  value={[multiple_bubbles_min]}
                  onValueChange={(v) => onChange('multiple_bubbles_min', v[0])}
                  className="transition-colors duration-150"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="bubbles-max" className="text-sm font-medium">
                  จำนวนบอลลูนสูงสุด:{' '}
                  <span className="font-semibold tabular-nums">{multiple_bubbles_max}</span>
                </Label>
                <Slider
                  id="bubbles-max"
                  min={2}
                  max={5}
                  step={1}
                  value={[multiple_bubbles_max]}
                  onValueChange={(v) => onChange('multiple_bubbles_max', v[0])}
                  className="transition-colors duration-150"
                />
              </div>
            </div>
          )}
        </div>
      </Panel>

      {/* Wait Multiple Bubbles */}
      <Panel
        icon={Timer}
        title="รออ่านหลายบอลลูน"
        description="รอรับข้อความหลายอันก่อนตอบกลับ"
      >
        <div className="px-5 py-4 space-y-4">
          <SettingRow label="เปิดใช้งาน" htmlFor="wait-bubbles-toggle">
            <Switch
              id="wait-bubbles-toggle"
              checked={wait_multiple_bubbles_enabled}
              onCheckedChange={(checked) => onChange('wait_multiple_bubbles_enabled', checked)}
            />
          </SettingRow>

          {wait_multiple_bubbles_enabled && (
            <div className="ml-4 pl-4 border-l-2 border-muted space-y-4 mt-2">
              <div className="space-y-2">
                <Label htmlFor="wait-seconds" className="text-sm font-medium">
                  เวลารอ:{' '}
                  <span className="font-semibold tabular-nums">
                    {wait_multiple_bubbles_seconds.toFixed(1)} วินาที
                  </span>
                </Label>
                <Slider
                  id="wait-seconds"
                  min={0.5}
                  max={20}
                  step={0.5}
                  value={[wait_multiple_bubbles_seconds]}
                  onValueChange={(v) => onChange('wait_multiple_bubbles_seconds', v[0])}
                  className="transition-colors duration-150"
                />
              </div>

              {/* Smart Aggregation */}
              <div className="rounded-lg border bg-muted/20 p-4 space-y-4">
                <div className="flex items-center gap-2">
                  <Zap className="h-4 w-4 text-muted-foreground" strokeWidth={1.5} />
                  <span className="text-sm font-semibold">Smart Aggregation</span>
                  <span className="text-xs text-muted-foreground">(ปรับเวลารออัตโนมัติ)</span>
                </div>

                <SettingRow
                  label="เปิดใช้ Smart Aggregation"
                  description="ปรับเวลารอตามความเร็วการพิมพ์ของลูกค้า"
                  htmlFor="smart-aggregation-toggle"
                >
                  <Switch
                    id="smart-aggregation-toggle"
                    checked={smart_aggregation_enabled}
                    onCheckedChange={(checked) => onChange('smart_aggregation_enabled', checked)}
                  />
                </SettingRow>

                {smart_aggregation_enabled && (
                  <div className="ml-4 pl-4 border-l-2 border-muted space-y-4 mt-2">
                    <div className="space-y-2">
                      <Label htmlFor="smart-min-wait" className="text-sm font-medium">
                        เวลารอขั้นต่ำ:{' '}
                        <span className="font-semibold tabular-nums">
                          {smart_min_wait_seconds.toFixed(1)} วินาที
                        </span>
                      </Label>
                      <Slider
                        id="smart-min-wait"
                        min={0.3}
                        max={3}
                        step={0.1}
                        value={[smart_min_wait_seconds]}
                        onValueChange={(v) => onChange('smart_min_wait_seconds', v[0])}
                        className="transition-colors duration-150"
                      />
                    </div>

                    <div className="space-y-2">
                      <Label htmlFor="smart-max-wait" className="text-sm font-medium">
                        เวลารอสูงสุด:{' '}
                        <span className="font-semibold tabular-nums">
                          {smart_max_wait_seconds.toFixed(1)} วินาที
                        </span>
                      </Label>
                      <Slider
                        id="smart-max-wait"
                        min={1}
                        max={10}
                        step={0.5}
                        value={[smart_max_wait_seconds]}
                        onValueChange={(v) => onChange('smart_max_wait_seconds', v[0])}
                        className="transition-colors duration-150"
                      />
                    </div>

                    <SettingRow
                      label="ตอบทันทีสำหรับข้อความสมบูรณ์"
                      description='ไม่ต้องรอถ้าข้อความจบด้วย "ครับ" "ค่ะ" หรือ "?"'
                      htmlFor="smart-early-trigger"
                    >
                      <Switch
                        id="smart-early-trigger"
                        checked={smart_early_trigger_enabled}
                        onCheckedChange={(checked) => onChange('smart_early_trigger_enabled', checked)}
                      />
                    </SettingRow>

                    <SettingRow
                      label="เรียนรู้พฤติกรรมการพิมพ์"
                      description="ปรับเวลารอตามความเร็วพิมพ์ของลูกค้าแต่ละคน"
                      htmlFor="smart-per-user-learning"
                    >
                      <Switch
                        id="smart-per-user-learning"
                        checked={smart_per_user_learning_enabled}
                        onCheckedChange={(checked) =>
                          onChange('smart_per_user_learning_enabled', checked)
                        }
                      />
                    </SettingRow>
                  </div>
                )}
              </div>
            </div>
          )}
        </div>
      </Panel>
    </div>
  );
}
