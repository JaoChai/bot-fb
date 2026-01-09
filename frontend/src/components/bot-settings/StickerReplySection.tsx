/**
 * StickerReplySection - Sticker reply settings component
 * Part of 006-bots-refactor feature (T039)
 *
 * Handles:
 * - Enable/disable sticker reply
 * - Custom reply message for stickers
 */

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Input } from '@/components/ui/input';
import { type SectionProps } from './types';

export function StickerReplySection({
  formData,
  onChange,
  disabled = false,
}: SectionProps) {
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
          <div className="space-y-2 pt-4 border-t">
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
      </CardContent>
    </Card>
  );
}
