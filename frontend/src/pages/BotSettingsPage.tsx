import { useState, useEffect } from 'react';
import { useParams } from 'react-router';
import { Loader2, Gauge, Clock, Bot as BotIcon, Smile } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { useToast } from '@/hooks/use-toast';
import { useBotSettings, useUpdateBotSettings } from '@/hooks/useBotSettings';
import {
  createDefaultResponseHours,
  parseResponseHours,
  serializeResponseHours,
  type DayKey,
  type ResponseHoursConfig,
  type TimeSlot,
} from '@/hooks/useResponseHours';
import { PageHeader, StickyActionBar } from '@/components/connections';
import { RateLimitTab } from '@/components/bot-settings/RateLimitTab';
import { ResponseHoursTab } from '@/components/bot-settings/ResponseHoursTab';
import { BehaviorTab } from '@/components/bot-settings/BehaviorTab';
import { StickerReplyTab } from '@/components/bot-settings/StickerReplyTab';
import { cn } from '@/lib/utils';
import type { BotSettings } from '@/types/api';

const TABS = [
  { value: 'rate-limit', label: 'ข้อจำกัด', icon: Gauge },
  { value: 'response-hours', label: 'เวลาตอบกลับ', icon: Clock },
  { value: 'behavior', label: 'พฤติกรรม', icon: BotIcon },
  { value: 'sticker', label: 'สติกเกอร์', icon: Smile },
] as const;

type TabValue = (typeof TABS)[number]['value'];

interface BotSettingsFormData {
  daily_message_limit: number;
  per_user_limit: number;
  rate_limit_bot_message: string;
  rate_limit_user_message: string;
  easy_slip_enabled: boolean;
  hitl_enabled: boolean;
  reply_when_called_only: boolean;
  reply_when_called_only_override_hitl: boolean;
  lead_recovery_enabled: boolean;
  lead_recovery_description: string;
  multiple_bubbles_enabled: boolean;
  multiple_bubbles_min: number;
  multiple_bubbles_max: number;
  wait_multiple_bubbles_enabled: boolean;
  wait_multiple_bubbles_seconds: number;
  smart_aggregation_enabled: boolean;
  smart_min_wait_seconds: number;
  smart_max_wait_seconds: number;
  smart_early_trigger_enabled: boolean;
  smart_per_user_learning_enabled: boolean;
  reply_sticker_enabled: boolean;
  reply_sticker_message: string;
  reply_sticker_mode: 'static' | 'ai';
  reply_sticker_ai_prompt: string;
  response_hours_enabled: boolean;
  response_hours: ResponseHoursConfig;
  response_hours_timezone: string;
  offline_message: string;
}

const DEFAULT_FORM: BotSettingsFormData = {
  daily_message_limit: 100,
  per_user_limit: 10,
  rate_limit_bot_message: '',
  rate_limit_user_message: '',
  easy_slip_enabled: false,
  hitl_enabled: false,
  reply_when_called_only: false,
  reply_when_called_only_override_hitl: false,
  lead_recovery_enabled: false,
  lead_recovery_description: 'ติดตามลูกค้าอัตโนมัติเมื่อบทสนทนาเงียบ',
  multiple_bubbles_enabled: false,
  multiple_bubbles_min: 1,
  multiple_bubbles_max: 3,
  wait_multiple_bubbles_enabled: false,
  wait_multiple_bubbles_seconds: 1.5,
  smart_aggregation_enabled: false,
  smart_min_wait_seconds: 0.5,
  smart_max_wait_seconds: 3,
  smart_early_trigger_enabled: true,
  smart_per_user_learning_enabled: false,
  reply_sticker_enabled: false,
  reply_sticker_message: '',
  reply_sticker_mode: 'static',
  reply_sticker_ai_prompt: '',
  response_hours_enabled: false,
  response_hours: createDefaultResponseHours(),
  response_hours_timezone: 'Asia/Bangkok',
  offline_message: '',
};

export function BotSettingsPage() {
  const { botId } = useParams<{ botId: string }>();
  const { toast } = useToast();
  const numericBotId = botId ? Number(botId) : null;

  const { data: serverSettings, isLoading } = useBotSettings(numericBotId);
  const updateMutation = useUpdateBotSettings(numericBotId);
  const isSaving = updateMutation.isPending;

  const [formData, setFormData] = useState<BotSettingsFormData>(DEFAULT_FORM);
  const [tab, setTab] = useState<TabValue>('rate-limit');
  const [isDirty, setIsDirty] = useState(false);

  // Sync form data from server settings
  useEffect(() => {
    if (!serverSettings) return;
    const s = serverSettings as unknown as Record<string, unknown>;
    setFormData({
      daily_message_limit: (s.daily_message_limit as number) ?? 100,
      per_user_limit: (s.per_user_limit as number) ?? 10,
      rate_limit_bot_message: (s.rate_limit_bot_message as string) ?? '',
      rate_limit_user_message: (s.rate_limit_user_message as string) ?? '',
      easy_slip_enabled: false,
      hitl_enabled: (s.hitl_enabled as boolean) ?? false,
      reply_when_called_only: false,
      reply_when_called_only_override_hitl: false,
      lead_recovery_enabled: false,
      lead_recovery_description: 'ติดตามลูกค้าอัตโนมัติเมื่อบทสนทนาเงียบ',
      multiple_bubbles_enabled: (s.multiple_bubbles_enabled as boolean) ?? false,
      multiple_bubbles_min: (s.multiple_bubbles_min as number) ?? 1,
      multiple_bubbles_max: (s.multiple_bubbles_max as number) ?? 3,
      wait_multiple_bubbles_enabled: (s.wait_multiple_bubbles_enabled as boolean) ?? false,
      wait_multiple_bubbles_seconds: ((s.wait_multiple_bubbles_ms as number) ?? 1500) / 1000,
      smart_aggregation_enabled: (s.smart_aggregation_enabled as boolean) ?? false,
      smart_min_wait_seconds: ((s.smart_min_wait_ms as number) ?? 500) / 1000,
      smart_max_wait_seconds: ((s.smart_max_wait_ms as number) ?? 3000) / 1000,
      smart_early_trigger_enabled: (s.smart_early_trigger_enabled as boolean) ?? true,
      smart_per_user_learning_enabled: (s.smart_per_user_learning_enabled as boolean) ?? false,
      reply_sticker_enabled: (s.reply_sticker_enabled as boolean) ?? false,
      reply_sticker_message: (s.reply_sticker_message as string) ?? '',
      reply_sticker_mode: (s.reply_sticker_mode as 'static' | 'ai') ?? 'static',
      reply_sticker_ai_prompt: (s.reply_sticker_ai_prompt as string) ?? '',
      response_hours_enabled: (s.response_hours_enabled as boolean) ?? false,
      response_hours: parseResponseHours(s.response_hours as Record<string, TimeSlot[]> | null),
      response_hours_timezone: (s.response_hours_timezone as string) ?? 'Asia/Bangkok',
      offline_message: (s.offline_message as string) ?? '',
    });
  }, [serverSettings]);

  // Track dirty state
  useEffect(() => {
    if (!serverSettings) {
      setIsDirty(false);
      return;
    }
    const s = serverSettings as unknown as Record<string, unknown>;
    const dirty =
      formData.daily_message_limit !== ((s.daily_message_limit as number) ?? 100) ||
      formData.per_user_limit !== ((s.per_user_limit as number) ?? 10) ||
      formData.rate_limit_bot_message !== ((s.rate_limit_bot_message as string) ?? '') ||
      formData.rate_limit_user_message !== ((s.rate_limit_user_message as string) ?? '') ||
      formData.hitl_enabled !== ((s.hitl_enabled as boolean) ?? false) ||
      formData.response_hours_enabled !== ((s.response_hours_enabled as boolean) ?? false) ||
      formData.response_hours_timezone !== ((s.response_hours_timezone as string) ?? 'Asia/Bangkok') ||
      formData.offline_message !== ((s.offline_message as string) ?? '') ||
      formData.multiple_bubbles_enabled !== ((s.multiple_bubbles_enabled as boolean) ?? false) ||
      formData.smart_aggregation_enabled !== ((s.smart_aggregation_enabled as boolean) ?? false) ||
      formData.reply_sticker_enabled !== ((s.reply_sticker_enabled as boolean) ?? false);
    setIsDirty(dirty);
  }, [formData, serverSettings]);

  const onFieldChange = (field: string, value: unknown) => {
    setFormData((prev) => ({ ...prev, [field]: value }) as BotSettingsFormData);
  };

  // Response Hours handlers
  const handleDayToggle = (day: DayKey, enabled: boolean) => {
    setFormData((prev) => ({
      ...prev,
      response_hours: {
        ...prev.response_hours,
        [day]: { ...prev.response_hours[day], enabled },
      },
    }));
  };

  const handleSlotChange = (
    day: DayKey,
    slotIndex: number,
    field: 'start' | 'end',
    value: string
  ) => {
    setFormData((prev) => {
      const newSlots = [...prev.response_hours[day].slots];
      newSlots[slotIndex] = { ...newSlots[slotIndex], [field]: value };
      return {
        ...prev,
        response_hours: {
          ...prev.response_hours,
          [day]: { ...prev.response_hours[day], slots: newSlots },
        },
      };
    });
  };

  const handleAddSlot = (day: DayKey) => {
    setFormData((prev) => {
      const slots = prev.response_hours[day].slots;
      const lastSlot = slots[slots.length - 1];
      const newSlot = { start: lastSlot?.end ?? '09:00', end: '18:00' };
      return {
        ...prev,
        response_hours: {
          ...prev.response_hours,
          [day]: { ...prev.response_hours[day], slots: [...slots, newSlot] },
        },
      };
    });
  };

  const handleRemoveSlot = (day: DayKey, slotIndex: number) => {
    setFormData((prev) => {
      let slots = prev.response_hours[day].slots.filter((_, i) => i !== slotIndex);
      if (slots.length === 0) slots = [{ start: '09:00', end: '18:00' }];
      return {
        ...prev,
        response_hours: {
          ...prev.response_hours,
          [day]: { ...prev.response_hours[day], slots },
        },
      };
    });
  };

  const handleApplyToAllDays = () => {
    const mondaySchedule = formData.response_hours.mon;
    setFormData((prev) => ({
      ...prev,
      response_hours: {
        mon: mondaySchedule,
        tue: { ...mondaySchedule },
        wed: { ...mondaySchedule },
        thu: { ...mondaySchedule },
        fri: { ...mondaySchedule },
        sat: { ...mondaySchedule },
        sun: { ...mondaySchedule },
      },
    }));
  };

  const handleSave = async () => {
    if (!numericBotId) return;
    try {
      await updateMutation.mutateAsync({
        daily_message_limit: formData.daily_message_limit,
        per_user_limit: formData.per_user_limit,
        rate_limit_bot_message: formData.rate_limit_bot_message || null,
        rate_limit_user_message: formData.rate_limit_user_message || null,
        hitl_enabled: formData.hitl_enabled,
        response_hours_enabled: formData.response_hours_enabled,
        response_hours: serializeResponseHours(formData.response_hours) as BotSettings['response_hours'],
        response_hours_timezone: formData.response_hours_timezone,
        offline_message: formData.offline_message || null,
        multiple_bubbles_enabled: formData.multiple_bubbles_enabled,
        multiple_bubbles_min: formData.multiple_bubbles_min,
        multiple_bubbles_max: formData.multiple_bubbles_max,
        wait_multiple_bubbles_enabled: formData.wait_multiple_bubbles_enabled,
        wait_multiple_bubbles_ms: Math.round(formData.wait_multiple_bubbles_seconds * 1000),
        smart_aggregation_enabled: formData.smart_aggregation_enabled,
        smart_min_wait_ms: Math.round(formData.smart_min_wait_seconds * 1000),
        smart_max_wait_ms: Math.round(formData.smart_max_wait_seconds * 1000),
        smart_early_trigger_enabled: formData.smart_early_trigger_enabled,
        smart_per_user_learning_enabled: formData.smart_per_user_learning_enabled,
        reply_sticker_enabled: formData.reply_sticker_enabled,
        reply_sticker_message: formData.reply_sticker_message || null,
        reply_sticker_mode: formData.reply_sticker_mode,
        reply_sticker_ai_prompt: formData.reply_sticker_ai_prompt || null,
      });
      setIsDirty(false);
      toast({ title: 'บันทึกสำเร็จ', description: 'ตั้งค่าบอทได้รับการบันทึกแล้ว' });
    } catch {
      toast({
        title: 'ข้อผิดพลาด',
        description: 'ไม่สามารถบันทึกตั้งค่าบอทได้',
        variant: 'destructive',
      });
    }
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-[40vh]">
        <Loader2 className="h-7 w-7 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="ตั้งค่าบอท"
        description="กำหนดพฤติกรรมและตัวเลือกเพิ่มเติมสำหรับบอท"
        backTo="/bots"
        breadcrumb={[
          { label: 'การเชื่อมต่อ', to: '/bots' },
          { label: `Bot #${numericBotId}` },
          { label: 'ตั้งค่า' },
        ]}
        actions={
          isDirty ? (
            <Badge variant="outline" className="text-xs gap-1.5">
              <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
              มีการเปลี่ยนแปลง
            </Badge>
          ) : null
        }
      />

      <div className="grid gap-6 md:grid-cols-[220px_1fr] md:gap-8">
        <aside className="md:border-r md:pr-6">
          <nav className="flex md:flex-col gap-1 overflow-x-auto md:overflow-visible -mx-1 px-1">
            {TABS.map((t) => {
              const Icon = t.icon;
              const isActive = tab === t.value;
              return (
                <button
                  key={t.value}
                  type="button"
                  onClick={() => setTab(t.value)}
                  className={cn(
                    'relative flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors text-left shrink-0',
                    'before:absolute before:left-0 before:top-1/2 before:-translate-y-1/2 before:h-4 before:w-0.5 before:rounded-full before:bg-primary before:transition-opacity',
                    isActive
                      ? 'bg-accent text-foreground before:opacity-100'
                      : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground before:opacity-0',
                  )}
                >
                  <Icon className="h-4 w-4 shrink-0" strokeWidth={1.5} />
                  <span>{t.label}</span>
                </button>
              );
            })}
          </nav>
        </aside>

        <div className="min-w-0 space-y-6">
          {tab === 'rate-limit' && (
            <RateLimitTab
              daily_message_limit={formData.daily_message_limit}
              per_user_limit={formData.per_user_limit}
              rate_limit_bot_message={formData.rate_limit_bot_message}
              rate_limit_user_message={formData.rate_limit_user_message}
              onChange={onFieldChange}
            />
          )}
          {tab === 'response-hours' && (
            <ResponseHoursTab
              response_hours_enabled={formData.response_hours_enabled}
              response_hours={formData.response_hours}
              response_hours_timezone={formData.response_hours_timezone}
              offline_message={formData.offline_message}
              onChange={onFieldChange}
              onDayToggle={handleDayToggle}
              onSlotChange={handleSlotChange}
              onAddSlot={handleAddSlot}
              onRemoveSlot={handleRemoveSlot}
              onApplyToAllDays={handleApplyToAllDays}
            />
          )}
          {tab === 'behavior' && (
            <BehaviorTab
              hitl_enabled={formData.hitl_enabled}
              multiple_bubbles_enabled={formData.multiple_bubbles_enabled}
              multiple_bubbles_min={formData.multiple_bubbles_min}
              multiple_bubbles_max={formData.multiple_bubbles_max}
              wait_multiple_bubbles_enabled={formData.wait_multiple_bubbles_enabled}
              wait_multiple_bubbles_seconds={formData.wait_multiple_bubbles_seconds}
              smart_aggregation_enabled={formData.smart_aggregation_enabled}
              smart_min_wait_seconds={formData.smart_min_wait_seconds}
              smart_max_wait_seconds={formData.smart_max_wait_seconds}
              smart_early_trigger_enabled={formData.smart_early_trigger_enabled}
              smart_per_user_learning_enabled={formData.smart_per_user_learning_enabled}
              onChange={onFieldChange}
            />
          )}
          {tab === 'sticker' && (
            <StickerReplyTab
              reply_sticker_enabled={formData.reply_sticker_enabled}
              reply_sticker_mode={formData.reply_sticker_mode}
              reply_sticker_message={formData.reply_sticker_message}
              reply_sticker_ai_prompt={formData.reply_sticker_ai_prompt}
              onChange={onFieldChange}
            />
          )}
        </div>
      </div>

      <StickyActionBar>
        <span className="text-sm text-muted-foreground hidden sm:block">
          {isDirty ? 'มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก' : 'การเปลี่ยนแปลงจะมีผลทันทีหลังบันทึก'}
        </span>
        <Button onClick={handleSave} disabled={isSaving} className="min-w-[100px]">
          {isSaving ? (
            <>
              <Loader2 className="h-4 w-4 mr-2 animate-spin" />
              บันทึก...
            </>
          ) : (
            'บันทึกตั้งค่า'
          )}
        </Button>
      </StickyActionBar>
    </div>
  );
}
