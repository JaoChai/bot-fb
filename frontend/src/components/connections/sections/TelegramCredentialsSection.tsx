import { useState } from 'react';
import { Send, Eye, EyeOff, ExternalLink, Copy, Check } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Panel } from '@/components/common';
import { SettingRow } from '@/components/connections';
import { useToast } from '@/hooks/use-toast';
import type { ConnectionFormData } from '@/hooks/useConnectionForm';
import type { Bot } from '@/types/api';

const BACKEND_URL = import.meta.env.VITE_API_URL || 'https://api.botjao.com';

interface TelegramCredentialsSectionProps {
  formData: ConnectionFormData;
  handleChange: <K extends keyof ConnectionFormData>(field: K, value: ConnectionFormData[K]) => void;
  isEditMode: boolean;
  existingBot?: Bot;
}

export function TelegramCredentialsSection({
  formData,
  handleChange,
  isEditMode,
  existingBot,
}: TelegramCredentialsSectionProps) {
  const [showToken, setShowToken] = useState(false);
  const [webhookCopied, setWebhookCopied] = useState(false);
  const { toast } = useToast();

  const webhookUrl = existingBot
    ? `${BACKEND_URL.replace('/api', '')}/webhook/telegram/${existingBot.webhook_url?.split('/').pop() || '[token]'}`
    : '';

  const handleCopyWebhook = () => {
    const url = `${BACKEND_URL.replace('/api', '')}/webhook/telegram/${existingBot?.webhook_url?.split('/').pop() || ''}`;
    navigator.clipboard.writeText(url);
    setWebhookCopied(true);
    setTimeout(() => setWebhookCopied(false), 2000);
    toast({ title: 'คัดลอกแล้ว', description: 'Webhook URL ถูกคัดลอกไปยังคลิปบอร์ด' });
  };

  return (
    <Panel
      icon={Send}
      title="Telegram Bot Token"
      description="ข้อมูลจาก @BotFather บน Telegram"
      tone="secure"
    >
      <div className="space-y-4">
        <SettingRow
          label={isEditMode ? 'Bot Token (เว้นว่างถ้าไม่เปลี่ยน)' : 'Bot Token'}
          htmlFor="telegram-token"
          orientation="vertical"
        >
          <div className="flex gap-2 max-w-md">
            <Input
              id="telegram-token"
              type={showToken ? 'text' : 'password'}
              placeholder={isEditMode ? '••••••••' : '123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ'}
              value={formData.telegram_bot_token}
              onChange={(e) => handleChange('telegram_bot_token', e.target.value)}
              className="font-mono text-sm transition-colors duration-150"
            />
            <Button
              variant="ghost"
              size="icon"
              type="button"
              onClick={() => setShowToken(!showToken)}
              aria-label="Toggle visibility"
              className="h-10 w-10 shrink-0 transition-colors duration-150"
            >
              {showToken ? (
                <EyeOff className="h-4 w-4" strokeWidth={1.5} />
              ) : (
                <Eye className="h-4 w-4" strokeWidth={1.5} />
              )}
            </Button>
          </div>
        </SettingRow>

        {isEditMode && existingBot && (
          <SettingRow label="Webhook URL" htmlFor="webhook-url" orientation="vertical">
            <div className="flex gap-2 max-w-md">
              <Input
                id="webhook-url"
                readOnly
                value={webhookUrl}
                className="font-mono text-xs bg-muted transition-colors duration-150"
              />
              <Button
                variant="ghost"
                size="icon"
                type="button"
                onClick={handleCopyWebhook}
                aria-label="Copy webhook URL"
                className="h-10 w-10 shrink-0 transition-colors duration-150"
              >
                {webhookCopied ? (
                  <Check className="h-4 w-4 text-emerald-600 dark:text-emerald-400" strokeWidth={1.5} />
                ) : (
                  <Copy className="h-4 w-4" strokeWidth={1.5} />
                )}
              </Button>
            </div>
            <p className="text-xs text-muted-foreground mt-1">
              นำ URL นี้ไปตั้งค่าที่ @BotFather ด้วยคำสั่ง /setwebhook
            </p>
          </SettingRow>
        )}

        <div className="rounded-md border bg-muted/30 p-3 max-w-md">
          <h4 className="font-medium text-sm mb-2">วิธีสร้าง Telegram Bot</h4>
          <ol className="text-xs text-muted-foreground space-y-1 list-decimal list-inside">
            <li>เปิด Telegram แล้วค้นหา @BotFather</li>
            <li>ส่งคำสั่ง /newbot และทำตามขั้นตอน</li>
            <li>คัดลอก Bot Token มาวางที่นี่</li>
            {isEditMode && <li>คัดลอก Webhook URL ด้านบนไปตั้งค่าด้วย /setwebhook</li>}
          </ol>
        </div>

        <Button variant="link" className="h-auto p-0 text-sm" asChild>
          <a
            href="https://core.telegram.org/bots#how-do-i-create-a-bot"
            target="_blank"
            rel="noopener noreferrer"
          >
            ดูวิธีการสร้าง Telegram Bot <ExternalLink className="h-3 w-3 ml-1" strokeWidth={1.5} />
          </a>
        </Button>
      </div>
    </Panel>
  );
}
