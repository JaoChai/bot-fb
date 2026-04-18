import { Settings } from 'lucide-react';
import { Switch } from '@/components/ui/switch';
import { Panel } from '@/components/common';
import { SettingRow } from '@/components/connections';
import type { ConnectionFormData } from '@/hooks/useConnectionForm';

interface AdvancedOptionsSectionProps {
  formData: ConnectionFormData;
  handleChange: <K extends keyof ConnectionFormData>(field: K, value: ConnectionFormData[K]) => void;
}

export function AdvancedOptionsSection({ formData, handleChange }: AdvancedOptionsSectionProps) {
  return (
    <Panel icon={Settings} title="ตัวเลือกขั้นสูง">
      <div className="space-y-4 max-w-md">
        <SettingRow
          label="Webhook Forwarder"
          description="ส่ง webhook ไปยัง URL อื่นด้วย"
          htmlFor="webhook-forwarder"
        >
          <Switch
            id="webhook-forwarder"
            checked={formData.webhook_forwarder_enabled}
            onCheckedChange={(checked) => handleChange('webhook_forwarder_enabled', checked)}
          />
        </SettingRow>

        <SettingRow
          label="Auto Handover"
          description="ปิดบอทตอบอัตโนมัติ ให้ Admin ตอบเอง"
          htmlFor="auto-handover"
        >
          <Switch
            id="auto-handover"
            checked={formData.auto_handover}
            onCheckedChange={(checked) => handleChange('auto_handover', checked)}
          />
        </SettingRow>
      </div>
    </Panel>
  );
}
