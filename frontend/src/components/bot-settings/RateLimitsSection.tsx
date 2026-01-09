/**
 * RateLimitsSection - Rate limiting settings
 * Part of 006-bots-refactor feature (T035)
 *
 * Fields:
 * - daily_message_limit
 * - per_user_limit
 * - rate_limit_per_minute
 * - max_tokens_per_response
 * - rate_limit_bot_message
 * - rate_limit_user_message
 */

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { Textarea } from '@/components/ui/textarea';
import { type SectionProps } from './types';

export function RateLimitsSection({ formData, onChange, disabled }: SectionProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-lg">จำกัดการใช้งาน</CardTitle>
        <p className="text-sm text-muted-foreground mt-2">
          ป้องกันสแปมและควบคุมการใช้ทรัพยากร
        </p>
      </CardHeader>
      <CardContent className="space-y-6">
        {/* Daily Message Limit */}
        <div className="space-y-2">
          <Label htmlFor="daily-limit" className="font-semibold">
            จำนวนข้อความต่อวัน (รวมทุกคน):{' '}
            {formData.daily_message_limit ?? 'ไม่จำกัด'}
          </Label>
          <Slider
            id="daily-limit"
            min={0}
            max={10000}
            step={100}
            value={[formData.daily_message_limit ?? 0]}
            onValueChange={(value) =>
              onChange('daily_message_limit', value[0] === 0 ? null : value[0])
            }
            disabled={disabled}
          />
          <p className="text-xs text-muted-foreground">
            จำนวนข้อความสูงสุดที่บอทจะตอบต่อวัน (0 = ไม่จำกัด)
          </p>
        </div>

        {/* Per User Limit */}
        <div className="space-y-2">
          <Label htmlFor="user-limit" className="font-semibold">
            จำนวนข้อความต่อคนต่อวัน:{' '}
            {formData.per_user_limit ?? 'ไม่จำกัด'}
          </Label>
          <Slider
            id="user-limit"
            min={0}
            max={500}
            step={5}
            value={[formData.per_user_limit ?? 0]}
            onValueChange={(value) =>
              onChange('per_user_limit', value[0] === 0 ? null : value[0])
            }
            disabled={disabled}
          />
          <p className="text-xs text-muted-foreground">
            จำนวนข้อความสูงสุดต่อผู้ใช้ต่อวัน (0 = ไม่จำกัด)
          </p>
        </div>

        {/* Rate Limit Per Minute */}
        <div className="space-y-2">
          <Label htmlFor="rate-per-minute" className="font-semibold">
            จำนวนข้อความต่อนาที: {formData.rate_limit_per_minute}
          </Label>
          <Slider
            id="rate-per-minute"
            min={1}
            max={60}
            step={1}
            value={[formData.rate_limit_per_minute]}
            onValueChange={(value) => onChange('rate_limit_per_minute', value[0])}
            disabled={disabled}
          />
          <p className="text-xs text-muted-foreground">
            จำกัดความถี่การส่งข้อความต่อนาที
          </p>
        </div>

        {/* Max Tokens Per Response */}
        <div className="space-y-2">
          <Label htmlFor="max-tokens" className="font-semibold">
            Token สูงสุดต่อการตอบ
          </Label>
          <Input
            id="max-tokens"
            type="number"
            min={0}
            max={8000}
            placeholder="ไม่จำกัด"
            value={formData.max_tokens_per_response ?? ''}
            onChange={(e) =>
              onChange(
                'max_tokens_per_response',
                e.target.value ? parseInt(e.target.value, 10) : null
              )
            }
            className="w-full sm:w-32"
            disabled={disabled}
          />
          <p className="text-xs text-muted-foreground">
            จำกัดความยาวของคำตอบ (เว้นว่าง = ไม่จำกัด)
          </p>
        </div>

        {/* Rate Limit Messages */}
        <div className="border-t pt-6 mt-6">
          <h4 className="font-medium mb-4">ข้อความเมื่อถูกจำกัด</h4>
          <p className="text-sm text-muted-foreground mb-4">
            เว้นว่างไว้ = บอทจะไม่ตอบเมื่อถูกจำกัด
          </p>

          <div className="space-y-4">
            {/* Bot Rate Limit Message */}
            <div className="space-y-2">
              <Label htmlFor="rate-limit-bot-msg" className="font-semibold">
                ข้อความเมื่อบอทถูกจำกัด (รวมทุกคน)
              </Label>
              <Textarea
                id="rate-limit-bot-msg"
                placeholder="ขออภัยครับ บอทได้รับข้อความจำนวนมากในวันนี้ กรุณาลองใหม่พรุ่งนี้ครับ"
                value={formData.rate_limit_bot_message ?? ''}
                onChange={(e) =>
                  onChange('rate_limit_bot_message', e.target.value || null)
                }
                rows={2}
                disabled={disabled}
              />
            </div>

            {/* User Rate Limit Message */}
            <div className="space-y-2">
              <Label htmlFor="rate-limit-user-msg" className="font-semibold">
                ข้อความเมื่อผู้ใช้ถูกจำกัด (ต่อคน)
              </Label>
              <Textarea
                id="rate-limit-user-msg"
                placeholder="ขออภัยครับ คุณส่งข้อความครบจำนวนที่กำหนดต่อวันแล้ว กรุณาลองใหม่พรุ่งนี้ครับ"
                value={formData.rate_limit_user_message ?? ''}
                onChange={(e) =>
                  onChange('rate_limit_user_message', e.target.value || null)
                }
                rows={2}
                disabled={disabled}
              />
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
