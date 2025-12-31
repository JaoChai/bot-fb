import { useState, useEffect } from 'react';
import { useNavigate, useParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import { Slider } from '@/components/ui/slider';
import { Textarea } from '@/components/ui/textarea';
import { useToast } from '@/hooks/use-toast';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ArrowLeft, Loader2, Clock, Plus, Trash2, Copy } from 'lucide-react';
import { apiGet, apiPut } from '@/lib/api';
import { AISettingsSection, type AISettings } from '@/components/bot/AISettingsSection';

// Response Hours types
interface TimeSlot {
  start: string;
  end: string;
}

interface DaySchedule {
  enabled: boolean;
  slots: TimeSlot[];
}

type DayKey = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';

interface ResponseHoursConfig {
  mon: DaySchedule;
  tue: DaySchedule;
  wed: DaySchedule;
  thu: DaySchedule;
  fri: DaySchedule;
  sat: DaySchedule;
  sun: DaySchedule;
}

// Constants
const DAYS: { key: DayKey; label: string }[] = [
  { key: 'mon', label: 'จันทร์' },
  { key: 'tue', label: 'อังคาร' },
  { key: 'wed', label: 'พุธ' },
  { key: 'thu', label: 'พฤหัสบดี' },
  { key: 'fri', label: 'ศุกร์' },
  { key: 'sat', label: 'เสาร์' },
  { key: 'sun', label: 'อาทิตย์' },
];

const TIMEZONES = [
  { value: 'Asia/Bangkok', label: 'Asia/Bangkok (GMT+7)' },
  { value: 'Asia/Singapore', label: 'Asia/Singapore (GMT+8)' },
  { value: 'Asia/Tokyo', label: 'Asia/Tokyo (GMT+9)' },
  { value: 'Asia/Hong_Kong', label: 'Asia/Hong Kong (GMT+8)' },
  { value: 'Asia/Shanghai', label: 'Asia/Shanghai (GMT+8)' },
  { value: 'UTC', label: 'UTC (GMT+0)' },
];

const createDefaultResponseHours = (): ResponseHoursConfig => ({
  mon: { enabled: true, slots: [{ start: '09:00', end: '18:00' }] },
  tue: { enabled: true, slots: [{ start: '09:00', end: '18:00' }] },
  wed: { enabled: true, slots: [{ start: '09:00', end: '18:00' }] },
  thu: { enabled: true, slots: [{ start: '09:00', end: '18:00' }] },
  fri: { enabled: true, slots: [{ start: '09:00', end: '18:00' }] },
  sat: { enabled: false, slots: [{ start: '10:00', end: '14:00' }] },
  sun: { enabled: false, slots: [{ start: '10:00', end: '14:00' }] },
});

// Parse API response to UI format
const parseResponseHours = (apiData: Record<string, TimeSlot[]> | null): ResponseHoursConfig => {
  const defaultHours = createDefaultResponseHours();
  if (!apiData) return defaultHours;

  const result: ResponseHoursConfig = { ...defaultHours };
  for (const day of DAYS) {
    const slots = apiData[day.key];
    if (slots && Array.isArray(slots) && slots.length > 0) {
      result[day.key] = { enabled: true, slots };
    } else {
      result[day.key] = { ...defaultHours[day.key], enabled: false };
    }
  }
  return result;
};

// Convert UI format to API format
const serializeResponseHours = (uiData: ResponseHoursConfig): Record<string, TimeSlot[]> => {
  const result: Record<string, TimeSlot[]> = {};
  for (const day of DAYS) {
    if (uiData[day.key].enabled && uiData[day.key].slots.length > 0) {
      result[day.key] = uiData[day.key].slots;
    }
  }
  return result;
};

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
  reply_sticker_enabled: boolean;
  response_hours_enabled: boolean;
  response_hours: ResponseHoursConfig;
  response_hours_timezone: string;
  offline_message: string;
  // AI Settings
  ai_settings: AISettings;
}

export function BotSettingsPage() {
  const navigate = useNavigate();
  const { botId } = useParams<{ botId: string }>();
  const { toast } = useToast();

  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [formData, setFormData] = useState<BotSettingsFormData>({
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
    reply_sticker_enabled: false,
    response_hours_enabled: false,
    response_hours: createDefaultResponseHours(),
    response_hours_timezone: 'Asia/Bangkok',
    offline_message: '',
    ai_settings: {
      use_semantic_router: false,
      semantic_router_threshold: 0.75,
      semantic_router_fallback: 'llm',
      use_confidence_cascade: false,
      cascade_confidence_threshold: 0.7,
      cascade_cheap_model: 'openai/gpt-4o-mini',
      cascade_expensive_model: 'openai/gpt-4o',
    },
  });

  // Response Hours helper functions
  const handleDayToggle = (day: DayKey, enabled: boolean) => {
    setFormData((prev) => ({
      ...prev,
      response_hours: {
        ...prev.response_hours,
        [day]: { ...prev.response_hours[day], enabled },
      },
    }));
  };

  const handleSlotChange = (day: DayKey, slotIndex: number, field: 'start' | 'end', value: string) => {
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

  const addSlot = (day: DayKey) => {
    setFormData((prev) => {
      const slots = prev.response_hours[day].slots;
      const lastSlot = slots[slots.length - 1];
      const newSlot = { start: lastSlot?.end || '09:00', end: '18:00' };
      return {
        ...prev,
        response_hours: {
          ...prev.response_hours,
          [day]: { ...prev.response_hours[day], slots: [...slots, newSlot] },
        },
      };
    });
  };

  const removeSlot = (day: DayKey, slotIndex: number) => {
    setFormData((prev) => {
      const slots = prev.response_hours[day].slots.filter((_, i) => i !== slotIndex);
      // Keep at least one slot
      if (slots.length === 0) {
        slots.push({ start: '09:00', end: '18:00' });
      }
      return {
        ...prev,
        response_hours: {
          ...prev.response_hours,
          [day]: { ...prev.response_hours[day], slots },
        },
      };
    });
  };

  const applyToAllDays = () => {
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

  // Fetch settings on mount
  useEffect(() => {
    if (!botId) return;

    const fetchSettings = async () => {
      try {
        setIsLoading(true);
        const response = await apiGet<{ data: Record<string, unknown> }>(`/bots/${botId}/settings`);
        const settings = response.data;

        setFormData({
          daily_message_limit: (settings.daily_message_limit as number) ?? 100,
          per_user_limit: (settings.per_user_limit as number) ?? 10,
          rate_limit_bot_message: (settings.rate_limit_bot_message as string) ?? '',
          rate_limit_user_message: (settings.rate_limit_user_message as string) ?? '',
          easy_slip_enabled: false,
          hitl_enabled: (settings.hitl_enabled as boolean) ?? false,
          reply_when_called_only: false,
          reply_when_called_only_override_hitl: false,
          lead_recovery_enabled: false,
          lead_recovery_description: 'ติดตามลูกค้าอัตโนมัติเมื่อบทสนทนาเงียบ',
          multiple_bubbles_enabled: (settings.multiple_bubbles_enabled as boolean) ?? false,
          multiple_bubbles_min: (settings.multiple_bubbles_min as number) ?? 1,
          multiple_bubbles_max: (settings.multiple_bubbles_max as number) ?? 3,
          wait_multiple_bubbles_enabled: (settings.wait_multiple_bubbles_enabled as boolean) ?? false,
          // Convert ms to seconds for display
          wait_multiple_bubbles_seconds: ((settings.wait_multiple_bubbles_ms as number) ?? 1500) / 1000,
          reply_sticker_enabled: false,
          response_hours_enabled: (settings.response_hours_enabled as boolean) ?? false,
          response_hours: parseResponseHours(settings.response_hours as Record<string, TimeSlot[]> | null),
          response_hours_timezone: (settings.response_hours_timezone as string) ?? 'Asia/Bangkok',
          offline_message: (settings.offline_message as string) ?? '',
          ai_settings: {
            use_semantic_router: (settings.use_semantic_router as boolean) ?? false,
            semantic_router_threshold: (settings.semantic_router_threshold as number) ?? 0.75,
            semantic_router_fallback: (settings.semantic_router_fallback as 'llm' | 'default_intent') ?? 'llm',
            use_confidence_cascade: (settings.use_confidence_cascade as boolean) ?? false,
            cascade_confidence_threshold: (settings.cascade_confidence_threshold as number) ?? 0.7,
            cascade_cheap_model: (settings.cascade_cheap_model as string) ?? 'openai/gpt-4o-mini',
            cascade_expensive_model: (settings.cascade_expensive_model as string) ?? 'openai/gpt-4o',
          },
        });
      } catch (error) {
        console.error('Failed to fetch settings:', error);
        toast({
          title: 'ข้อผิดพลาด',
          description: 'ไม่สามารถโหลดตั้งค่าบอทได้',
          variant: 'destructive',
        });
      } finally {
        setIsLoading(false);
      }
    };

    fetchSettings();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [botId]); // Remove toast from deps - it causes infinite loop

  const handleChange = <K extends keyof BotSettingsFormData>(
    field: K,
    value: BotSettingsFormData[K]
  ) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
  };

  const handleAISettingsChange = <K extends keyof AISettings>(
    field: K,
    value: AISettings[K]
  ) => {
    setFormData((prev) => ({
      ...prev,
      ai_settings: { ...prev.ai_settings, [field]: value },
    }));
  };

  const handleSave = async () => {
    if (!botId) return;

    setIsSaving(true);
    try {
      await apiPut(`/bots/${botId}/settings`, {
        daily_message_limit: formData.daily_message_limit,
        per_user_limit: formData.per_user_limit,
        rate_limit_bot_message: formData.rate_limit_bot_message || null,
        rate_limit_user_message: formData.rate_limit_user_message || null,
        hitl_enabled: formData.hitl_enabled,
        response_hours_enabled: formData.response_hours_enabled,
        response_hours: serializeResponseHours(formData.response_hours),
        response_hours_timezone: formData.response_hours_timezone,
        offline_message: formData.offline_message || null,
        // Multiple bubbles settings
        multiple_bubbles_enabled: formData.multiple_bubbles_enabled,
        multiple_bubbles_min: formData.multiple_bubbles_min,
        multiple_bubbles_max: formData.multiple_bubbles_max,
        wait_multiple_bubbles_enabled: formData.wait_multiple_bubbles_enabled,
        // Convert seconds to ms for backend
        wait_multiple_bubbles_ms: Math.round(formData.wait_multiple_bubbles_seconds * 1000),
        // AI Settings
        use_semantic_router: formData.ai_settings.use_semantic_router,
        semantic_router_threshold: formData.ai_settings.semantic_router_threshold,
        semantic_router_fallback: formData.ai_settings.semantic_router_fallback,
        use_confidence_cascade: formData.ai_settings.use_confidence_cascade,
        cascade_confidence_threshold: formData.ai_settings.cascade_confidence_threshold,
        cascade_cheap_model: formData.ai_settings.cascade_cheap_model || null,
        cascade_expensive_model: formData.ai_settings.cascade_expensive_model || null,
      });

      toast({
        title: 'บันทึกสำเร็จ',
        description: 'ตั้งค่าบอทได้รับการบันทึกแล้ว',
      });
    } catch (error) {
      console.error('Failed to save settings:', error);
      toast({
        title: 'ข้อผิดพลาด',
        description: 'ไม่สามารถบันทึกตั้งค่าบอทได้',
        variant: 'destructive',
      });
    } finally {
      setIsSaving(false);
    }
  };

  // Loading state
  if (isLoading) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-background">
      <div className="max-w-4xl mx-auto px-4 py-8">
        {/* Header */}
        <div className="flex items-center gap-4 mb-8">
          <Button
            variant="ghost"
            size="icon"
            onClick={() => navigate('/bots')}
            aria-label="กลับไปหน้าการเชื่อมต่อ"
          >
            <ArrowLeft className="h-5 w-5" />
          </Button>
          <div>
            <h1 className="text-2xl sm:text-3xl font-bold tracking-tight">ตั้งค่าบอท</h1>
            <p className="text-muted-foreground mt-1">กำหนดพฤติกรรมและตัวเลือกเพิ่มเติมสำหรับบอท</p>
          </div>
        </div>

        <div className="space-y-6">
          {/* Rate Limiting */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">จำกัดการใช้งาน</CardTitle>
              <p className="text-sm text-muted-foreground mt-2">ป้องกันสแปมและการใช้ประโยชน์มากเกินไป</p>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="daily-limit" className="font-semibold">
                  จำนวนข้อความต่อวัน: {formData.daily_message_limit}
                </Label>
                <Slider
                  id="daily-limit"
                  min={10}
                  max={1000}
                  step={10}
                  value={[formData.daily_message_limit]}
                  onValueChange={(value) => handleChange('daily_message_limit', value[0])}
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="user-limit" className="font-semibold">
                  จำนวนข้อความต่อคนต่อวัน: {formData.per_user_limit}
                </Label>
                <Slider
                  id="user-limit"
                  min={1}
                  max={100}
                  step={1}
                  value={[formData.per_user_limit]}
                  onValueChange={(value) => handleChange('per_user_limit', value[0])}
                />
              </div>

              <div className="border-t pt-4 mt-4">
                <p className="text-sm text-muted-foreground mb-4">
                  ข้อความเมื่อถูกจำกัด (เว้นว่างไว้ = บอทจะไม่ตอบ)
                </p>

                <div className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="rate-limit-bot-msg" className="font-semibold">
                      ข้อความเมื่อบอทถูกจำกัด (รวมทุกคน)
                    </Label>
                    <Textarea
                      id="rate-limit-bot-msg"
                      placeholder="ตัวอย่าง: ขออภัยครับ บอทได้รับข้อความจำนวนมากในวันนี้ กรุณาลองใหม่พรุ่งนี้ครับ"
                      value={formData.rate_limit_bot_message}
                      onChange={(e) => handleChange('rate_limit_bot_message', e.target.value)}
                      rows={2}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="rate-limit-user-msg" className="font-semibold">
                      ข้อความเมื่อผู้ใช้ถูกจำกัด (ต่อคน)
                    </Label>
                    <Textarea
                      id="rate-limit-user-msg"
                      placeholder="ตัวอย่าง: ขออภัยครับ คุณส่งข้อความครบจำนวนที่กำหนดต่อวันแล้ว กรุณาลองใหม่พรุ่งนี้ครับ"
                      value={formData.rate_limit_user_message}
                      onChange={(e) => handleChange('rate_limit_user_message', e.target.value)}
                      rows={2}
                    />
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* AI Optimization Section */}
          <div className="pt-4">
            <h2 className="text-xl font-semibold mb-4 flex items-center gap-2">
              AI Optimization
              <Badge variant="outline" className="bg-gradient-to-r from-purple-50 to-blue-50 text-purple-700 dark:from-purple-950 dark:to-blue-950 dark:text-purple-400">
                ใหม่
              </Badge>
            </h2>
            <AISettingsSection
              settings={formData.ai_settings}
              onChange={handleAISettingsChange}
            />
          </div>

          {/* Easy Slip */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg flex items-center gap-2">
                เช็คสลิป
                <Badge variant="outline" className="bg-slate-100 text-slate-700">Coming soon</Badge>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center justify-between">
                <Label htmlFor="easy-slip" className="font-semibold">
                  เปิดใช้งาน Easy Slip Verification
                </Label>
                <Switch
                  id="easy-slip"
                  checked={formData.easy_slip_enabled}
                  onCheckedChange={(checked) => handleChange('easy_slip_enabled', checked)}
                  disabled
                />
              </div>
            </CardContent>
          </Card>

          {/* HITL */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">HITL (Human in the Loop)</CardTitle>
              <p className="text-sm text-muted-foreground mt-2">อนุญาตให้ผู้คนแทรกแซงในการสนทนา</p>
            </CardHeader>
            <CardContent>
              <div className="flex items-center justify-between">
                <Label htmlFor="hitl" className="font-semibold">
                  เปิดใช้งาน HITL
                </Label>
                <Switch
                  id="hitl"
                  checked={formData.hitl_enabled}
                  onCheckedChange={(checked) => handleChange('hitl_enabled', checked)}
                />
              </div>
            </CardContent>
          </Card>

          {/* Reply When Called Only */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">การเรียกบอท</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center justify-between">
                <Label htmlFor="called-only" className="font-semibold">
                  บอทจะตอบเมื่อถูกเรียกเท่านั้น
                </Label>
                <Switch
                  id="called-only"
                  checked={formData.reply_when_called_only}
                  onCheckedChange={(checked) => handleChange('reply_when_called_only', checked)}
                />
              </div>

              {formData.reply_when_called_only && (
                <p className="text-sm text-slate-600 bg-slate-50 dark:bg-slate-900/30 p-3 rounded-lg">
                  ℹ️ ถ้า HITL เปิดอยู่ ตัวเลือกนี้จะถูกปิด
                </p>
              )}
            </CardContent>
          </Card>

          {/* Lead Recovery */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">Lead Recovery</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center justify-between">
                <Label htmlFor="lead-recovery" className="font-semibold">
                  เปิดใช้งาน Lead Recovery
                </Label>
                <Switch
                  id="lead-recovery"
                  checked={formData.lead_recovery_enabled}
                  onCheckedChange={(checked) => handleChange('lead_recovery_enabled', checked)}
                />
              </div>

              {formData.lead_recovery_enabled && (
                <div className="space-y-2">
                  <Label htmlFor="lead-recovery-desc" className="font-semibold">
                    คำอธิบาย
                  </Label>
                  <Input
                    id="lead-recovery-desc"
                    placeholder="ติดตามลูกค้าอัตโนมัติเมื่อบทสนทนาเงียบ"
                    value={formData.lead_recovery_description}
                    onChange={(e) => handleChange('lead_recovery_description', e.target.value)}
                  />
                </div>
              )}
            </CardContent>
          </Card>

          {/* Multiple Bubbles */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">
                การตอบแบบหลายบอลลูน
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center justify-between">
                <Label htmlFor="bubbles" className="font-semibold">
                  เปิดใช้งาน Multiple Bubbles
                </Label>
                <Switch
                  id="bubbles"
                  checked={formData.multiple_bubbles_enabled}
                  onCheckedChange={(checked) => handleChange('multiple_bubbles_enabled', checked)}
                />
              </div>

              {formData.multiple_bubbles_enabled && (
                <div className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="bubbles-min" className="font-semibold">
                      จำนวนบอลลูนขั้นต่ำ: {formData.multiple_bubbles_min}
                    </Label>
                    <Slider
                      id="bubbles-min"
                      min={1}
                      max={3}
                      step={1}
                      value={[formData.multiple_bubbles_min]}
                      onValueChange={(value) => handleChange('multiple_bubbles_min', value[0])}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="bubbles-max" className="font-semibold">
                      จำนวนบอลลูนสูงสุด: {formData.multiple_bubbles_max}
                    </Label>
                    <Slider
                      id="bubbles-max"
                      min={2}
                      max={5}
                      step={1}
                      value={[formData.multiple_bubbles_max]}
                      onValueChange={(value) => handleChange('multiple_bubbles_max', value[0])}
                    />
                  </div>
                </div>
              )}
            </CardContent>
          </Card>

          {/* Wait Multiple Bubbles */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">รออ่านหลายบอลลูน</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center justify-between">
                <Label htmlFor="wait-bubbles" className="font-semibold">
                  เปิดใช้งาน
                </Label>
                <Switch
                  id="wait-bubbles"
                  checked={formData.wait_multiple_bubbles_enabled}
                  onCheckedChange={(checked) => handleChange('wait_multiple_bubbles_enabled', checked)}
                />
              </div>

              {formData.wait_multiple_bubbles_enabled && (
                <div className="space-y-2">
                  <Label htmlFor="wait-seconds" className="font-semibold">
                    เวลารอ: {formData.wait_multiple_bubbles_seconds.toFixed(1)} วินาที
                  </Label>
                  <Slider
                    id="wait-seconds"
                    min={0.5}
                    max={20}
                    step={0.5}
                    value={[formData.wait_multiple_bubbles_seconds]}
                    onValueChange={(value) => handleChange('wait_multiple_bubbles_seconds', value[0])}
                  />
                </div>
              )}
            </CardContent>
          </Card>

          {/* Reply Sticker */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">การตอบกลับ Sticker</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex items-center justify-between">
                <Label htmlFor="sticker" className="font-semibold">
                  เปิดใช้งาน Reply Sticker
                </Label>
                <Switch
                  id="sticker"
                  checked={formData.reply_sticker_enabled}
                  onCheckedChange={(checked) => handleChange('reply_sticker_enabled', checked)}
                />
              </div>
            </CardContent>
          </Card>

          {/* Response Hours */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg flex items-center gap-2">
                <Clock className="h-5 w-5" />
                Response Hours
              </CardTitle>
              <p className="text-sm text-muted-foreground mt-2">บอทจะตอบตามวันและเวลาที่กำหนด</p>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center justify-between">
                <Label htmlFor="response-hours" className="font-semibold">
                  เปิดใช้งาน Response Hours
                </Label>
                <Switch
                  id="response-hours"
                  checked={formData.response_hours_enabled}
                  onCheckedChange={(checked) => handleChange('response_hours_enabled', checked)}
                />
              </div>

              {formData.response_hours_enabled && (
                <div className="space-y-4 pt-4 border-t">
                  {/* Timezone Selection */}
                  <div className="space-y-2">
                    <Label className="font-semibold">Timezone</Label>
                    <Select
                      value={formData.response_hours_timezone}
                      onValueChange={(value) => handleChange('response_hours_timezone', value)}
                    >
                      <SelectTrigger className="w-full sm:w-64">
                        <SelectValue placeholder="เลือก timezone" />
                      </SelectTrigger>
                      <SelectContent>
                        {TIMEZONES.map((tz) => (
                          <SelectItem key={tz.value} value={tz.value}>
                            {tz.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  {/* Day Schedule */}
                  <div className="space-y-3">
                    <div className="flex items-center justify-between">
                      <Label className="font-semibold">ตารางเวลา</Label>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={applyToAllDays}
                        className="text-xs"
                      >
                        <Copy className="h-3 w-3 mr-1" />
                        ใช้เวลาจันทร์กับทุกวัน
                      </Button>
                    </div>

                    <div className="space-y-2 rounded-lg border p-3">
                      {DAYS.map((day) => (
                        <div key={day.key} className="space-y-2">
                          <div className="flex items-center gap-3">
                            <Switch
                              checked={formData.response_hours[day.key].enabled}
                              onCheckedChange={(checked) => handleDayToggle(day.key, checked)}
                            />
                            <span className={`w-20 text-sm font-medium ${!formData.response_hours[day.key].enabled ? 'text-muted-foreground' : ''}`}>
                              {day.label}
                            </span>

                            {formData.response_hours[day.key].enabled ? (
                              <div className="flex-1 space-y-1">
                                {formData.response_hours[day.key].slots.map((slot, slotIndex) => (
                                  <div key={slotIndex} className="flex items-center gap-2">
                                    <Input
                                      type="time"
                                      value={slot.start}
                                      onChange={(e) => handleSlotChange(day.key, slotIndex, 'start', e.target.value)}
                                      className="w-28"
                                    />
                                    <span className="text-muted-foreground">-</span>
                                    <Input
                                      type="time"
                                      value={slot.end}
                                      onChange={(e) => handleSlotChange(day.key, slotIndex, 'end', e.target.value)}
                                      className="w-28"
                                    />
                                    {formData.response_hours[day.key].slots.length > 1 && (
                                      <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => removeSlot(day.key, slotIndex)}
                                        className="h-8 w-8 text-muted-foreground hover:text-destructive"
                                      >
                                        <Trash2 className="h-4 w-4" />
                                      </Button>
                                    )}
                                  </div>
                                ))}
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => addSlot(day.key)}
                                  className="text-xs text-muted-foreground"
                                >
                                  <Plus className="h-3 w-3 mr-1" />
                                  เพิ่มช่วงเวลา
                                </Button>
                              </div>
                            ) : (
                              <span className="text-sm text-muted-foreground">ปิด</span>
                            )}
                          </div>
                          {day.key !== 'sun' && <div className="border-b" />}
                        </div>
                      ))}
                    </div>
                  </div>

                  {/* Offline Message */}
                  <div className="space-y-2">
                    <Label htmlFor="offline-message" className="font-semibold">
                      ข้อความนอกเวลาทำการ
                    </Label>
                    <Textarea
                      id="offline-message"
                      placeholder="ตัวอย่าง: ขณะนี้อยู่นอกเวลาทำการ กรุณาติดต่อใหม่ในเวลา 09:00-18:00 น. วันจันทร์-ศุกร์ครับ"
                      value={formData.offline_message}
                      onChange={(e) => handleChange('offline_message', e.target.value)}
                      rows={2}
                    />
                    <p className="text-xs text-muted-foreground">
                      เว้นว่างไว้ = บอทจะไม่ตอบนอกเวลาทำการ
                    </p>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>

          {/* Save Button */}
          <div className="flex gap-3 pt-4">
            <Button
              onClick={handleSave}
              disabled={isSaving}
              className="flex-1"
            >
              {isSaving ? (
                <>
                  <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                  บันทึก...
                </>
              ) : (
                'บันทึกตั้งค่า'
              )}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}
