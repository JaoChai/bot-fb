import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Slider } from '@/components/ui/slider';
import { Input } from '@/components/ui/input';
import { Collapsible, CollapsibleContent } from '@/components/ui/collapsible';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import {
  Shield,
  Clock,
  DollarSign,
  AlertTriangle,
  Info,
  HandMetal
} from 'lucide-react';

export interface FlowSafetySettingsData {
  agent_timeout_seconds: number;
  agent_max_cost_per_request: number | null;
  hitl_enabled: boolean;
  hitl_dangerous_actions: string[];
}

interface FlowSafetySettingsProps {
  settings: FlowSafetySettingsData;
  onChange: <K extends keyof FlowSafetySettingsData>(
    field: K,
    value: FlowSafetySettingsData[K]
  ) => void;
}

const InfoTooltip = ({ children }: { children: React.ReactNode }) => (
  <TooltipProvider>
    <Tooltip>
      <TooltipTrigger asChild>
        <Info className="h-4 w-4 text-muted-foreground cursor-help" />
      </TooltipTrigger>
      <TooltipContent className="max-w-xs">
        <p className="text-sm">{children}</p>
      </TooltipContent>
    </Tooltip>
  </TooltipProvider>
);

const DANGEROUS_ACTIONS = [
  { id: 'send_email', label: 'ส่งอีเมล', description: 'อนุญาตให้ส่งอีเมลได้' },
  { id: 'make_payment', label: 'ชำระเงิน', description: 'ดำเนินการชำระเงิน' },
  { id: 'delete_data', label: 'ลบข้อมูล', description: 'ลบข้อมูลในระบบ' },
  { id: 'update_database', label: 'แก้ไขฐานข้อมูล', description: 'แก้ไขข้อมูลในฐานข้อมูล' },
  { id: 'call_external_api', label: 'เรียก API ภายนอก', description: 'เรียกใช้ API ภายนอก' },
];

export function FlowSafetySettings({ settings, onChange }: FlowSafetySettingsProps) {
  const handleDangerousActionToggle = (actionId: string, enabled: boolean) => {
    const current = settings.hitl_dangerous_actions || [];
    if (enabled) {
      onChange('hitl_dangerous_actions', [...current, actionId]);
    } else {
      onChange('hitl_dangerous_actions', current.filter((a) => a !== actionId));
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3 mb-4">
        <div className="p-2 rounded-lg bg-orange-500/10">
          <Shield className="h-5 w-5 text-orange-500" />
        </div>
        <div>
          <h3 className="font-semibold">Agent Safety Controls</h3>
          <p className="text-sm text-muted-foreground">
            ป้องกัน runaway costs และ dangerous actions
          </p>
        </div>
      </div>

      {/* Timeout */}
      <Card>
        <CardHeader className="pb-3">
          <div className="flex items-center gap-2">
            <Clock className="h-4 w-4 text-muted-foreground" />
            <CardTitle className="text-base">Timeout</CardTitle>
          </div>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Label className="font-medium">Agent Timeout</Label>
              <InfoTooltip>
                หยุด agent loop อัตโนมัติถ้าทำงานเกินเวลาที่กำหนด
              </InfoTooltip>
            </div>
            <span className="text-sm font-mono bg-muted px-2 py-1 rounded">
              {settings.agent_timeout_seconds} วินาที
            </span>
          </div>
          <Slider
            min={30}
            max={300}
            step={10}
            value={[settings.agent_timeout_seconds]}
            onValueChange={(value) => onChange('agent_timeout_seconds', value[0])}
            className="cursor-pointer"
          />
          <div className="flex justify-between text-xs text-muted-foreground">
            <span>30 วินาที</span>
            <span>5 นาที</span>
          </div>
        </CardContent>
      </Card>

      {/* Cost Limit */}
      <Card>
        <CardHeader className="pb-3">
          <div className="flex items-center gap-2">
            <DollarSign className="h-4 w-4 text-muted-foreground" />
            <CardTitle className="text-base">Cost Limit</CardTitle>
          </div>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Label className="font-medium">Max Cost per Request</Label>
              <InfoTooltip>
                หยุด agent ถ้าค่าใช้จ่ายเกินที่กำหนด (USD)
              </InfoTooltip>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <span className="text-muted-foreground">$</span>
            <Input
              type="number"
              step="0.01"
              min="0.01"
              max="10"
              placeholder="0.50"
              value={settings.agent_max_cost_per_request ?? ''}
              onChange={(e) => {
                const val = e.target.value ? parseFloat(e.target.value) : null;
                onChange('agent_max_cost_per_request', val);
              }}
              className="w-32"
            />
            <span className="text-sm text-muted-foreground">ต่อ request</span>
          </div>
          <p className="text-xs text-muted-foreground">
            เว้นว่างไว้ = ไม่จำกัด (ระวัง: อาจเกิด runaway costs)
          </p>
        </CardContent>
      </Card>

      {/* HITL */}
      <Card>
        <CardHeader className="pb-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <HandMetal className="h-4 w-4 text-muted-foreground" />
              <CardTitle className="text-base">Human-in-the-Loop (HITL)</CardTitle>
            </div>
            <Switch
              checked={settings.hitl_enabled}
              onCheckedChange={(checked) => onChange('hitl_enabled', checked)}
            />
          </div>
          <p className="text-sm text-muted-foreground">
            ขออนุมัติจากมนุษย์ก่อนดำเนินการที่อันตราย
          </p>
        </CardHeader>

        <Collapsible open={settings.hitl_enabled}>
          <CollapsibleContent>
            <CardContent className="pt-0 space-y-3 border-t">
              <div className="pt-3">
                <Label className="font-medium mb-3 block">
                  เลือก Actions ที่ต้องขออนุมัติ:
                </Label>
                <div className="space-y-2">
                  {DANGEROUS_ACTIONS.map((action) => (
                    <div
                      key={action.id}
                      className="flex items-center justify-between p-3 rounded-lg border hover:bg-muted/50 transition-colors"
                    >
                      <div className="flex items-center gap-3">
                        <AlertTriangle className="h-4 w-4 text-amber-500" />
                        <div>
                          <span className="font-medium">{action.label}</span>
                          <p className="text-xs text-muted-foreground">
                            {action.description}
                          </p>
                        </div>
                      </div>
                      <Switch
                        checked={(settings.hitl_dangerous_actions || []).includes(action.id)}
                        onCheckedChange={(checked) =>
                          handleDangerousActionToggle(action.id, checked)
                        }
                      />
                    </div>
                  ))}
                </div>
              </div>

              {/* Info Box */}
              <div className="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-lg p-3 text-sm">
                <div className="flex gap-2">
                  <AlertTriangle className="h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                  <p className="text-amber-800 dark:text-amber-200">
                    เมื่อ agent ต้องการดำเนินการที่เลือกไว้ ระบบจะส่งการแจ้งเตือนและรอการอนุมัติจากคุณ
                    (timeout 60 วินาที)
                  </p>
                </div>
              </div>
            </CardContent>
          </CollapsibleContent>
        </Collapsible>
      </Card>
    </div>
  );
}
