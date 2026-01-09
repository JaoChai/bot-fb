/**
 * HITLSettingsSection - Human-In-The-Loop settings
 * Part of 006-bots-refactor feature (T036)
 *
 * Fields:
 * - hitl_enabled
 * - hitl_triggers
 * - lead_recovery_enabled
 * - reply_when_called_enabled
 * - easy_slip_enabled
 */

import { useState } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Plus, X } from 'lucide-react';
import { type SectionProps } from './types';

export function HITLSettingsSection({ formData, onChange, disabled }: SectionProps) {
  const [newTrigger, setNewTrigger] = useState('');

  const handleAddTrigger = () => {
    const trimmed = newTrigger.trim();
    if (trimmed && !formData.hitl_triggers.includes(trimmed)) {
      onChange('hitl_triggers', [...formData.hitl_triggers, trimmed]);
      setNewTrigger('');
    }
  };

  const handleRemoveTrigger = (triggerToRemove: string) => {
    onChange(
      'hitl_triggers',
      formData.hitl_triggers.filter((t) => t !== triggerToRemove)
    );
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      handleAddTrigger();
    }
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-lg">HITL (Human-In-The-Loop)</CardTitle>
        <p className="text-sm text-muted-foreground mt-2">
          อนุญาตให้มนุษย์แทรกแซงในการสนทนา
        </p>
      </CardHeader>
      <CardContent className="space-y-6">
        {/* HITL Enabled */}
        <div className="flex items-center justify-between">
          <div>
            <Label htmlFor="hitl-enabled" className="font-semibold">
              เปิดใช้งาน HITL
            </Label>
            <p className="text-xs text-muted-foreground mt-1">
              ระบบจะหยุดตอบและแจ้งเตือนทีมเมื่อตรงตามเงื่อนไข
            </p>
          </div>
          <Switch
            id="hitl-enabled"
            checked={formData.hitl_enabled}
            onCheckedChange={(checked) => onChange('hitl_enabled', checked)}
            disabled={disabled}
          />
        </div>

        {/* HITL Triggers */}
        {formData.hitl_enabled && (
          <div className="space-y-3 pl-4 border-l-2 border-muted">
            <Label className="font-semibold">Trigger Keywords</Label>
            <p className="text-xs text-muted-foreground">
              คำหรือวลีที่จะทำให้ระบบหยุดและแจ้งเตือนทีม
            </p>

            {/* Trigger List */}
            <div className="flex flex-wrap gap-2">
              {formData.hitl_triggers.map((trigger) => (
                <Badge
                  key={trigger}
                  variant="secondary"
                  className="flex items-center gap-1 px-3 py-1"
                >
                  {trigger}
                  <button
                    type="button"
                    onClick={() => handleRemoveTrigger(trigger)}
                    className="ml-1 hover:text-destructive"
                    disabled={disabled}
                    aria-label={`Remove trigger: ${trigger}`}
                  >
                    <X className="h-3 w-3" />
                  </button>
                </Badge>
              ))}
              {formData.hitl_triggers.length === 0 && (
                <span className="text-sm text-muted-foreground">
                  ยังไม่มี trigger keywords
                </span>
              )}
            </div>

            {/* Add Trigger Input */}
            <div className="flex gap-2">
              <Input
                placeholder="พิมพ์คำหรือวลี เช่น 'ขอคุยกับคน'"
                value={newTrigger}
                onChange={(e) => setNewTrigger(e.target.value)}
                onKeyDown={handleKeyDown}
                disabled={disabled}
                className="flex-1"
              />
              <Button
                type="button"
                variant="outline"
                size="icon"
                onClick={handleAddTrigger}
                disabled={disabled || !newTrigger.trim()}
                aria-label="Add trigger"
              >
                <Plus className="h-4 w-4" />
              </Button>
            </div>

            {/* Common Triggers Suggestions */}
            <div className="pt-2">
              <p className="text-xs text-muted-foreground mb-2">
                คำแนะนำ (คลิกเพื่อเพิ่ม):
              </p>
              <div className="flex flex-wrap gap-1">
                {['ขอคุยกับคน', 'ขอพูดกับ admin', 'มีปัญหา', 'ไม่เข้าใจ', 'complaint'].map(
                  (suggestion) =>
                    !formData.hitl_triggers.includes(suggestion) && (
                      <Button
                        key={suggestion}
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 text-xs"
                        onClick={() => onChange('hitl_triggers', [...formData.hitl_triggers, suggestion])}
                        disabled={disabled}
                      >
                        + {suggestion}
                      </Button>
                    )
                )}
              </div>
            </div>
          </div>
        )}

        {/* Lead Recovery */}
        <div className="flex items-center justify-between pt-4 border-t">
          <div>
            <Label htmlFor="lead-recovery" className="font-semibold">
              Lead Recovery
            </Label>
            <p className="text-xs text-muted-foreground mt-1">
              ติดตามลูกค้าอัตโนมัติเมื่อบทสนทนาเงียบ
            </p>
          </div>
          <Switch
            id="lead-recovery"
            checked={formData.lead_recovery_enabled}
            onCheckedChange={(checked) => onChange('lead_recovery_enabled', checked)}
            disabled={disabled}
          />
        </div>

        {/* Reply When Called */}
        <div className="flex items-center justify-between">
          <div>
            <Label htmlFor="reply-when-called" className="font-semibold">
              ตอบเมื่อถูกเรียกเท่านั้น
            </Label>
            <p className="text-xs text-muted-foreground mt-1">
              บอทจะตอบเฉพาะเมื่อถูกเรียกชื่อหรือ mention
            </p>
          </div>
          <Switch
            id="reply-when-called"
            checked={formData.reply_when_called_enabled}
            onCheckedChange={(checked) => onChange('reply_when_called_enabled', checked)}
            disabled={disabled}
          />
        </div>

        {formData.reply_when_called_enabled && formData.hitl_enabled && (
          <p className="text-sm text-amber-600 bg-amber-50 dark:bg-amber-900/30 dark:text-amber-400 p-3 rounded-lg">
            หมายเหตุ: เมื่อ HITL เปิดอยู่ ตัวเลือก "ตอบเมื่อถูกเรียกเท่านั้น" อาจไม่ทำงานตามปกติ
          </p>
        )}

        {/* Easy Slip */}
        <div className="flex items-center justify-between">
          <div>
            <Label htmlFor="easy-slip" className="font-semibold flex items-center gap-2">
              Easy Slip
              <Badge variant="outline" className="bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                Coming soon
              </Badge>
            </Label>
            <p className="text-xs text-muted-foreground mt-1">
              ตรวจสอบสลิปการโอนเงินอัตโนมัติ
            </p>
          </div>
          <Switch
            id="easy-slip"
            checked={formData.easy_slip_enabled}
            onCheckedChange={(checked) => onChange('easy_slip_enabled', checked)}
            disabled={true}
          />
        </div>
      </CardContent>
    </Card>
  );
}
