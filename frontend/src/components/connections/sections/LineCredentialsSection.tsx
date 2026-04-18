import { useState } from 'react';
import { Key, Eye, EyeOff, ExternalLink } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Panel } from '@/components/common';
import { SettingRow } from '@/components/connections';
import type { ConnectionFormData } from '@/hooks/useConnectionForm';

interface LineCredentialsSectionProps {
  formData: ConnectionFormData;
  handleChange: <K extends keyof ConnectionFormData>(field: K, value: ConnectionFormData[K]) => void;
  isEditMode: boolean;
}

export function LineCredentialsSection({ formData, handleChange, isEditMode }: LineCredentialsSectionProps) {
  const [showSecret, setShowSecret] = useState(false);

  return (
    <Panel
      icon={Key}
      title="LINE Credentials"
      description="ข้อมูลจาก LINE Developers Console"
      tone="secure"
    >
      <div className="space-y-4">
        <SettingRow
          label={isEditMode ? 'Channel Secret (เว้นว่างถ้าไม่เปลี่ยน)' : 'Channel Secret'}
          htmlFor="line-secret"
          orientation="vertical"
        >
          <div className="flex gap-2 max-w-md">
            <Input
              id="line-secret"
              type={showSecret ? 'text' : 'password'}
              placeholder={isEditMode ? '••••••••' : 'Channel Secret'}
              value={formData.line_channel_secret}
              onChange={(e) => handleChange('line_channel_secret', e.target.value)}
              className="font-mono text-sm transition-colors duration-150"
            />
            <Button
              variant="ghost"
              size="icon"
              type="button"
              onClick={() => setShowSecret(!showSecret)}
              aria-label="Toggle visibility"
              className="h-10 w-10 shrink-0 transition-colors duration-150"
            >
              {showSecret ? (
                <EyeOff className="h-4 w-4" strokeWidth={1.5} />
              ) : (
                <Eye className="h-4 w-4" strokeWidth={1.5} />
              )}
            </Button>
          </div>
        </SettingRow>

        <SettingRow
          label={isEditMode ? 'Channel Access Token (เว้นว่างถ้าไม่เปลี่ยน)' : 'Channel Access Token'}
          htmlFor="line-token"
          orientation="vertical"
        >
          <Input
            id="line-token"
            type="password"
            placeholder={isEditMode ? '••••••••' : 'Channel Access Token'}
            value={formData.line_channel_access_token}
            onChange={(e) => handleChange('line_channel_access_token', e.target.value)}
            className="font-mono text-sm max-w-md transition-colors duration-150"
          />
        </SettingRow>

        <Button variant="link" className="h-auto p-0 text-sm" asChild>
          <a href="https://developers.line.biz" target="_blank" rel="noopener noreferrer">
            ดูวิธีการเชื่อมต่อ LINE OA <ExternalLink className="h-3 w-3 ml-1" strokeWidth={1.5} />
          </a>
        </Button>
      </div>
    </Panel>
  );
}
