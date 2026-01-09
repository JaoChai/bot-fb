/**
 * Shared types for BotSettings components
 * Part of 006-bots-refactor feature
 */

// Response Hours types
export interface TimeSlot {
  start: string;
  end: string;
}

export interface DaySchedule {
  enabled: boolean;
  slots: TimeSlot[];
}

export type DayKey = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';

export interface ResponseHoursConfig {
  mon: DaySchedule;
  tue: DaySchedule;
  wed: DaySchedule;
  thu: DaySchedule;
  fri: DaySchedule;
  sat: DaySchedule;
  sun: DaySchedule;
}

// Constants
export const DAYS: { key: DayKey; label: string }[] = [
  { key: 'mon', label: 'จันทร์' },
  { key: 'tue', label: 'อังคาร' },
  { key: 'wed', label: 'พุธ' },
  { key: 'thu', label: 'พฤหัสบดี' },
  { key: 'fri', label: 'ศุกร์' },
  { key: 'sat', label: 'เสาร์' },
  { key: 'sun', label: 'อาทิตย์' },
];

export const TIMEZONES = [
  { value: 'Asia/Bangkok', label: 'Asia/Bangkok (GMT+7)' },
  { value: 'Asia/Singapore', label: 'Asia/Singapore (GMT+8)' },
  { value: 'Asia/Tokyo', label: 'Asia/Tokyo (GMT+9)' },
  { value: 'Asia/Hong_Kong', label: 'Asia/Hong Kong (GMT+8)' },
  { value: 'Asia/Shanghai', label: 'Asia/Shanghai (GMT+8)' },
  { value: 'UTC', label: 'UTC (GMT+0)' },
];

export const createDefaultResponseHours = (): ResponseHoursConfig => ({
  mon: { enabled: true, slots: [{ start: '09:00', end: '18:00' }] },
  tue: { enabled: true, slots: [{ start: '09:00', end: '18:00' }] },
  wed: { enabled: true, slots: [{ start: '09:00', end: '18:00' }] },
  thu: { enabled: true, slots: [{ start: '09:00', end: '18:00' }] },
  fri: { enabled: true, slots: [{ start: '09:00', end: '18:00' }] },
  sat: { enabled: false, slots: [{ start: '10:00', end: '14:00' }] },
  sun: { enabled: false, slots: [{ start: '10:00', end: '14:00' }] },
});

export interface BotSettingsFormData {
  // Core Settings
  welcome_message: string | null;
  fallback_message: string | null;
  typing_indicator: boolean;
  typing_delay_ms: number;
  language: string;
  response_style: string | null;
  auto_archive_days: number | null;
  save_conversations: boolean;

  // Rate Limits
  daily_message_limit: number | null;
  per_user_limit: number | null;
  rate_limit_per_minute: number;
  max_tokens_per_response: number | null;
  rate_limit_bot_message: string | null;
  rate_limit_user_message: string | null;

  // HITL Settings
  hitl_enabled: boolean;
  hitl_triggers: string[];
  lead_recovery_enabled: boolean;
  reply_when_called_enabled: boolean;
  easy_slip_enabled: boolean;

  // Aggregation Settings
  multiple_bubbles_enabled: boolean;
  multiple_bubbles_min: number;
  multiple_bubbles_max: number;
  multiple_bubbles_delimiter: string;
  wait_multiple_bubbles_enabled: boolean;
  wait_multiple_bubbles_ms: number;
  smart_aggregation_enabled: boolean;
  smart_min_wait_ms: number;
  smart_max_wait_ms: number;
  smart_early_trigger_enabled: boolean;
  smart_per_user_learning_enabled: boolean;

  // Response Hours
  response_hours_enabled: boolean;
  response_hours: ResponseHoursConfig;
  response_hours_timezone: string;
  offline_message: string | null;
  reply_sticker_enabled: boolean;
  reply_sticker_message: string | null;
}

export interface SectionProps {
  formData: BotSettingsFormData;
  onChange: <K extends keyof BotSettingsFormData>(
    key: K,
    value: BotSettingsFormData[K]
  ) => void;
  disabled?: boolean;
}

export interface BotLimits {
  id: number;
  bot_setting_id: number;
  daily_message_limit: number | null;
  per_user_limit: number | null;
  rate_limit_per_minute: number;
  max_tokens_per_response: number | null;
  rate_limit_bot_message: string | null;
  rate_limit_user_message: string | null;
}

export interface BotHITLSettings {
  id: number;
  bot_setting_id: number;
  hitl_enabled: boolean;
  hitl_triggers: string[];
  lead_recovery_enabled: boolean;
  reply_when_called_enabled: boolean;
  easy_slip_enabled: boolean;
}

export interface BotAggregationSettings {
  id: number;
  bot_setting_id: number;
  multiple_bubbles_enabled: boolean;
  multiple_bubbles_min: number;
  multiple_bubbles_max: number;
  multiple_bubbles_delimiter: string;
  wait_multiple_bubbles_enabled: boolean;
  wait_multiple_bubbles_ms: number;
  smart_aggregation_enabled: boolean;
  smart_min_wait_ms: number;
  smart_max_wait_ms: number;
  smart_early_trigger_enabled: boolean;
  smart_per_user_learning_enabled: boolean;
}

export interface BotResponseHours {
  id: number;
  bot_setting_id: number;
  response_hours_enabled: boolean;
  response_hours: Record<string, unknown> | null;
  response_hours_timezone: string;
  offline_message: string | null;
  reply_sticker_enabled: boolean;
  reply_sticker_message: string | null;
}

// Extended props for ResponseHoursSection with schedule handlers
export interface ResponseHoursSectionProps extends SectionProps {
  onDayToggle: (day: DayKey, enabled: boolean) => void;
  onSlotChange: (day: DayKey, slotIndex: number, field: 'start' | 'end', value: string) => void;
  onAddSlot: (day: DayKey) => void;
  onRemoveSlot: (day: DayKey, slotIndex: number) => void;
  onApplyToAllDays: () => void;
}

// Helper functions for Response Hours parsing/serialization
export const parseResponseHours = (
  apiData: Record<string, TimeSlot[]> | null
): ResponseHoursConfig => {
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

export const serializeResponseHours = (
  uiData: ResponseHoursConfig
): Record<string, TimeSlot[]> => {
  const result: Record<string, TimeSlot[]> = {};
  for (const day of DAYS) {
    if (uiData[day.key].enabled && uiData[day.key].slots.length > 0) {
      result[day.key] = uiData[day.key].slots;
    }
  }
  return result;
};
