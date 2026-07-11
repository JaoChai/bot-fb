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
              ไปที่หน้า Settings <ExternalLink className="size-3 ml-1" strokeWidth={1.5} />
            </a>
          </Button>
        </div>
      </Panel>

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
      </Panel>
    </>
  );
}
