import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Slider } from '@/components/ui/slider';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Collapsible, CollapsibleContent } from '@/components/ui/collapsible';
import {
  Zap,
  Brain,
  Info
} from 'lucide-react';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';

export interface AISettings {
  // Semantic Router
  use_semantic_router: boolean;
  semantic_router_threshold: number;
  semantic_router_fallback: 'llm' | 'default_intent';
  // Confidence Cascade
  use_confidence_cascade: boolean;
  cascade_confidence_threshold: number;
  cascade_cheap_model: string;
  cascade_expensive_model: string;
}

interface AISettingsSectionProps {
  settings: AISettings;
  onChange: <K extends keyof AISettings>(field: K, value: AISettings[K]) => void;
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

export function AISettingsSection({ settings, onChange }: AISettingsSectionProps) {
  return (
    <div className="space-y-6">
      {/* Semantic Router */}
      <Card>
        <CardHeader className="pb-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="p-2 rounded-lg bg-primary/10">
                <Zap className="h-5 w-5 text-primary" />
              </div>
              <div>
                <CardTitle className="text-lg">Semantic Router</CardTitle>
                <p className="text-sm text-muted-foreground mt-0.5">
                  วิเคราะห์ Intent ด้วย Embedding (เร็วกว่า 10 เท่า)
                </p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <Badge variant="outline" className="bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400">
                ลด Latency 90%
              </Badge>
              <Switch
                checked={settings.use_semantic_router}
                onCheckedChange={(checked) => onChange('use_semantic_router', checked)}
              />
            </div>
          </div>
        </CardHeader>

        <Collapsible open={settings.use_semantic_router}>
          <CollapsibleContent>
            <CardContent className="pt-0 space-y-4 border-t">
              <div className="pt-4 space-y-4">
                {/* Threshold Slider */}
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <Label className="font-medium">Confidence Threshold</Label>
                      <InfoTooltip>
                        ถ้า Semantic Router ได้คะแนนต่ำกว่านี้ จะ fallback ไปใช้ LLM Decision
                      </InfoTooltip>
                    </div>
                    <span className="text-sm font-mono bg-muted px-2 py-1 rounded">
                      {(settings.semantic_router_threshold * 100).toFixed(0)}%
                    </span>
                  </div>
                  <Slider
                    min={50}
                    max={95}
                    step={5}
                    value={[settings.semantic_router_threshold * 100]}
                    onValueChange={(value) => onChange('semantic_router_threshold', value[0] / 100)}
                    className="cursor-pointer"
                  />
                  <div className="flex justify-between text-xs text-muted-foreground">
                    <span>50% (ยืดหยุ่น)</span>
                    <span>95% (เข้มงวด)</span>
                  </div>
                </div>

                {/* Fallback Mode */}
                <div className="space-y-2">
                  <div className="flex items-center gap-2">
                    <Label className="font-medium">Fallback Mode</Label>
                    <InfoTooltip>
                      เมื่อ Semantic Router ไม่มั่นใจ จะทำอย่างไร
                    </InfoTooltip>
                  </div>
                  <Select
                    value={settings.semantic_router_fallback}
                    onValueChange={(value) => onChange('semantic_router_fallback', value as 'llm' | 'default_intent')}
                  >
                    <SelectTrigger className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="llm">
                        <div className="flex flex-col">
                          <span>LLM Decision Model</span>
                          <span className="text-xs text-muted-foreground">ใช้ AI วิเคราะห์ (แม่นกว่า แต่ช้า)</span>
                        </div>
                      </SelectItem>
                      <SelectItem value="default_intent">
                        <div className="flex flex-col">
                          <span>Default Intent</span>
                          <span className="text-xs text-muted-foreground">ใช้ค่าเริ่มต้น (เร็วกว่า)</span>
                        </div>
                      </SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </CardContent>
          </CollapsibleContent>
        </Collapsible>
      </Card>

      {/* Confidence Cascade */}
      <Card>
        <CardHeader className="pb-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="p-2 rounded-lg bg-blue-500/10">
                <Brain className="h-5 w-5 text-blue-500" />
              </div>
              <div>
                <CardTitle className="text-lg">Confidence Cascade</CardTitle>
                <p className="text-sm text-muted-foreground mt-0.5">
                  ลองโมเดลถูกก่อน ถ้าไม่มั่นใจค่อยใช้โมเดลแพง
                </p>
              </div>
            </div>
            <div className="flex items-center gap-2">
              <Badge variant="outline" className="bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-400">
                ลดค่าใช้จ่าย 50-85%
              </Badge>
              <Switch
                checked={settings.use_confidence_cascade}
                onCheckedChange={(checked) => onChange('use_confidence_cascade', checked)}
              />
            </div>
          </div>
        </CardHeader>

        <Collapsible open={settings.use_confidence_cascade}>
          <CollapsibleContent>
            <CardContent className="pt-0 space-y-4 border-t">
              <div className="pt-4 space-y-4">
                {/* Confidence Threshold */}
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <Label className="font-medium">Escalation Threshold</Label>
                      <InfoTooltip>
                        ถ้าโมเดลถูกตอบด้วย confidence ต่ำกว่านี้ จะ escalate ไปโมเดลแพง
                      </InfoTooltip>
                    </div>
                    <span className="text-sm font-mono bg-muted px-2 py-1 rounded">
                      {(settings.cascade_confidence_threshold * 100).toFixed(0)}%
                    </span>
                  </div>
                  <Slider
                    min={50}
                    max={90}
                    step={5}
                    value={[settings.cascade_confidence_threshold * 100]}
                    onValueChange={(value) => onChange('cascade_confidence_threshold', value[0] / 100)}
                    className="cursor-pointer"
                  />
                  <div className="flex justify-between text-xs text-muted-foreground">
                    <span>50% (ประหยัดมาก)</span>
                    <span>90% (คุณภาพสูง)</span>
                  </div>
                </div>

                {/* Model Selection */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <div className="flex items-center gap-2">
                      <Label className="font-medium">Cheap Model (ลองก่อน)</Label>
                      <Badge variant="secondary" className="text-xs">$</Badge>
                    </div>
                    <Input
                      placeholder="openai/gpt-4o-mini"
                      value={settings.cascade_cheap_model}
                      onChange={(e) => onChange('cascade_cheap_model', e.target.value)}
                    />
                  </div>
                  <div className="space-y-2">
                    <div className="flex items-center gap-2">
                      <Label className="font-medium">Expensive Model (Escalate)</Label>
                      <Badge variant="secondary" className="text-xs">$$$</Badge>
                    </div>
                    <Input
                      placeholder="openai/gpt-4o"
                      value={settings.cascade_expensive_model}
                      onChange={(e) => onChange('cascade_expensive_model', e.target.value)}
                    />
                  </div>
                </div>

                {/* Info Box */}
                <div className="bg-muted/50 rounded-lg p-3 text-sm">
                  <p className="text-muted-foreground">
                    <strong>ตัวอย่าง:</strong> ถ้าลูกค้าถามว่า "สวัสดี" → GPT-4o-mini ตอบได้
                    แต่ถ้าถามเรื่องซับซ้อน → จะ escalate ไป GPT-4o อัตโนมัติ
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
