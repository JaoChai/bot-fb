import { Cpu } from 'lucide-react';
import { Panel } from '@/components/common';
import { ModelConfiguration } from '@/components/ModelSelector';
import { ReasoningEffortSelector } from '@/components/connections/ReasoningEffortSelector';
import type { ConnectionFormData } from '@/hooks/useConnectionForm';

interface AIModelsSectionProps {
  formData: ConnectionFormData;
  handleChange: <K extends keyof ConnectionFormData>(field: K, value: ConnectionFormData[K]) => void;
}

export function AIModelsSection({ formData, handleChange }: AIModelsSectionProps) {
  return (
    <>
      <Panel
        icon={Cpu}
        title="AI Models"
        description="โมเดลตอบแชท (หลัก + สำรอง) และโมเดลงานเบื้องหลัง"
      >
        <ModelConfiguration
          primaryModel={formData.primary_chat_model}
          fallbackModel={formData.fallback_chat_model}
          utilityModel={formData.utility_model}
          onPrimaryChange={(value) => handleChange('primary_chat_model', value)}
          onFallbackChange={(value) => handleChange('fallback_chat_model', value)}
          onUtilityChange={(value) => handleChange('utility_model', value)}
        />
        <div className="mt-4">
          <ReasoningEffortSelector
            value={formData.reasoning_effort}
            onChange={(value) => handleChange('reasoning_effort', value)}
          />
        </div>
      </Panel>
    </>
  );
}
