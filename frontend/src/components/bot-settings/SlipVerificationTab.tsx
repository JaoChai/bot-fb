import { Receipt, ShieldCheck } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { Panel } from '@/components/common';
import { SettingRow } from '@/components/connections';

interface SlipVerificationTabProps {
  slip_verification_enabled: boolean;
  slip_receiver_account: string;
  slip_amount_tolerance: number;
  slip_success_message: string;
  slip_fail_message: string;
  onChange: (field: string, value: unknown) => void;
}

export function SlipVerificationTab({
  slip_verification_enabled,
  slip_receiver_account,
  slip_amount_tolerance,
  slip_success_message,
  slip_fail_message,
  onChange,
}: SlipVerificationTabProps) {
  return (
    <div className="space-y-6">
      <Panel
        icon={Receipt}
        title="ตรวจสลิปอัตโนมัติ (EasySlip)"
        description="ตรวจสลิปโอนเงินกับธนาคารจริงก่อนบอทยืนยันรับเงิน — ต้องตั้งค่า EasySlip API Token ในหน้า Settings ก่อน"
      >
        <div className="px-5 py-4 space-y-5">
          <SettingRow label="เปิดตรวจสลิปอัตโนมัติ" htmlFor="slip-toggle">
            <Switch
              id="slip-toggle"
              checked={slip_verification_enabled}
              onCheckedChange={(checked) => onChange('slip_verification_enabled', checked)}
            />
          </SettingRow>

          {slip_verification_enabled && (
            <div className="ml-4 pl-4 border-l-2 border-muted space-y-5 mt-2">
              <SettingRow
                label="เลขบัญชีร้าน (รับเงินเข้า)"
                htmlFor="slip-account"
                description="สลิปต้องโอนเข้าบัญชีนี้เท่านั้นถึงจะผ่าน เช่น 223-3-24880-3"
                orientation="vertical"
              >
                <Input
                  id="slip-account"
                  placeholder="223-3-24880-3"
                  value={slip_receiver_account}
                  onChange={(e) => onChange('slip_receiver_account', e.target.value)}
                />
              </SettingRow>

              <SettingRow
                label="ยอดคลาดเคลื่อนได้ไม่เกิน (บาท)"
                htmlFor="slip-tolerance"
                description="0 = ยอดต้องตรงกับออเดอร์เป๊ะ"
                orientation="vertical"
              >
                <Input
                  id="slip-tolerance"
                  type="number"
                  min={0}
                  max={10000}
                  value={slip_amount_tolerance}
                  onChange={(e) => onChange('slip_amount_tolerance', Number(e.target.value))}
                  className="max-w-[160px]"
                />
              </SettingRow>

              <SettingRow
                label="ข้อความเมื่อตรวจผ่าน"
                htmlFor="slip-success"
                description="ใช้ {amount} = ยอดเงิน, {order_summary} = รายการสินค้า — เว้นว่าง = ใช้ข้อความเริ่มต้น (ต้องมี [ยืนยันชำระเงิน] เพื่อให้ระบบบันทึกออเดอร์)"
                orientation="vertical"
              >
                <Textarea
                  id="slip-success"
                  placeholder={'เงินเข้าแล้ว {amount} บาท ✅\nออเดอร์: {order_summary}\nส่งใน 5-10 นาที ขอบคุณครับ\n[ยืนยันชำระเงิน]'}
                  value={slip_success_message}
                  onChange={(e) => onChange('slip_success_message', e.target.value)}
                  rows={4}
                />
              </SettingRow>

              <SettingRow
                label="ข้อความเมื่อตรวจไม่ผ่าน"
                htmlFor="slip-fail"
                description="บอทจะไม่ยืนยันออเดอร์ และแจ้งเตือนแอดมินทาง Telegram อัตโนมัติ"
                orientation="vertical"
              >
                <Textarea
                  id="slip-fail"
                  placeholder="ได้รับสลิปแล้วครับ ขอตรวจสอบยอดสักครู่ เดี๋ยวแอดมินยืนยันให้อีกครั้งนะครับ 🙏"
                  value={slip_fail_message}
                  onChange={(e) => onChange('slip_fail_message', e.target.value)}
                  rows={3}
                />
              </SettingRow>

              <div className="flex items-start gap-2 rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground">
                <ShieldCheck className="size-4 shrink-0 mt-0.5" strokeWidth={1.5} />
                <p>
                  ระบบตรวจ 4 อย่าง: สลิปจริง (เช็คกับธนาคาร) · โอนเข้าบัญชีร้าน · ยอดตรงกับออเดอร์ ·
                  ไม่ใช่สลิปซ้ำ — รูปที่ไม่ใช่สลิปจะตอบด้วย AI ตามปกติ
                </p>
              </div>
            </div>
          )}
        </div>
      </Panel>
    </div>
  );
}
