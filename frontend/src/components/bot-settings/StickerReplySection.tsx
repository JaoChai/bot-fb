/**
 * StickerReplySection - Sticker reply settings component
 * Part of 006-bots-refactor feature (T039)
 *
 * Handles:
 * - Enable/disable sticker reply
 * - Mode selection (static/ai)
 * - Custom reply message for static mode
 * - AI prompt for ai mode
 */

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { MessageSquare, Sparkles, Info } from 'lucide-react';
import { cn } from '@/lib/utils';
import { type SectionProps } from './types';

type StickerMode = 'static' | 'ai';

interface ModeCardProps {
  mode: StickerMode;
  selected: boolean;
  onClick: () => void;
  disabled?: boolean;
  icon: React.ReactNode;
  title: string;
  description: string;
}

function ModeCard({
  selected,
  onClick,
  disabled,
  icon,
  title,
  description,
}: ModeCardProps) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className={cn(
        'flex-1 p-4 rounded-lg border-2 text-left cursor-pointer transition-all',
        'hover:border-primary/50 hover:bg-muted/50',
        'focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2',
        selected
          ? 'border-primary bg-primary/5'
          : 'border-border bg-background',
        disabled && 'opacity-50 cursor-not-allowed hover:border-border hover:bg-background'
      )}
    >
      <div className="flex items-start gap-3">
        <div
          className={cn(
            'w-4 h-4 mt-0.5 rounded-full border-2 flex items-center justify-center flex-shrink-0',
            selected ? 'border-primary' : 'border-muted-foreground'
          )}
        >
          {selected && (
            <div className="w-2 h-2 rounded-full bg-primary" />
          )}
        </div>
        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2 mb-1">
            <span className={cn(
              'text-muted-foreground',
              selected && 'text-primary'
            )}>
              {icon}
            </span>
            <span className="font-medium">{title}</span>
          </div>
          <p className="text-sm text-muted-foreground">{description}</p>
        </div>
      </div>
    </button>
  );
}

export function StickerReplySection({
  formData,
  onChange,
  disabled = false,
}: SectionProps) {
  const currentMode = formData.reply_sticker_mode ?? 'static';

  const handleModeChange = (mode: StickerMode) => {
    onChange('reply_sticker_mode', mode);
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-lg">การตอบกลับ Sticker</CardTitle>
        <p className="text-sm text-muted-foreground mt-1">
          บอทจะตอบกลับอัตโนมัติเมื่อได้รับสติกเกอร์
        </p>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="flex items-center justify-between">
          <Label htmlFor="sticker" className="font-semibold">
            เปิดใช้งาน Reply Sticker
          </Label>
          <Switch
            id="sticker"
            checked={formData.reply_sticker_enabled}
            onCheckedChange={(checked) =>
              onChange('reply_sticker_enabled', checked)
            }
            disabled={disabled}
          />
        </div>

        {formData.reply_sticker_enabled && (
          <div className="space-y-4 pt-4 border-t">
            {/* Mode Selection */}
            <div className="space-y-3">
              <Label className="font-semibold">รูปแบบการตอบกลับ</Label>
              <div className="flex flex-col sm:flex-row gap-3">
                <ModeCard
                  mode="static"
                  selected={currentMode === 'static'}
                  onClick={() => handleModeChange('static')}
                  disabled={disabled}
                  icon={<MessageSquare className="h-4 w-4" />}
                  title="ข้อความคงที่"
                  description="ตอบด้วยข้อความเดิมทุกครั้ง"
                />
                <ModeCard
                  mode="ai"
                  selected={currentMode === 'ai'}
                  onClick={() => handleModeChange('ai')}
                  disabled={disabled}
                  icon={<Sparkles className="h-4 w-4" />}
                  title="AI วิเคราะห์"
                  description="ให้ AI ตอบตามบริบท"
                />
              </div>
            </div>

            {/* Static Mode: Message Input */}
            {currentMode === 'static' && (
              <div className="space-y-2">
                <Label htmlFor="sticker-message" className="font-semibold">
                  ข้อความตอบกลับ
                </Label>
                <Input
                  id="sticker-message"
                  placeholder="ได้รับสติกเกอร์แล้วค่ะ"
                  value={formData.reply_sticker_message ?? ''}
                  onChange={(e) =>
                    onChange('reply_sticker_message', e.target.value || null)
                  }
                  disabled={disabled}
                />
                <p className="text-xs text-muted-foreground">
                  เว้นว่างไว้ = ใช้ข้อความเริ่มต้น "ได้รับสติกเกอร์แล้วค่ะ"
                </p>
              </div>
            )}

            {/* AI Mode: Info Box + Custom Prompt */}
            {currentMode === 'ai' && (
              <div className="space-y-4">
                {/* Info Box */}
                <div className="flex gap-3 p-3 bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 rounded-lg">
                  <Info className="h-5 w-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" />
                  <div className="text-sm text-blue-800 dark:text-blue-200">
                    <p className="font-medium mb-1">AI จะวิเคราะห์ความหมายของสติกเกอร์</p>
                    <p className="text-blue-700 dark:text-blue-300">
                      บอทจะพิจารณาบริบทของการสนทนาและตอบกลับอย่างเหมาะสม
                    </p>
                  </div>
                </div>

                {/* Custom AI Prompt */}
                <div className="space-y-2">
                  <Label htmlFor="sticker-ai-prompt" className="font-semibold">
                    คำสั่งเพิ่มเติมสำหรับ AI (ไม่บังคับ)
                  </Label>
                  <Textarea
                    id="sticker-ai-prompt"
                    placeholder="เช่น: ให้ตอบสั้นๆ น่ารัก หรือ ถ้าเป็นสติกเกอร์ขอบคุณให้ตอบว่ายินดีเสมอค่ะ"
                    value={formData.reply_sticker_ai_prompt ?? ''}
                    onChange={(e) =>
                      onChange('reply_sticker_ai_prompt', e.target.value || null)
                    }
                    disabled={disabled}
                    rows={3}
                  />
                  <p className="text-xs text-muted-foreground">
                    กำหนดแนวทางการตอบเพิ่มเติมให้ AI
                  </p>
                </div>
              </div>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
