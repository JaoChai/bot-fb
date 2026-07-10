// helper สำหรับหน้ารายการสลิป: label/สี badge/กลุ่ม status + ช่วงวัน Bangkok

type BadgeVariant = 'success' | 'destructive' | 'warning' | 'secondary';

const STATUS_META: Record<string, { label: string; variant: BadgeVariant }> = {
  passed: { label: 'ผ่าน', variant: 'success' },
  fake: { label: 'ปลอม', variant: 'destructive' },
  wrong_account: { label: 'บัญชีผิด', variant: 'destructive' },
  duplicate: { label: 'สลิปซ้ำ', variant: 'warning' },
  amount_mismatch: { label: 'ยอดไม่ตรง', variant: 'warning' },
  no_pending_order: { label: 'ไม่มีออเดอร์ค้าง', variant: 'warning' },
  unreadable: { label: 'อ่านสลิปไม่ได้', variant: 'secondary' },
  api_error: { label: 'API ผิดพลาด', variant: 'secondary' },
  config_error: { label: 'ตั้งค่าไม่ครบ', variant: 'secondary' },
  image_download_failed: { label: 'โหลดรูปไม่ได้', variant: 'secondary' },
  pending: { label: 'รอธนาคารยืนยัน', variant: 'secondary' },
};

export function slipStatusMeta(status: string): { label: string; variant: BadgeVariant } {
  return STATUS_META[status] ?? { label: status, variant: 'secondary' };
}

// กลุ่ม filter (ค่าที่ส่งเป็น csv ให้ backend)
export const STATUS_GROUPS: Record<string, string[]> = {
  all: [],
  passed: ['passed'],
  abnormal: ['fake', 'wrong_account', 'duplicate', 'amount_mismatch', 'no_pending_order'],
  error: ['unreadable', 'api_error', 'config_error', 'image_download_failed', 'pending'],
};

export const STATUS_GROUP_LABELS: Record<string, string> = {
  all: 'ทั้งหมด',
  passed: 'ผ่าน',
  abnormal: 'ผิดปกติ',
  error: 'error ระบบ',
};

// ช่วง "วันนี้" ตามเวลาไทย (Bangkok = UTC+7, ไม่มี DST) คืนเป็น UTC ISO
export function bangkokTodayRange(): { date_from: string; date_to: string } {
  const OFFSET_MS = 7 * 60 * 60 * 1000;
  const nowBkk = new Date(Date.now() + OFFSET_MS);
  const y = nowBkk.getUTCFullYear();
  const m = nowBkk.getUTCMonth();
  const d = nowBkk.getUTCDate();
  const startUtc = new Date(Date.UTC(y, m, d, 0, 0, 0) - OFFSET_MS);
  const endUtc = new Date(Date.UTC(y, m, d, 23, 59, 59) - OFFSET_MS);
  return { date_from: startUtc.toISOString(), date_to: endUtc.toISOString() };
}
