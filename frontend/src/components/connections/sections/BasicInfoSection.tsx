import { MessageCircle } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Panel } from '@/components/common';
import { SettingRow } from '@/components/connections';
import type { ConnectionFormData } from '@/hooks/useConnectionForm';

const PLATFORMS = [
  { id: 'line', name: 'LINE Official Account' },
  { id: 'facebook', name: 'Facebook Page' },
  { id: 'telegram', name: 'Telegram Bot' },
  { id: 'testing', name: 'Just Testing' },
];

interface BasicInfoSectionProps {
  formData: ConnectionFormData;
  handleChange: <K extends keyof ConnectionFormData>(field: K, value: ConnectionFormData[K]) => void;
  isEditMode: boolean;
}

export function BasicInfoSection({ formData, handleChange, isEditMode }: BasicInfoSectionProps) {
  const platformName = PLATFORMS.find((p) => p.id === formData.platform)?.name;

  const placeholder =
    formData.platform === 'telegram'
      ? 'เช่น Telegram Support ร้านกาแฟ'
      : formData.platform === 'facebook'
      ? 'เช่น Facebook Bot ร้านกาแฟ'
      : formData.platform === 'testing'
      ? 'เช่น Bot ทดสอบ'
      : 'เช่น LINE Bot สำหรับร้านกาแฟ';

  return (
    <Panel
      icon={MessageCircle}
      title="ข้อมูลพื้นฐาน"
      description="ตั้งชื่อให้จำง่ายและสื่อความหมาย"
    >
      <div className="space-y-4">
        <SettingRow
          label="ชื่อการเชื่อมต่อ"
          htmlFor="connection_name"
          orientation="vertical"
        >
          <Input
            id="connection_name"
            placeholder={placeholder}
            value={formData.connection_name}
            onChange={(e) => handleChange('connection_name', e.target.value)}
            className="max-w-md transition-colors duration-150"
          />
        </SettingRow>

        {!isEditMode && platformName && (
          <div className="text-xs text-muted-foreground bg-muted/50 rounded-lg px-3 py-2 max-w-md">
            Platform: <strong>{platformName}</strong>
            <span className="text-muted-foreground"> (เลือกจากหน้าก่อนหน้า)</span>
          </div>
        )}
      </div>
    </Panel>
  );
}
