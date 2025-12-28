import { useState } from 'react';
import { useNavigate } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import { Slider } from '@/components/ui/slider';
import { useToast } from '@/hooks/use-toast';
import { ArrowLeft, Loader2, Clock } from 'lucide-react';

interface BotSettingsFormData {
  daily_message_limit: number;
  message_limit_per_user: number;
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
}

export function BotSettingsPage() {
  const navigate = useNavigate();
  const { toast } = useToast();

  const [isSaving, setIsSaving] = useState(false);
  const [formData, setFormData] = useState<BotSettingsFormData>({
    daily_message_limit: 100,
    message_limit_per_user: 10,
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
    wait_multiple_bubbles_seconds: 10,
    reply_sticker_enabled: false,
    response_hours_enabled: false,
  });

  const handleChange = <K extends keyof BotSettingsFormData>(
    field: K,
    value: BotSettingsFormData[K]
  ) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
  };

  const handleSave = async () => {
    setIsSaving(true);
    try {
      // TODO: Call API to save bot settings
      console.log('Saving bot settings:', formData);
      toast({
        title: 'บันทึกสำเร็จ',
        description: 'ตั้งค่าบอทได้รับการบันทึกแล้ว',
      });
    } catch (error) {
      toast({
        title: 'ข้อผิดพลาด',
        description: 'ไม่สามารถบันทึกตั้งค่าบอทได้',
        variant: 'destructive',
      });
    } finally {
      setIsSaving(false);
    }
  };

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
            <h1 className="text-3xl font-bold tracking-tight">ตั้งค่าบอท</h1>
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
                  จำนวนข้อความต่อคนต่อวัน: {formData.message_limit_per_user}
                </Label>
                <Slider
                  id="user-limit"
                  min={1}
                  max={100}
                  step={1}
                  value={[formData.message_limit_per_user]}
                  onValueChange={(value) => handleChange('message_limit_per_user', value[0])}
                />
              </div>
            </CardContent>
          </Card>

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
              <CardTitle className="text-lg flex items-center gap-2">
                การตอบแบบหลายบอลลูน
                <Badge variant="outline" className="text-xs">Agentic mode only</Badge>
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
                    เวลารอ: {formData.wait_multiple_bubbles_seconds} วินาที
                  </Label>
                  <Slider
                    id="wait-seconds"
                    min={5}
                    max={20}
                    step={1}
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
            <CardContent>
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
                <p className="text-sm text-muted-foreground mt-4 p-4 bg-muted rounded-lg">
                  💡 Response Hours UI จะถูกเพิ่มในเวอร์ชันถัดไป
                </p>
              )}
            </CardContent>
          </Card>

          {/* Save Button */}
          <div className="flex gap-3 pt-4">
            <Button
              onClick={handleSave}
              disabled={isSaving}
              variant="cta"
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
