/**
 * SecondAISection - Second AI settings for flow editor
 * Part of 006-bots-refactor feature (T047)
 *
 * Controls:
 * - second_ai_enabled toggle
 * - second_ai_model selector
 * - second_ai_check_fact, second_ai_check_policy, second_ai_check_personality checkboxes
 */

import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Collapsible, CollapsibleContent } from '@/components/ui/collapsible';
import { ModelSelector } from '@/components/ModelSelector';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { Badge } from '@/components/ui/badge';
import { ShieldCheck, Info, CheckCircle, Scale, MessageCircle } from 'lucide-react';
import { type FlowSectionProps } from './types';

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

export function SecondAISection({
  formData,
  onChange,
  disabled = false,
}: FlowSectionProps) {
  const handleCheckToggle = (
    field: 'second_ai_check_fact' | 'second_ai_check_policy' | 'second_ai_check_personality',
    checked: boolean
  ) => {
    onChange(field, checked);
  };

  return (
    <div className="border rounded-lg p-6 space-y-4">
      {/* Header with toggle */}
      <div className="flex items-start gap-4">
        <Switch
          id="second_ai_enabled"
          checked={formData.second_ai_enabled}
          onCheckedChange={(checked) => onChange('second_ai_enabled', checked)}
          disabled={disabled}
        />
        <div className="flex-1">
          <div className="flex items-center gap-2">
            <ShieldCheck className="h-5 w-5 text-muted-foreground" />
            <Label htmlFor="second_ai_enabled" className="font-medium">
              Second AI for Improvement
            </Label>
            <Badge variant="secondary" className="text-xs">
              Quality Check
            </Badge>
          </div>
          <p className="text-sm text-muted-foreground mt-1">
            ใช้ AI ตัวที่สองเพื่อตรวจสอบและปรับปรุงคำตอบก่อนส่งให้ลูกค้า
          </p>
        </div>
      </div>

      {/* Expanded options when enabled */}
      <Collapsible open={formData.second_ai_enabled}>
        <CollapsibleContent>
          <div className="mt-4 space-y-4 pt-4 border-t">
            {/* Model selector */}
            <div className="space-y-2">
              <div className="flex items-center gap-2">
                <Label className="text-sm font-medium">Model สำหรับ Second AI</Label>
                <InfoTooltip>
                  เลือก model ที่จะใช้ในการตรวจสอบคำตอบ แนะนำให้ใช้ model ที่เร็วและประหยัด
                </InfoTooltip>
              </div>
              <ModelSelector
                label=""
                value={formData.second_ai_model || ''}
                onChange={(value) => onChange('second_ai_model', value)}
                placeholder="openai/gpt-4o-mini (แนะนำ)"
              />
            </div>

            {/* Check options */}
            <div className="space-y-2">
              <Label className="text-sm font-medium">ประเภทการตรวจสอบ</Label>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                {/* Fact Check */}
                <label
                  className={`flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors ${
                    formData.second_ai_check_fact
                      ? 'bg-green-500/10 border-green-500/30'
                      : 'hover:bg-muted'
                  } ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
                >
                  <input
                    type="checkbox"
                    checked={formData.second_ai_check_fact}
                    onChange={(e) => handleCheckToggle('second_ai_check_fact', e.target.checked)}
                    disabled={disabled}
                    className="mt-1 rounded border-border"
                  />
                  <div className="flex-1">
                    <div className="flex items-center gap-2">
                      <CheckCircle className="h-4 w-4 text-green-600" />
                      <span className="text-sm font-medium">Fact Check</span>
                    </div>
                    <p className="text-xs text-muted-foreground mt-1">
                      ตรวจสอบความถูกต้องของข้อมูลกับ Knowledge Base
                    </p>
                  </div>
                </label>

                {/* Policy Check */}
                <label
                  className={`flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors ${
                    formData.second_ai_check_policy
                      ? 'bg-amber-500/10 border-amber-500/30'
                      : 'hover:bg-muted'
                  } ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
                >
                  <input
                    type="checkbox"
                    checked={formData.second_ai_check_policy}
                    onChange={(e) => handleCheckToggle('second_ai_check_policy', e.target.checked)}
                    disabled={disabled}
                    className="mt-1 rounded border-border"
                  />
                  <div className="flex-1">
                    <div className="flex items-center gap-2">
                      <Scale className="h-4 w-4 text-amber-600" />
                      <span className="text-sm font-medium">Policy</span>
                    </div>
                    <p className="text-xs text-muted-foreground mt-1">
                      ตรวจสอบว่าเป็นไปตามนโยบายของธุรกิจ
                    </p>
                  </div>
                </label>

                {/* Personality Check */}
                <label
                  className={`flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-colors ${
                    formData.second_ai_check_personality
                      ? 'bg-purple-500/10 border-purple-500/30'
                      : 'hover:bg-muted'
                  } ${disabled ? 'opacity-50 cursor-not-allowed' : ''}`}
                >
                  <input
                    type="checkbox"
                    checked={formData.second_ai_check_personality}
                    onChange={(e) =>
                      handleCheckToggle('second_ai_check_personality', e.target.checked)
                    }
                    disabled={disabled}
                    className="mt-1 rounded border-border"
                  />
                  <div className="flex-1">
                    <div className="flex items-center gap-2">
                      <MessageCircle className="h-4 w-4 text-purple-600" />
                      <span className="text-sm font-medium">Personality</span>
                    </div>
                    <p className="text-xs text-muted-foreground mt-1">
                      ตรวจสอบน้ำเสียงและบุคลิกภาพ AI
                    </p>
                  </div>
                </label>
              </div>
            </div>

            {/* Warning when fact check enabled but no KB */}
            {formData.second_ai_check_fact && formData.knowledge_bases.length === 0 && (
              <div className="bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800 rounded-lg p-3 text-sm">
                <div className="flex gap-2">
                  <Info className="h-4 w-4 text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                  <p className="text-amber-800 dark:text-amber-200">
                    Fact Check ต้องการ Knowledge Base เพื่อตรวจสอบข้อมูล กรุณาเชื่อมต่อ Knowledge
                    Base อย่างน้อย 1 รายการ
                  </p>
                </div>
              </div>
            )}

            {/* Info about Second AI */}
            <div className="bg-muted/50 rounded-lg p-3 text-sm">
              <p className="text-muted-foreground">
                Second AI จะตรวจสอบคำตอบทุกครั้งก่อนส่ง อาจเพิ่มเวลาตอบ 0.5-1.5 วินาที
                และค่าใช้จ่ายเพิ่มเติมตาม model ที่เลือก
              </p>
            </div>
          </div>
        </CollapsibleContent>
      </Collapsible>
    </div>
  );
}
