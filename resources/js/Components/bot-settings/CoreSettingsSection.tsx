/**
 * CoreSettingsSection - Core bot settings
 * Part of 006-bots-refactor feature (T034)
 *
 * Fields:
 * - welcome_message
 * - fallback_message
 * - typing_indicator
 * - typing_delay_ms
 * - language
 * - response_style
 * - auto_archive_days
 * - save_conversations
 */

import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Slider } from '@/Components/ui/slider';
import { Textarea } from '@/Components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/Components/ui/select';
import { type SectionProps } from './types';

const LANGUAGES = [
  { value: 'th', label: 'ไทย' },
  { value: 'en', label: 'English' },
  { value: 'zh', label: '中文' },
  { value: 'ja', label: '日本語' },
];

const RESPONSE_STYLES = [
  { value: 'formal', label: 'ทางการ' },
  { value: 'casual', label: 'เป็นกันเอง' },
  { value: 'friendly', label: 'เป็นมิตร' },
  { value: 'professional', label: 'มืออาชีพ' },
];

export function CoreSettingsSection({ formData, onChange, disabled }: SectionProps) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-lg">ตั้งค่าพื้นฐาน</CardTitle>
        <p className="text-sm text-muted-foreground mt-2">
          กำหนดพฤติกรรมเบื้องต้นของบอท
        </p>
      </CardHeader>
      <CardContent className="space-y-6">
        {/* Welcome Message */}
        <div className="space-y-2">
          <Label htmlFor="welcome-message" className="font-semibold">
            ข้อความต้อนรับ
          </Label>
          <Textarea
            id="welcome-message"
            placeholder="สวัสดีครับ! มีอะไรให้ช่วยไหมครับ?"
            value={formData.welcome_message ?? ''}
            onChange={(e) => onChange('welcome_message', e.target.value || null)}
            rows={2}
            disabled={disabled}
          />
          <p className="text-xs text-muted-foreground">
            ข้อความที่บอทจะส่งเมื่อเริ่มการสนทนาใหม่
          </p>
        </div>

        {/* Fallback Message */}
        <div className="space-y-2">
          <Label htmlFor="fallback-message" className="font-semibold">
            ข้อความ Fallback
          </Label>
          <Textarea
            id="fallback-message"
            placeholder="ขออภัยครับ ไม่เข้าใจคำถาม กรุณาลองใหม่อีกครั้งครับ"
            value={formData.fallback_message ?? ''}
            onChange={(e) => onChange('fallback_message', e.target.value || null)}
            rows={2}
            disabled={disabled}
          />
          <p className="text-xs text-muted-foreground">
            ข้อความเมื่อบอทไม่สามารถตอบได้
          </p>
        </div>

        {/* Typing Indicator */}
        <div className="flex items-center justify-between">
          <div>
            <Label htmlFor="typing-indicator" className="font-semibold">
              แสดงสถานะกำลังพิมพ์
            </Label>
            <p className="text-xs text-muted-foreground mt-1">
              แสดงว่าบอทกำลังพิมพ์ก่อนส่งข้อความ
            </p>
          </div>
          <Switch
            id="typing-indicator"
            checked={formData.typing_indicator}
            onCheckedChange={(checked) => onChange('typing_indicator', checked)}
            disabled={disabled}
          />
        </div>

        {/* Typing Delay */}
        {formData.typing_indicator && (
          <div className="space-y-2 pl-4 border-l-2 border-muted">
            <Label htmlFor="typing-delay" className="font-semibold">
              เวลาหน่วงการพิมพ์: {formData.typing_delay_ms} ms
            </Label>
            <Slider
              id="typing-delay"
              min={0}
              max={5000}
              step={100}
              value={[formData.typing_delay_ms]}
              onValueChange={(value) => onChange('typing_delay_ms', value[0])}
              disabled={disabled}
            />
            <p className="text-xs text-muted-foreground">
              ระยะเวลาที่แสดงสถานะกำลังพิมพ์ก่อนส่งข้อความ
            </p>
          </div>
        )}

        {/* Language */}
        <div className="space-y-2">
          <Label className="font-semibold">ภาษาหลัก</Label>
          <Select
            value={formData.language}
            onValueChange={(value) => onChange('language', value)}
            disabled={disabled}
          >
            <SelectTrigger className="w-full sm:w-48">
              <SelectValue placeholder="เลือกภาษา" />
            </SelectTrigger>
            <SelectContent>
              {LANGUAGES.map((lang) => (
                <SelectItem key={lang.value} value={lang.value}>
                  {lang.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-xs text-muted-foreground">
            ภาษาที่บอทใช้ในการตอบ
          </p>
        </div>

        {/* Response Style */}
        <div className="space-y-2">
          <Label className="font-semibold">รูปแบบการตอบ</Label>
          <Select
            value={formData.response_style ?? 'friendly'}
            onValueChange={(value) => onChange('response_style', value)}
            disabled={disabled}
          >
            <SelectTrigger className="w-full sm:w-48">
              <SelectValue placeholder="เลือกรูปแบบ" />
            </SelectTrigger>
            <SelectContent>
              {RESPONSE_STYLES.map((style) => (
                <SelectItem key={style.value} value={style.value}>
                  {style.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p className="text-xs text-muted-foreground">
            โทนและรูปแบบการตอบของบอท
          </p>
        </div>

        {/* Auto Archive Days */}
        <div className="space-y-2">
          <Label htmlFor="auto-archive" className="font-semibold">
            Archive อัตโนมัติหลังจาก (วัน)
          </Label>
          <Input
            id="auto-archive"
            type="number"
            min={0}
            max={365}
            placeholder="ไม่ archive อัตโนมัติ"
            value={formData.auto_archive_days ?? ''}
            onChange={(e) =>
              onChange('auto_archive_days', e.target.value ? parseInt(e.target.value, 10) : null)
            }
            className="w-full sm:w-32"
            disabled={disabled}
          />
          <p className="text-xs text-muted-foreground">
            จำนวนวันที่ไม่มีกิจกรรมก่อน archive บทสนทนา (เว้นว่าง = ไม่ archive)
          </p>
        </div>

        {/* Save Conversations */}
        <div className="flex items-center justify-between">
          <div>
            <Label htmlFor="save-conversations" className="font-semibold">
              บันทึกบทสนทนา
            </Label>
            <p className="text-xs text-muted-foreground mt-1">
              เก็บประวัติบทสนทนาทั้งหมด
            </p>
          </div>
          <Switch
            id="save-conversations"
            checked={formData.save_conversations}
            onCheckedChange={(checked) => onChange('save_conversations', checked)}
            disabled={disabled}
          />
        </div>
      </CardContent>
    </Card>
  );
}
