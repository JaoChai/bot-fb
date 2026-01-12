/**
 * LeadRecoverySection - Lead recovery settings component
 * Part of 006-bots-refactor feature
 *
 * Handles:
 * - Timeout duration selection
 * - Mode selection (static/ai)
 * - Custom message for static mode
 * - Max attempts configuration
 */

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { MessageSquare, Sparkles, Info } from 'lucide-react';
import { cn } from '@/lib/utils';

type LeadRecoveryMode = 'static' | 'ai';

export interface LeadRecoverySettings {
  lead_recovery_timeout_hours: number;
  lead_recovery_mode: LeadRecoveryMode;
  lead_recovery_message: string | null;
  lead_recovery_max_attempts: number;
}

export interface LeadRecoverySectionProps {
  enabled: boolean;
  settings: LeadRecoverySettings;
  onChange: (settings: Partial<LeadRecoverySettings>) => void;
  disabled?: boolean;
}

interface ModeCardProps {
  mode: LeadRecoveryMode;
  selected: boolean;
  onClick: () => void;
  disabled?: boolean;
  icon: React.ReactNode;
  title: string;
  description: string;
}

const TIMEOUT_OPTIONS = [
  { value: '1', label: '1 ชั่วโมง' },
  { value: '2', label: '2 ชั่วโมง' },
  { value: '4', label: '4 ชั่วโมง' },
  { value: '6', label: '6 ชั่วโมง' },
  { value: '12', label: '12 ชั่วโมง' },
  { value: '24', label: '24 ชั่วโมง (1 วัน)' },
  { value: '48', label: '48 ชั่วโมง (2 วัน)' },
  { value: '72', label: '72 ชั่วโมง (3 วัน)' },
];

const MAX_ATTEMPTS_OPTIONS = [
  { value: '1', label: '1 ครั้ง' },
  { value: '2', label: '2 ครั้ง' },
  { value: '3', label: '3 ครั้ง' },
  { value: '4', label: '4 ครั้ง' },
  { value: '5', label: '5 ครั้ง' },
];

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

export function LeadRecoverySection({
  enabled,
  settings,
  onChange,
  disabled = false,
}: LeadRecoverySectionProps) {
  const currentMode = settings.lead_recovery_mode ?? 'static';
  const timeoutHours = settings.lead_recovery_timeout_hours ?? 4;
  const maxAttempts = settings.lead_recovery_max_attempts ?? 2;

  if (!enabled) {
    return null;
  }

  return (
    <div className="space-y-4 pl-4 border-l-2 border-muted">
      <Card className="border-0 shadow-none bg-transparent">
        <CardHeader className="px-0 pt-0">
          <CardTitle className="text-base">ตั้งค่า Lead Recovery</CardTitle>
          <p className="text-sm text-muted-foreground">
            กำหนดเวลาและรูปแบบการติดตามลูกค้าเมื่อบทสนทนาเงียบ
          </p>
        </CardHeader>
        <CardContent className="px-0 pb-0 space-y-5">
          {/* Timeout Duration */}
          <div className="space-y-2">
            <Label htmlFor="timeout-hours" className="font-semibold">
              ระยะเวลารอก่อนติดตาม
            </Label>
            <Select
              value={String(timeoutHours)}
              onValueChange={(value) =>
                onChange({ lead_recovery_timeout_hours: parseInt(value, 10) })
              }
              disabled={disabled}
            >
              <SelectTrigger id="timeout-hours" className="w-full sm:w-[240px]">
                <SelectValue placeholder="เลือกระยะเวลา" />
              </SelectTrigger>
              <SelectContent>
                {TIMEOUT_OPTIONS.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              ระบบจะรอเวลานี้หลังจากข้อความสุดท้ายก่อนส่งข้อความติดตาม
            </p>
          </div>

          {/* Mode Selection */}
          <div className="space-y-3">
            <Label className="font-semibold">รูปแบบข้อความติดตาม</Label>
            <div className="flex flex-col sm:flex-row gap-3">
              <ModeCard
                mode="static"
                selected={currentMode === 'static'}
                onClick={() => onChange({ lead_recovery_mode: 'static' })}
                disabled={disabled}
                icon={<MessageSquare className="h-4 w-4" />}
                title="ข้อความคงที่"
                description="ใช้ข้อความเดิมทุกครั้ง"
              />
              <ModeCard
                mode="ai"
                selected={currentMode === 'ai'}
                onClick={() => onChange({ lead_recovery_mode: 'ai' })}
                disabled={disabled}
                icon={<Sparkles className="h-4 w-4" />}
                title="AI สร้างข้อความ"
                description="ให้ AI สร้างข้อความตามบริบท"
              />
            </div>
          </div>

          {/* Static Mode: Message Input */}
          {currentMode === 'static' && (
            <div className="space-y-2">
              <Label htmlFor="recovery-message" className="font-semibold">
                ข้อความติดตาม
              </Label>
              <Textarea
                id="recovery-message"
                placeholder="สวัสดีค่ะ ไม่ทราบว่ายังสนใจสินค้าอยู่ไหมคะ? หากมีข้อสงสัยเพิ่มเติมสามารถสอบถามได้เลยนะคะ"
                value={settings.lead_recovery_message ?? ''}
                onChange={(e) =>
                  onChange({ lead_recovery_message: e.target.value || null })
                }
                disabled={disabled}
                rows={3}
              />
              <p className="text-xs text-muted-foreground">
                ข้อความที่จะส่งถึงลูกค้าเมื่อบทสนทนาเงียบ
              </p>
            </div>
          )}

          {/* AI Mode: Info Box */}
          {currentMode === 'ai' && (
            <div className="flex gap-3 p-3 bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-800 rounded-lg">
              <Info className="h-5 w-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" />
              <div className="text-sm text-blue-800 dark:text-blue-200">
                <p className="font-medium mb-1">AI จะสร้างข้อความติดตามอัตโนมัติ</p>
                <p className="text-blue-700 dark:text-blue-300">
                  บอทจะวิเคราะห์บริบทการสนทนาและสร้างข้อความที่เหมาะสมกับแต่ละลูกค้า
                </p>
              </div>
            </div>
          )}

          {/* Max Attempts */}
          <div className="space-y-2">
            <Label htmlFor="max-attempts" className="font-semibold">
              จำนวนครั้งสูงสุดที่ติดตาม
            </Label>
            <Select
              value={String(maxAttempts)}
              onValueChange={(value) =>
                onChange({ lead_recovery_max_attempts: parseInt(value, 10) })
              }
              disabled={disabled}
            >
              <SelectTrigger id="max-attempts" className="w-full sm:w-[180px]">
                <SelectValue placeholder="เลือกจำนวนครั้ง" />
              </SelectTrigger>
              <SelectContent>
                {MAX_ATTEMPTS_OPTIONS.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <p className="text-xs text-muted-foreground">
              หลังจากติดตามครบตามจำนวนนี้แล้ว ระบบจะหยุดติดตามอัตโนมัติ
            </p>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
