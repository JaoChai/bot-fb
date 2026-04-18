import { Smile, MessageSquare, Sparkles } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { SettingSection, SettingRow } from '@/components/connections';

interface StickerReplyTabProps {
  reply_sticker_enabled: boolean;
  reply_sticker_mode: 'static' | 'ai';
  reply_sticker_message: string;
  reply_sticker_ai_prompt: string;
  onChange: (field: string, value: unknown) => void;
}

export function StickerReplyTab({
  reply_sticker_enabled,
  reply_sticker_mode,
  reply_sticker_message,
  reply_sticker_ai_prompt,
  onChange,
}: StickerReplyTabProps) {
  return (
    <div className="space-y-6">
      <div className="border rounded-lg p-5 space-y-5">
        <SettingSection
          icon={Smile}
          title="การตอบกลับ Sticker"
          description="บอทจะตอบกลับอัตโนมัติเมื่อได้รับสติกเกอร์"
        >
          <SettingRow label="เปิดใช้งาน Reply Sticker" htmlFor="sticker-toggle">
            <Switch
              id="sticker-toggle"
              checked={reply_sticker_enabled}
              onCheckedChange={(checked) => onChange('reply_sticker_enabled', checked)}
            />
          </SettingRow>
        </SettingSection>

        {reply_sticker_enabled && (
          <div className="space-y-5 border-t pt-5">
            {/* Mode Selection */}
            <div className="space-y-2">
              <span className="text-sm font-medium text-foreground">รูปแบบการตอบกลับ</span>
              <div className="grid grid-cols-2 gap-3 mt-2">
                <button
                  type="button"
                  onClick={() => onChange('reply_sticker_mode', 'static')}
                  className={cn(
                    'p-4 rounded-lg border-2 text-left transition-colors duration-150',
                    reply_sticker_mode === 'static'
                      ? 'border-foreground bg-muted/40'
                      : 'border-border hover:border-muted-foreground/50'
                  )}
                >
                  <div className="flex items-center gap-2 mb-1.5">
                    <MessageSquare className="h-4 w-4 text-blue-500" />
                    <span className="text-sm font-medium">ข้อความคงที่</span>
                  </div>
                  <p className="text-xs text-muted-foreground leading-relaxed">
                    ตอบด้วยข้อความที่กำหนดไว้ล่วงหน้า
                  </p>
                </button>

                <button
                  type="button"
                  onClick={() => onChange('reply_sticker_mode', 'ai')}
                  className={cn(
                    'p-4 rounded-lg border-2 text-left transition-colors duration-150',
                    reply_sticker_mode === 'ai'
                      ? 'border-foreground bg-muted/40'
                      : 'border-border hover:border-muted-foreground/50'
                  )}
                >
                  <div className="flex items-center gap-2 mb-1.5">
                    <Sparkles className="h-4 w-4 text-amber-500" />
                    <span className="text-sm font-medium">AI วิเคราะห์</span>
                  </div>
                  <p className="text-xs text-muted-foreground leading-relaxed">
                    AI วิเคราะห์สติกเกอร์และตอบกลับอัจฉริยะ
                  </p>
                </button>
              </div>
            </div>

            {/* Static Mode */}
            {reply_sticker_mode === 'static' && (
              <SettingRow
                label="ข้อความตอบกลับ"
                htmlFor="sticker-message"
                description='เว้นว่างไว้ = ใช้ข้อความเริ่มต้น "ได้รับสติกเกอร์แล้วค่ะ 🎉"'
                orientation="vertical"
              >
                <Input
                  id="sticker-message"
                  placeholder="ได้รับสติกเกอร์แล้วค่ะ 🎉"
                  value={reply_sticker_message}
                  onChange={(e) => onChange('reply_sticker_message', e.target.value)}
                  className="transition-colors duration-150"
                />
              </SettingRow>
            )}

            {/* AI Mode */}
            {reply_sticker_mode === 'ai' && (
              <SettingRow
                label="AI Prompt (ไม่บังคับ)"
                htmlFor="sticker-ai-prompt"
                description="เว้นว่างไว้ = AI จะใช้ prompt เริ่มต้น"
                orientation="vertical"
              >
                <Textarea
                  id="sticker-ai-prompt"
                  placeholder="ตอบกลับสติกเกอร์อย่างเป็นมิตร..."
                  value={reply_sticker_ai_prompt}
                  onChange={(e) => onChange('reply_sticker_ai_prompt', e.target.value)}
                  rows={3}
                  className="transition-colors duration-150"
                />
              </SettingRow>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
