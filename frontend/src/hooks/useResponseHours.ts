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
