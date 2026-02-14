import { useState, useMemo } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Loader2, Save, AlertCircle, DollarSign, AlertTriangle, Bell, Mail, Slack } from 'lucide-react';
import { useQAInspectorSettings, useUpdateQAInspectorSettings } from '@/hooks/useQAInspector';
import { QAInspectorToggle } from './QAInspectorToggle';
import { ModelLayerConfig } from './ModelLayerConfig';
import type { QAInspectorSettings as QASettings, QAReportSchedule, DeepPartial } from '@/types/qa-inspector';

// Report schedule options
const REPORT_SCHEDULES: { value: QAReportSchedule; label: string }[] = [
  { value: 'monday_00:00', label: 'จันทร์ 00:00' },
  { value: 'monday_09:00', label: 'จันทร์ 09:00' },
  { value: 'friday_18:00', label: 'ศุกร์ 18:00' },
  { value: 'sunday_00:00', label: 'อาทิตย์ 00:00' },
];

// Base monthly cost at 100% sampling (200 conversations/day)
const BASE_MONTHLY_COST = 62;

interface QAInspectorSettingsProps {
  botId: number;
}

/**
 * Full QA Inspector settings component with model configuration.
 * Allows users to enable/disable QA Inspector and configure AI models
 * for each evaluation layer (realtime, analysis, report).
 */
export function QAInspectorSettings({ botId }: QAInspectorSettingsProps) {
  const { data: settings, isLoading, isError } = useQAInspectorSettings(botId);
  const updateMutation = useUpdateQAInspectorSettings(botId);

  // Compute initial form data from settings
  const initialFormData = useMemo<DeepPartial<QASettings>>(() => {
    if (!settings) return {};
    return {
      qa_inspector_enabled: settings.qa_inspector_enabled,
      models: settings.models,
      settings: {
        score_threshold: settings.settings?.score_threshold ?? 0.70,
        sampling_rate: settings.settings?.sampling_rate ?? 100,
        report_schedule: settings.settings?.report_schedule ?? 'monday_00:00',
      },
      notifications: {
        email: settings.notifications?.email ?? true,
        alert: settings.notifications?.alert ?? false,
        slack: settings.notifications?.slack ?? false,
      },
    };
  }, [settings]);

  // Track settings version to reset form when server data changes
  const settingsVersion = settings?.qa_inspector_enabled !== undefined ? JSON.stringify(settings) : '';

  // Local state for form modifications (only stores changes from initial)
  // Using a function initializer that resets when settingsVersion changes
  const [formState, setFormState] = useState<{
    overrides: DeepPartial<QASettings>;
    hasChanges: boolean;
    version: string;
  }>({ overrides: {}, hasChanges: false, version: settingsVersion });

  // If settings changed (new version), reset the form state
  const hasChanges = formState.version === settingsVersion ? formState.hasChanges : false;

  // Wrapper to update overrides
  const setFormOverrides = (updater: (prev: DeepPartial<QASettings>) => DeepPartial<QASettings>) => {
    setFormState((prev) => ({
      version: settingsVersion,
      overrides: updater(prev.version === settingsVersion ? prev.overrides : {}),
      hasChanges: true,
    }));
  };

  const setHasChanges = (value: boolean) => {
    setFormState((prev) => ({
      ...prev,
      version: settingsVersion,
      hasChanges: value,
    }));
  };

  // Merged form data: initial settings + user overrides
  const formData = useMemo<DeepPartial<QASettings>>(() => {
    // Compute formOverrides based on version match
    const formOverrides = formState.version === settingsVersion ? formState.overrides : {};

    return {
      ...initialFormData,
      ...formOverrides,
      models: {
        ...initialFormData.models,
        ...formOverrides.models,
        realtime: { ...initialFormData.models?.realtime, ...formOverrides.models?.realtime },
        analysis: { ...initialFormData.models?.analysis, ...formOverrides.models?.analysis },
        report: { ...initialFormData.models?.report, ...formOverrides.models?.report },
      },
      settings: {
        ...initialFormData.settings,
        ...formOverrides.settings,
      },
      notifications: {
        ...initialFormData.notifications,
        ...formOverrides.notifications,
      },
    };
  }, [initialFormData, formState, settingsVersion]);

  // Calculate estimated monthly cost based on sampling rate
  const estimatedMonthlyCost = useMemo(() => {
    const samplingRate = formData.settings?.sampling_rate ?? 100;
    return Math.round(BASE_MONTHLY_COST * (samplingRate / 100));
  }, [formData.settings?.sampling_rate]);

  // Threshold warnings
  const thresholdWarning = useMemo(() => {
    const threshold = formData.settings?.score_threshold ?? 0.70;
    if (threshold < 0.50) {
      return 'เกณฑ์ต่ำมากอาจทำให้เกิดการแจ้งเตือนผิดพลาดจำนวนมาก';
    }
    if (threshold > 0.85) {
      return 'เกณฑ์สูงมากอาจพลาดปัญหาที่แท้จริง';
    }
    return null;
  }, [formData.settings?.score_threshold]);

  // Sampling rate warning
  const samplingWarning = useMemo(() => {
    const rate = formData.settings?.sampling_rate ?? 100;
    if (rate < 25) {
      return 'อัตราสุ่มต่ำอาจพลาดรูปแบบปัญหา ควรเพิ่มอัตราการสุ่ม';
    }
    return null;
  }, [formData.settings?.sampling_rate]);

  const handleModelChange = (
    layer: 'realtime' | 'analysis' | 'report',
    field: 'primary' | 'fallback',
    value: string
  ) => {
    setFormOverrides((prev) => ({
      ...prev,
      models: {
        ...prev.models,
        [layer]: {
          ...prev.models?.[layer],
          [field]: value,
        },
      },
    }));
    setHasChanges(true);
  };

  const handleSettingsChange = (
    field: 'score_threshold' | 'sampling_rate' | 'report_schedule',
    value: number | string
  ) => {
    setFormOverrides((prev) => ({
      ...prev,
      settings: {
        ...prev.settings,
        [field]: value,
      },
    }));
    setHasChanges(true);
  };

  const handleNotificationChange = (
    field: 'email' | 'alert' | 'slack',
    value: boolean
  ) => {
    setFormOverrides((prev) => ({
      ...prev,
      notifications: {
        ...prev.notifications,
        [field]: value,
      },
    }));
    setHasChanges(true);
  };

  const handleSave = () => {
    // Convert to API format
    const updateData = {
      qa_realtime_model: formData.models?.realtime?.primary || undefined,
      qa_realtime_fallback_model: formData.models?.realtime?.fallback || undefined,
      qa_analysis_model: formData.models?.analysis?.primary || undefined,
      qa_analysis_fallback_model: formData.models?.analysis?.fallback || undefined,
      qa_report_model: formData.models?.report?.primary || undefined,
      qa_report_fallback_model: formData.models?.report?.fallback || undefined,
      qa_score_threshold: formData.settings?.score_threshold,
      qa_sampling_rate: formData.settings?.sampling_rate,
      qa_report_schedule: formData.settings?.report_schedule,
      qa_notifications: formData.notifications,
    };

    updateMutation.mutate(updateData, {
      onSuccess: () => setHasChanges(false),
    });
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center p-8">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (isError) {
    return (
      <div className="rounded-md bg-destructive/10 p-4 text-sm text-destructive flex items-center gap-2">
        <AlertCircle className="h-4 w-4" />
        <span>ไม่สามารถโหลดการตั้งค่า QA Inspector ได้</span>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Enable/Disable Toggle */}
      <Card>
        <CardHeader>
          <CardTitle>ตรวจสอบคุณภาพ</CardTitle>
          <CardDescription>
            ระบบ AI ตรวจสอบคุณภาพการตอบของบอทอัตโนมัติ
          </CardDescription>
        </CardHeader>
        <CardContent>
          <QAInspectorToggle botId={botId} />
        </CardContent>
      </Card>

      {/* Model Configuration */}
      {settings?.qa_inspector_enabled && (
        <Card>
          <CardHeader>
            <CardTitle>ตั้งค่าโมเดล AI</CardTitle>
            <CardDescription>
              กำหนดโมเดล AI สำหรับแต่ละขั้นตอนการประเมิน ใช้รูปแบบ: provider/model-name
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            <ModelLayerConfig
              layerName="ประเมินแบบเรียลไทม์"
              layerDescription="ประเมินทุกการตอบของบอทอย่างรวดเร็ว (Layer 1)"
              primaryModel={formData.models?.realtime?.primary ?? ''}
              fallbackModel={formData.models?.realtime?.fallback ?? ''}
              primaryPlaceholder="google/gemini-2.5-flash-preview"
              fallbackPlaceholder="openai/gpt-4o-mini"
              onChange={(field, value) => handleModelChange('realtime', field, value)}
              disabled={updateMutation.isPending}
            />

            <ModelLayerConfig
              layerName="วิเคราะห์เชิงลึก"
              layerDescription="วิเคราะห์รายละเอียดสำหรับปัญหาที่พบ (Layer 2)"
              primaryModel={formData.models?.analysis?.primary ?? ''}
              fallbackModel={formData.models?.analysis?.fallback ?? ''}
              primaryPlaceholder="anthropic/claude-sonnet-4"
              fallbackPlaceholder="openai/gpt-4o"
              onChange={(field, value) => handleModelChange('analysis', field, value)}
              disabled={updateMutation.isPending}
            />

            <ModelLayerConfig
              layerName="รายงานประจำสัปดาห์"
              layerDescription="สร้างรายงานสรุปรายสัปดาห์แบบครบถ้วน (Layer 3)"
              primaryModel={formData.models?.report?.primary ?? ''}
              fallbackModel={formData.models?.report?.fallback ?? ''}
              primaryPlaceholder="anthropic/claude-opus-4-5"
              fallbackPlaceholder="anthropic/claude-sonnet-4"
              onChange={(field, value) => handleModelChange('report', field, value)}
              disabled={updateMutation.isPending}
            />
          </CardContent>
        </Card>
      )}

      {/* Additional Settings */}
      {settings?.qa_inspector_enabled && (
        <Card>
          <CardHeader>
            <CardTitle>ตั้งค่าการประเมิน</CardTitle>
            <CardDescription>
              กำหนดเกณฑ์คะแนน อัตราการสุ่มตรวจ และกำหนดการสร้างรายงาน
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            {/* Score Threshold */}
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <Label htmlFor="score-threshold" className="font-semibold">
                  เกณฑ์คะแนน
                </Label>
                <span className="text-sm font-medium tabular-nums">
                  {(formData.settings?.score_threshold ?? 0.70).toFixed(2)}
                </span>
              </div>
              <Slider
                id="score-threshold"
                min={0.50}
                max={0.95}
                step={0.01}
                value={[formData.settings?.score_threshold ?? 0.70]}
                onValueChange={(value) => handleSettingsChange('score_threshold', value[0])}
                disabled={updateMutation.isPending}
              />
              <p className="text-xs text-muted-foreground">
                การสนทนาที่คะแนนต่ำกว่าเกณฑ์นี้จะถูกแจ้งเตือน
              </p>
              {thresholdWarning && (
                <div className="flex items-center gap-2 text-sm text-yellow-600 dark:text-yellow-500 bg-yellow-50 dark:bg-yellow-950/50 rounded-md p-2">
                  <AlertTriangle className="h-4 w-4 shrink-0" />
                  <span>{thresholdWarning}</span>
                </div>
              )}
            </div>

            {/* Sampling Rate */}
            <div className="space-y-3">
              <div className="flex items-center justify-between">
                <Label htmlFor="sampling-rate" className="font-semibold">
                  อัตราการสุ่มตรวจ
                </Label>
                <span className="text-sm font-medium tabular-nums">
                  {formData.settings?.sampling_rate ?? 100}%
                </span>
              </div>
              <Slider
                id="sampling-rate"
                min={1}
                max={100}
                step={1}
                value={[formData.settings?.sampling_rate ?? 100]}
                onValueChange={(value) => handleSettingsChange('sampling_rate', value[0])}
                disabled={updateMutation.isPending}
              />
              <p className="text-xs text-muted-foreground">
                เปอร์เซ็นต์การสนทนาที่จะประเมิน (ลดค่าใช้จ่าย)
              </p>
              {samplingWarning && (
                <div className="flex items-center gap-2 text-sm text-yellow-600 dark:text-yellow-500 bg-yellow-50 dark:bg-yellow-950/50 rounded-md p-2">
                  <AlertTriangle className="h-4 w-4 shrink-0" />
                  <span>{samplingWarning}</span>
                </div>
              )}
            </div>

            {/* Report Schedule */}
            <div className="space-y-2">
              <Label className="font-semibold">กำหนดการสร้างรายงาน</Label>
              <Select
                value={formData.settings?.report_schedule ?? 'monday_00:00'}
                onValueChange={(value) => handleSettingsChange('report_schedule', value)}
                disabled={updateMutation.isPending}
              >
                <SelectTrigger className="w-full sm:w-48">
                  <SelectValue placeholder="เลือกกำหนดการ" />
                </SelectTrigger>
                <SelectContent>
                  {REPORT_SCHEDULES.map((schedule) => (
                    <SelectItem key={schedule.value} value={schedule.value}>
                      {schedule.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <p className="text-xs text-muted-foreground">
                เวลาที่จะสร้างรายงาน QA ประจำสัปดาห์
              </p>
            </div>

            {/* Cost Estimate */}
            <div className="bg-muted/50 rounded-lg p-4">
              <div className="flex items-center gap-2 mb-2">
                <DollarSign className="h-4 w-4 text-muted-foreground" />
                <span className="font-medium">ประมาณการค่าใช้จ่ายรายเดือน</span>
              </div>
              <p className="text-2xl font-bold">~${estimatedMonthlyCost}/เดือน</p>
              <p className="text-sm text-muted-foreground">
                จากการสนทนา 200 ครั้ง/วัน ที่อัตราสุ่ม {formData.settings?.sampling_rate ?? 100}%
              </p>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Notification Settings */}
      {settings?.qa_inspector_enabled && (
        <Card>
          <CardHeader>
            <CardTitle>การแจ้งเตือน</CardTitle>
            <CardDescription>
              ตั้งค่าวิธีการแจ้งเตือนเมื่อพบปัญหา QA
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            {/* Email Notifications */}
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <Mail className="h-5 w-5 text-muted-foreground" />
                <div>
                  <Label htmlFor="notify-email" className="font-semibold">
                    แจ้งเตือนทางอีเมล
                  </Label>
                  <p className="text-xs text-muted-foreground mt-0.5">
                    รับสรุปรายงานประจำสัปดาห์ทางอีเมล
                  </p>
                </div>
              </div>
              <Switch
                id="notify-email"
                checked={formData.notifications?.email ?? true}
                onCheckedChange={(checked) => handleNotificationChange('email', checked)}
                disabled={updateMutation.isPending}
              />
            </div>

            {/* In-app Alerts */}
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <Bell className="h-5 w-5 text-muted-foreground" />
                <div>
                  <Label htmlFor="notify-alert" className="font-semibold">
                    แจ้งเตือนในแอป
                  </Label>
                  <p className="text-xs text-muted-foreground mt-0.5">
                    รับการแจ้งเตือนปัญหาสำคัญในแดชบอร์ด
                  </p>
                </div>
              </div>
              <Switch
                id="notify-alert"
                checked={formData.notifications?.alert ?? false}
                onCheckedChange={(checked) => handleNotificationChange('alert', checked)}
                disabled={updateMutation.isPending}
              />
            </div>

            {/* Slack Integration */}
            <div className="flex items-center justify-between opacity-60">
              <div className="flex items-center gap-3">
                <Slack className="h-5 w-5 text-muted-foreground" />
                <div>
                  <Label htmlFor="notify-slack" className="font-semibold">
                    เชื่อมต่อ Slack
                  </Label>
                  <p className="text-xs text-muted-foreground mt-0.5">
                    เร็วๆ นี้ - รับการแจ้งเตือนใน Slack channel
                  </p>
                </div>
              </div>
              <Switch
                id="notify-slack"
                checked={false}
                disabled={true}
              />
            </div>
          </CardContent>
        </Card>
      )}

      {/* Save Button - Sticky at bottom when there are changes */}
      {settings?.qa_inspector_enabled && hasChanges && (
        <div className="sticky bottom-4 flex justify-end">
          <Button
            onClick={handleSave}
            disabled={updateMutation.isPending}
            className="shadow-lg"
          >
            {updateMutation.isPending ? (
              <>
                <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                กำลังบันทึก...
              </>
            ) : (
              <>
                <Save className="h-4 w-4 mr-2" />
                บันทึกการเปลี่ยนแปลง
              </>
            )}
          </Button>
        </div>
      )}

      {/* Error Message */}
      {updateMutation.isError && (
        <div className="flex items-center gap-2 text-sm text-destructive bg-destructive/10 rounded-md p-3">
          <AlertCircle className="h-4 w-4" />
          <span>
            {(updateMutation.error as { message?: string })?.message ||
              'ไม่สามารถบันทึกการตั้งค่าได้'}
          </span>
        </div>
      )}
    </div>
  );
}

