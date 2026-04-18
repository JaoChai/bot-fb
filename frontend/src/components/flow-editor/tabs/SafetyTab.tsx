import {
  Shield,
  Sparkles,
  ShieldCheck,
  Gavel,
  MessageCircle,
  Clock,
  DollarSign,
  AlertTriangle,
  Info,
} from 'lucide-react';
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Slider } from '@/components/ui/slider';
import { Input } from '@/components/ui/input';
import { Collapsible, CollapsibleContent } from '@/components/ui/collapsible';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { Panel } from '@/components/common';
import type { FlowSafetySettingsData } from '@/components/flows';

// Synced with backend AgentSafetyService.php $defaultDangerousPatterns
const DANGEROUS_ACTIONS = [
  { id: 'send_email', label: 'ส่งอีเมล', description: 'ส่งอีเมลในนามของ user' },
  { id: 'make_payment', label: 'ชำระเงิน', description: 'ดำเนินการชำระเงินหรือโอนเงิน' },
  { id: 'delete_*', label: 'ลบข้อมูล', description: 'ลบข้อมูลในระบบ (รวม remove, destroy, drop)' },
  { id: 'update_*', label: 'แก้ไขข้อมูล', description: 'แก้ไขหรืออัปเดตข้อมูลในฐานข้อมูล' },
  { id: 'call_external_api', label: 'เรียก API ภายนอก', description: 'เรียกใช้ API หรือ webhook ภายนอก' },
];

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

interface SafetyTabProps {
  safetySettings: FlowSafetySettingsData;
  knowledgeBasesCount: number;
  secondAIEnabled: boolean;
  secondAIOptions: { factCheck: boolean; policy: boolean; personality: boolean };
  onSafetyChange: (field: string, value: unknown) => void;
  onSecondAIToggle: (enabled: boolean) => void;
  onSecondAIOptionsChange: (options: { factCheck: boolean; policy: boolean; personality: boolean }) => void;
}

export function SafetyTab({
  safetySettings,
  knowledgeBasesCount,
  secondAIEnabled,
  secondAIOptions,
  onSafetyChange,
  onSecondAIToggle,
  onSecondAIOptionsChange,
}: SafetyTabProps) {
  const handleSecondAIOption = (key: keyof typeof secondAIOptions, checked: boolean) => {
    onSecondAIOptionsChange({ ...secondAIOptions, [key]: checked });
  };

  const handleDangerousActionToggle = (actionId: string, enabled: boolean) => {
    const current = safetySettings.hitl_dangerous_actions || [];
    if (enabled) {
      onSafetyChange('hitl_dangerous_actions', [...current, actionId]);
    } else {
      onSafetyChange('hitl_dangerous_actions', current.filter((a) => a !== actionId));
    }
  };

  const secondAICheckCount = [
    secondAIOptions.factCheck,
    secondAIOptions.policy,
    secondAIOptions.personality,
  ].filter(Boolean).length;

  return (
    <Panel
      title="ความปลอดภัย"
      description="ตั้งค่าขีดจำกัด การตรวจสอบ และการอนุมัติของ Agent"
      icon={Shield}
      tone="secure"
    >
      <div className="space-y-6">
        {/* Summary strip */}
        <div className="flex flex-wrap items-center gap-2 rounded-md border bg-muted/30 px-3 py-2 text-xs">
          <span className="text-muted-foreground">สถานะปัจจุบัน:</span>
          <Badge variant="outline" className="gap-1 tabular-nums">
            <Clock className="h-3 w-3" strokeWidth={1.5} />
            Timeout {safetySettings.agent_timeout_seconds}s
          </Badge>
          {safetySettings.agent_max_cost_per_request !== null && (
            <Badge variant="outline" className="gap-1 tabular-nums">
              <DollarSign className="h-3 w-3" strokeWidth={1.5} />
              ${safetySettings.agent_max_cost_per_request}/req
            </Badge>
          )}
          {secondAIEnabled && (
            <Badge variant="outline" className="gap-1 tabular-nums">
              <Sparkles className="h-3 w-3" strokeWidth={1.5} />
              Second AI · {secondAICheckCount} checks
            </Badge>
          )}
          {safetySettings.hitl_enabled && (
            <Badge variant="outline" className="gap-1 tabular-nums">
              <ShieldCheck className="h-3 w-3" strokeWidth={1.5} />
              HITL · {safetySettings.hitl_dangerous_actions?.length ?? 0} actions
            </Badge>
          )}
          {!secondAIEnabled && !safetySettings.hitl_enabled && (
            <span className="text-muted-foreground">ไม่มีระบบความปลอดภัยเพิ่มเติม</span>
          )}
        </div>

        {/* Section: Agent Safety */}
        <div className="space-y-4">
          <h3 className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground mb-3">
            Agent Safety
          </h3>

          {/* Timeout */}
          <div className="space-y-3">
            <div className="flex items-center gap-2">
              <Clock className="h-4 w-4 text-muted-foreground" strokeWidth={1.5} />
              <span className="text-sm font-semibold">Timeout</span>
            </div>
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Label className="font-medium">Agent Timeout</Label>
                <InfoTooltip>หยุด agent loop อัตโนมัติถ้าทำงานเกินเวลาที่กำหนด</InfoTooltip>
              </div>
              <span className="text-sm font-mono tabular-nums bg-muted px-2 py-1 rounded">
                {safetySettings.agent_timeout_seconds} วินาที
              </span>
            </div>
            <Slider
              min={30}
              max={300}
              step={10}
              value={[safetySettings.agent_timeout_seconds]}
              onValueChange={(value) => onSafetyChange('agent_timeout_seconds', value[0])}
              className="cursor-pointer"
            />
            <div className="flex justify-between text-xs text-muted-foreground">
              <span>30 วินาที</span>
              <span>5 นาที</span>
            </div>
          </div>

          {/* Cost Limit */}
          <div className="space-y-3 pt-1">
            <div className="flex items-center gap-2">
              <DollarSign className="h-4 w-4 text-muted-foreground" strokeWidth={1.5} />
              <span className="text-sm font-semibold">Cost Limit</span>
            </div>
            <div className="flex items-center gap-2">
              <Label className="font-medium">Max Cost per Request</Label>
              <InfoTooltip>หยุด agent ถ้าค่าใช้จ่ายเกินที่กำหนด (USD)</InfoTooltip>
            </div>
            <div className="flex items-center gap-2">
              <span className="text-muted-foreground">$</span>
              <Input
                type="number"
                step="0.01"
                min="0.01"
                max="10"
                placeholder="0.50"
                value={safetySettings.agent_max_cost_per_request ?? ''}
                onChange={(e) => {
                  const val = e.target.value ? parseFloat(e.target.value) : null;
                  onSafetyChange('agent_max_cost_per_request', val);
                }}
                className="w-32"
              />
              <span className="text-sm text-muted-foreground">ต่อ request</span>
            </div>
            <p className="text-xs text-muted-foreground">
              เว้นว่างไว้ = ไม่จำกัด (ระวัง: อาจเกิด runaway costs)
            </p>
          </div>
        </div>

        <div className="border-t" />

        {/* Section: Second AI */}
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground mb-1">
                Second AI (ตรวจสอบคำตอบ)
              </h3>
              <p className="text-xs text-muted-foreground">
                ใช้ AI ตัวที่สองเพื่อตรวจสอบและปรับปรุงคำตอบ
              </p>
            </div>
            <Switch
              id="second-ai-toggle"
              checked={secondAIEnabled}
              onCheckedChange={onSecondAIToggle}
            />
          </div>
          <Label htmlFor="second-ai-toggle" className="sr-only">
            เปิดใช้งาน Second AI
          </Label>

          {secondAIEnabled && (
            <div className="space-y-3">
              <p className="text-xs text-muted-foreground">เลือกประเภทการตรวจสอบที่ต้องการ</p>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                {/* Fact Check */}
                <div className="space-y-1">
                  <label className="flex items-center gap-2 cursor-pointer border rounded-md p-3 hover:bg-muted/40 transition-colors">
                    <Checkbox
                      id="second-ai-factcheck"
                      checked={secondAIOptions.factCheck}
                      onCheckedChange={(checked) =>
                        handleSecondAIOption('factCheck', checked === true)
                      }
                    />
                    <ShieldCheck className="h-4 w-4 text-muted-foreground" />
                    <span className="text-sm">Fact Check</span>
                  </label>
                  <p className="text-[11px] text-muted-foreground mt-0.5 px-1">
                    ต้องเลือก Knowledge Base
                  </p>
                </div>

                {/* Policy */}
                <label className="flex items-center gap-2 cursor-pointer border rounded-md p-3 hover:bg-muted/40 transition-colors">
                  <Checkbox
                    id="second-ai-policy"
                    checked={secondAIOptions.policy}
                    onCheckedChange={(checked) =>
                      handleSecondAIOption('policy', checked === true)
                    }
                  />
                  <Gavel className="h-4 w-4 text-muted-foreground" />
                  <span className="text-sm">Policy</span>
                </label>

                {/* Personality */}
                <label className="flex items-center gap-2 cursor-pointer border rounded-md p-3 hover:bg-muted/40 transition-colors">
                  <Checkbox
                    id="second-ai-personality"
                    checked={secondAIOptions.personality}
                    onCheckedChange={(checked) =>
                      handleSecondAIOption('personality', checked === true)
                    }
                  />
                  <MessageCircle className="h-4 w-4 text-muted-foreground" />
                  <span className="text-sm">Personality</span>
                </label>
              </div>

              {/* Fact Check KB warning */}
              {secondAIOptions.factCheck && knowledgeBasesCount === 0 && (
                <div className="flex items-start gap-2 rounded-md border border-amber-500/30 bg-amber-50 dark:bg-amber-950/20 px-3 py-2 text-xs">
                  <AlertTriangle
                    className="h-4 w-4 text-amber-600 dark:text-amber-500 shrink-0 mt-0.5"
                    strokeWidth={1.5}
                  />
                  <span>
                    ยังไม่ได้เลือก Knowledge Base — Fact Check จะไม่มีข้อมูลอ้างอิง
                    ไปเลือกใน tab "Knowledge" ก่อน
                  </span>
                </div>
              )}
            </div>
          )}
        </div>

        <div className="border-t" />

        {/* Section: HITL */}
        <div className="space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h3 className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground mb-1">
                HITL (Human-in-the-Loop)
              </h3>
              <p className="text-xs text-muted-foreground">
                ขออนุมัติจากมนุษย์ก่อนดำเนินการที่อันตราย
              </p>
            </div>
            <Switch
              checked={safetySettings.hitl_enabled}
              onCheckedChange={(checked) => onSafetyChange('hitl_enabled', checked)}
            />
          </div>

          <Collapsible open={safetySettings.hitl_enabled}>
            <CollapsibleContent>
              <div className="space-y-3">
                <Label className="font-medium block">เลือก Actions ที่ต้องขออนุมัติ:</Label>
                <div className="space-y-2">
                  {DANGEROUS_ACTIONS.map((action) => (
                    <div
                      key={action.id}
                      className="flex items-center justify-between p-3 rounded-lg border hover:bg-muted/40 transition-colors"
                    >
                      <div className="flex items-center gap-3">
                        <AlertTriangle
                          className="h-4 w-4 text-muted-foreground"
                          strokeWidth={1.5}
                        />
                        <div>
                          <span className="font-medium text-sm">{action.label}</span>
                          <p className="text-xs text-muted-foreground">{action.description}</p>
                        </div>
                      </div>
                      <Switch
                        checked={(safetySettings.hitl_dangerous_actions || []).includes(action.id)}
                        onCheckedChange={(checked) =>
                          handleDangerousActionToggle(action.id, checked)
                        }
                      />
                    </div>
                  ))}
                </div>

                <div className="bg-muted/40 border rounded-lg p-3 text-sm">
                  <div className="flex gap-2">
                    <AlertTriangle
                      className="h-4 w-4 text-muted-foreground shrink-0 mt-0.5"
                      strokeWidth={1.5}
                    />
                    <p className="text-foreground">
                      เมื่อ agent ต้องการดำเนินการที่เลือกไว้ ระบบจะส่งการแจ้งเตือนและรอการอนุมัติจากคุณ
                      (timeout {safetySettings.agent_timeout_seconds} วินาที)
                    </p>
                  </div>
                </div>
              </div>
            </CollapsibleContent>
          </Collapsible>
        </div>
      </div>
    </Panel>
  );
}
