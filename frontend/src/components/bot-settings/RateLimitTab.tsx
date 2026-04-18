import { BarChart2 } from 'lucide-react';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { Textarea } from '@/components/ui/textarea';
import { SettingSection, SettingRow } from '@/components/connections';

interface RateLimitTabProps {
  daily_message_limit: number;
  per_user_limit: number;
  rate_limit_bot_message: string;
  rate_limit_user_message: string;
  onChange: (field: string, value: unknown) => void;
}

export function RateLimitTab({
  daily_message_limit,
  per_user_limit,
  rate_limit_bot_message,
  rate_limit_user_message,
  onChange,
}: RateLimitTabProps) {
  return (
    <div className="space-y-6">
      <div className="border rounded-lg p-5 space-y-6">
        <SettingSection
          icon={BarChart2}
          title="จำกัดการใช้งาน"
          description="ป้องกันสแปมและการใช้ประโยชน์มากเกินไป"
        >
          <div className="space-y-5">
            <div className="space-y-2">
              <Label htmlFor="daily-limit" className="text-sm font-medium">
                จำนวนข้อความต่อวัน:{' '}
                <span className="font-semibold tabular-nums">{daily_message_limit}</span>
              </Label>
              <Slider
                id="daily-limit"
                min={10}
                max={1000}
                step={10}
                value={[daily_message_limit]}
                onValueChange={(v) => onChange('daily_message_limit', v[0])}
                className="transition-colors duration-150"
              />
              <div className="flex justify-between text-xs text-muted-foreground">
                <span>10</span>
                <span>1,000</span>
              </div>
            </div>

            <div className="space-y-2">
              <Label htmlFor="user-limit" className="text-sm font-medium">
                จำนวนข้อความต่อคนต่อวัน:{' '}
                <span className="font-semibold tabular-nums">{per_user_limit}</span>
              </Label>
              <Slider
                id="user-limit"
                min={1}
                max={100}
                step={1}
                value={[per_user_limit]}
                onValueChange={(v) => onChange('per_user_limit', v[0])}
                className="transition-colors duration-150"
              />
              <div className="flex justify-between text-xs text-muted-foreground">
                <span>1</span>
                <span>100</span>
              </div>
            </div>
          </div>
        </SettingSection>

        <div className="border-t pt-5 space-y-4">
          <p className="text-xs text-muted-foreground">
            ข้อความเมื่อถูกจำกัด — เว้นว่างไว้เพื่อไม่ตอบกลับ
          </p>

          <SettingRow
            label="ข้อความเมื่อบอทถูกจำกัด (รวมทุกคน)"
            htmlFor="rate-limit-bot-msg"
            orientation="vertical"
          >
            <Textarea
              id="rate-limit-bot-msg"
              placeholder="ตัวอย่าง: ขออภัยครับ บอทได้รับข้อความจำนวนมากในวันนี้ กรุณาลองใหม่พรุ่งนี้ครับ"
              value={rate_limit_bot_message}
              onChange={(e) => onChange('rate_limit_bot_message', e.target.value)}
              rows={2}
              className="transition-colors duration-150"
            />
          </SettingRow>

          <SettingRow
            label="ข้อความเมื่อผู้ใช้ถูกจำกัด (ต่อคน)"
            htmlFor="rate-limit-user-msg"
            orientation="vertical"
          >
            <Textarea
              id="rate-limit-user-msg"
              placeholder="ตัวอย่าง: ขออภัยครับ คุณส่งข้อความครบจำนวนที่กำหนดต่อวันแล้ว กรุณาลองใหม่พรุ่งนี้ครับ"
              value={rate_limit_user_message}
              onChange={(e) => onChange('rate_limit_user_message', e.target.value)}
              rows={2}
              className="transition-colors duration-150"
            />
          </SettingRow>
        </div>
      </div>
    </div>
  );
}
