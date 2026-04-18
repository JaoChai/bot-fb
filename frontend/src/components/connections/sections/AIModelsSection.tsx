import { Cpu, Key, ExternalLink } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Panel } from '@/components/common';
import { ModelConfiguration } from '@/components/ModelSelector';
import type { ConnectionFormData } from '@/hooks/useConnectionForm';

interface AIModelsSectionProps {
  formData: ConnectionFormData;
  handleChange: <K extends keyof ConnectionFormData>(field: K, value: ConnectionFormData[K]) => void;
}

export function AIModelsSection({ formData, handleChange }: AIModelsSectionProps) {
  return (
    <>
      <Panel
        icon={Key}
        title="OpenRouter API"
        description="ตั้งค่า API Key สำหรับเชื่อมต่อกับ AI Models"
      >
        <div className="rounded-md border bg-muted/30 p-3 text-sm text-muted-foreground max-w-md">
          <p className="mb-2">OpenRouter API Key ตั้งค่าที่หน้า Settings เพียงที่เดียว</p>
          <Button variant="link" className="h-auto p-0 text-sm" asChild>
            <a href="/settings">
              ไปที่หน้า Settings <ExternalLink className="h-3 w-3 ml-1" strokeWidth={1.5} />
            </a>
          </Button>
        </div>
      </Panel>

      <Panel
        icon={Cpu}
        title="AI Models"
        description="เลือก model สำหรับตอบคำถามและตัดสินใจ"
      >
        <ModelConfiguration
          primaryModel={formData.primary_chat_model}
          fallbackModel={formData.fallback_chat_model}
          decisionModel={formData.decision_model}
          fallbackDecisionModel={formData.fallback_decision_model}
          onPrimaryChange={(value) => handleChange('primary_chat_model', value)}
          onFallbackChange={(value) => handleChange('fallback_chat_model', value)}
          onDecisionChange={(value) => handleChange('decision_model', value)}
          onFallbackDecisionChange={(value) => handleChange('fallback_decision_model', value)}
          showDecisionModels={true}
        />
      </Panel>
    </>
  );
}
