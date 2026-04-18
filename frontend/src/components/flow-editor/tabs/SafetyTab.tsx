import { Shield, Sparkles, ShieldCheck, Gavel, MessageCircle } from 'lucide-react';
import { Switch } from '@/components/ui/switch';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { SettingSection } from '@/components/connections';
import { FlowSafetySettings } from '@/components/flows/FlowSafetySettings';
import type { FlowSafetySettingsData } from '@/components/flows';
import { KnowledgeBaseWarning } from '@/components/flow/KnowledgeBaseWarning';

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

  return (
    <div className="space-y-6">
      {/* Agent Safety Settings */}
      <div className="border rounded-lg p-5 space-y-4">
        <SettingSection
          icon={Shield}
          title="Agent Safety Settings"
          description="ตั้งค่าการป้องกันและขีดจำกัดของ Agent"
        >
          <FlowSafetySettings
            settings={safetySettings}
            onChange={(field, value) => onSafetyChange(field, value)}
          />
        </SettingSection>
      </div>

      {/* Second AI for Improvement */}
      <div className="border rounded-lg p-5 space-y-4">
        <SettingSection
          icon={Sparkles}
          title="Second AI for Improvement"
          description="ใช้ AI ตัวที่สองเพื่อตรวจสอบและปรับปรุงคำตอบ เช่น การตรวจสอบข้อเท็จจริง นโยบาย หรือบุคลิกภาพ"
          action={
            <Switch
              id="second-ai-toggle"
              checked={secondAIEnabled}
              onCheckedChange={onSecondAIToggle}
            />
          }
        >
          <Label htmlFor="second-ai-toggle" className="sr-only">
            เปิดใช้งาน Second AI
          </Label>

          {secondAIEnabled && (
            <div className="space-y-3 border-t pt-4">
              <p className="text-xs text-muted-foreground">เลือกประเภทการตรวจสอบที่ต้องการ</p>
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                {/* Fact Check */}
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

              <KnowledgeBaseWarning
                visible={secondAIOptions.factCheck && knowledgeBasesCount === 0}
              />
            </div>
          )}
        </SettingSection>
      </div>
    </div>
  );
}
