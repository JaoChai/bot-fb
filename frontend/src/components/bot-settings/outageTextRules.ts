// Outage text rules shared by OutageSettingsTab (UI) and BotSettingsPage
// (save gating). Kept out of the component file so react-refresh stays happy.
export const OUTAGE_MAX_CODE_POINTS = 500;

export const OVER_LIMIT_MESSAGE = 'ข้อความยาวเกิน 500 ตัวอักษร';

/** Counter (and limit) counts Unicode code points, so 500 emoji are 500. */
export function countCodePoints(value: string): number {
  return Array.from(value).length;
}

export function isOverOutageLimit(value: string): boolean {
  return countCodePoints(value) > OUTAGE_MAX_CODE_POINTS;
}
