import { useState } from 'react';
import { useParams, useNavigate } from 'react-router';
import { useToast } from '@/hooks/use-toast';

// UI Components
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

// Hooks
import { useBots } from '@/hooks/useKnowledgeBase';

// Icons
import { ArrowLeft, Save, Loader2, Clock, MessageSquare, Bot, Zap, Sticker, Info } from 'lucide-react';

// ResponseHoursGrid Component
function ResponseHoursGrid({
  value,
  onChange: _onChange,
}: {
  value: Record<string, { start: number; end: number }[]>;
  onChange: (value: Record<string, { start: number; end: number }[]>) => void;
}) {
  const days = [
    { key: 'sunday', label: 'อาทิตย์' },
    { key: 'monday', label: 'จันทร์' },
    { key: 'tuesday', label: 'อังคาร' },
    { key: 'wednesday', label: 'พุธ' },
    { key: 'thursday', label: 'พฤหัสบดี' },
    { key: 'friday', label: 'ศุกร์' },
    { key: 'saturday', label: 'เสาร์' },
  ];

  const getBarStyle = (dayKey: string) => {
    const daySchedule = value[dayKey] || [{ start: 7, end: 23 }];
    const firstSlot = daySchedule[0] || { start: 7, end: 23 };
    const startPercent = (firstSlot.start / 24) * 100;
    const widthPercent = ((firstSlot.end - firstSlot.start) / 24) * 100;
    return {
      left: `${startPercent}%`,
      width: `${widthPercent}%`,
    };
  };

  return (
    <div className="space-y-2">
      {/* Time labels */}
      <div className="flex items-center ml-20">
        <div className="flex-1 flex justify-between text-xs text-muted-foreground">
          <span>00:00</span>
          <span>06:00</span>
          <span>12:00</span>
          <span>18:00</span>
          <span>24:00</span>
        </div>
      </div>

      {/* Days grid */}
      <div className="space-y-1">
        {days.map((day) => (
          <div key={day.key} className="flex items-center gap-2">
            <span className="w-16 text-sm text-muted-foreground text-right">{day.label}</span>
            <div className="flex-1 h-6 bg-gray-100 dark:bg-gray-800 rounded relative">
              <div
                className="absolute h-full bg-amber-500 rounded transition-all"
                style={getBarStyle(day.key)}
              />
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

interface BotSettingsState {
  // Usage limits
  enableDailyLimit: boolean;
  dailyMessageLimit: number;

  // HITL
  enableHITL: boolean;

  // Bot invocation
  respondOnlyWhenCalled: boolean;

  // Lead Recovery
  enableLeadRecovery: boolean;

  // Multi-balloon
  enableMultiBalloon: boolean;
  minBalloons: number;
  maxBalloons: number;

  // Wait for reading
  enableWaitReading: boolean;
  waitSeconds: number;

  // Sticker response
  enableStickerResponse: boolean;

  // Response hours
  enableResponseHours: boolean;
  timezone: string;
  responseHours: Record<string, { start: number; end: number }[]>;
}

export function BotSettingsPage() {
  const { botId } = useParams();
  const navigate = useNavigate();
  const { toast } = useToast();
  const numericBotId = botId ? parseInt(botId, 10) : null;

  const { data: botsResponse, isLoading: isLoadingBots } = useBots();
  const [isSaving, setIsSaving] = useState(false);

  const currentBot = botsResponse?.data?.find(b => b.id === numericBotId);

  const [settings, setSettings] = useState<BotSettingsState>({
    enableDailyLimit: true,
    dailyMessageLimit: 25,
    enableHITL: false,
    respondOnlyWhenCalled: false,
    enableLeadRecovery: false,
    enableMultiBalloon: true,
    minBalloons: 1,
    maxBalloons: 3,
    enableWaitReading: true,
    waitSeconds: 20,
    enableStickerResponse: true,
    enableResponseHours: true,
    timezone: 'Asia/Bangkok',
    responseHours: {
      sunday: [{ start: 7, end: 23 }],
      monday: [{ start: 7, end: 23 }],
      tuesday: [{ start: 7, end: 23 }],
      wednesday: [{ start: 7, end: 23 }],
      thursday: [{ start: 7, end: 23 }],
      friday: [{ start: 7, end: 23 }],
      saturday: [{ start: 7, end: 23 }],
    },
  });

  const updateSetting = <K extends keyof BotSettingsState>(key: K, value: BotSettingsState[K]) => {
    setSettings(prev => ({ ...prev, [key]: value }));
  };

  const handleSave = async () => {
    setIsSaving(true);
    try {
      // TODO: Implement actual save API call
      await new Promise(resolve => setTimeout(resolve, 1000));
      toast({
        title: 'บันทึกสำเร็จ',
        description: 'การตั้งค่าถูกบันทึกเรียบร้อยแล้ว',
      });
    } catch {
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: 'ไม่สามารถบันทึกการตั้งค่าได้',
        variant: 'destructive',
      });
    } finally {
      setIsSaving(false);
    }
  };

  if (!numericBotId || isNaN(numericBotId)) {
    return (
      <div className="flex items-center justify-center h-64">
        <p className="text-muted-foreground">Bot ID ไม่ถูกต้อง</p>
      </div>
    );
  }

  if (isLoadingBots) {
    return (
      <div className="flex items-center justify-center h-64">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    <div className="space-y-6 max-w-4xl mx-auto">
      {/* Header - dabby.io style */}
      <div className="space-y-4">
        <button
          onClick={() => navigate('/bots')}
          className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground transition-colors"
        >
          <ArrowLeft className="h-4 w-4" />
          กลับไปหน้าการเชื่อมต่อ
        </button>

        <h1 className="text-2xl font-bold tracking-tight">
          ตั้งค่าการเชื่อมต่อ: {currentBot?.name || 'Bot'}
        </h1>
      </div>

      {/* Settings Sections */}
      <div className="space-y-6">
        {/* 1. Usage Limits - การจำกัดการใช้งาน */}
        <Card className="bg-white dark:bg-card">
          <CardHeader className="pb-4">
            <CardTitle className="text-lg flex items-center gap-2">
              <MessageSquare className="h-5 w-5" />
              การจำกัดการใช้งาน
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center gap-3">
              <Switch
                checked={settings.enableDailyLimit}
                onCheckedChange={(checked) => updateSetting('enableDailyLimit', checked)}
                className="data-[state=checked]:bg-green-500"
              />
              <Label>จำกัดจำนวนข้อความ ต่อวัน+ต่อคน</Label>
            </div>

            {settings.enableDailyLimit && (
              <div className="ml-10 space-y-2">
                <Label>จำนวนข้อความต่อวัน+ต่อคน</Label>
                <div className="flex items-center gap-2">
                  <Input
                    type="number"
                    value={settings.dailyMessageLimit}
                    onChange={(e) => updateSetting('dailyMessageLimit', parseInt(e.target.value) || 0)}
                    className="w-24"
                    min={1}
                  />
                  <span className="text-sm text-muted-foreground">ข้อความ</span>
                </div>
                <p className="text-xs text-muted-foreground">
                  บอทจะตอบข้อความได้ไม่เกินจำนวนที่กำหนด ต่อวัน+ต่อคน เท่านั้น มีประโยชน์เพื่อใช้ป้องกันบอทคุยกับคนเดิมมากเกินไป หรือป้องกัน spam หรือประหยัดค่า token ของบอท
                </p>
              </div>
            )}
          </CardContent>
        </Card>

        {/* 2. EasySlip - เช็คสลิป */}
        <Card className="bg-white dark:bg-card opacity-60">
          <CardHeader className="pb-4">
            <div className="flex items-center justify-between">
              <CardTitle className="text-lg">เช็คสลิป</CardTitle>
              <div className="flex items-center gap-2 text-amber-600 bg-amber-50 dark:bg-amber-950/30 px-3 py-1 rounded-full text-xs">
                <Info className="h-3 w-3" />
                EasySlip จะเปิดให้ใช้บริการเร็วๆ นี้
              </div>
            </div>
          </CardHeader>
          <CardContent>
            <div className="flex items-center gap-3">
              <Switch disabled className="opacity-50" />
              <Label className="text-muted-foreground">ตรวจสอบสลิปด้วย EasySlip API</Label>
            </div>
          </CardContent>
        </Card>

        {/* 3. HITL - การควบคุมบอท */}
        <Card className="bg-white dark:bg-card">
          <CardHeader className="pb-4">
            <CardTitle className="text-lg flex items-center gap-2">
              <Bot className="h-5 w-5" />
              การควบคุมบอท (HITL)
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="flex items-center gap-3">
              <Switch
                checked={settings.enableHITL}
                onCheckedChange={(checked) => updateSetting('enableHITL', checked)}
                className="data-[state=checked]:bg-green-500"
              />
              <Label>เปิดใช้งานการควบคุมบอท</Label>
            </div>
          </CardContent>
        </Card>

        {/* 4. Bot Invocation - การเรียกบอท */}
        <Card className="bg-white dark:bg-card">
          <CardHeader className="pb-4">
            <CardTitle className="text-lg">การเรียกบอท</CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            <div className="flex items-center gap-3">
              <Switch
                checked={settings.respondOnlyWhenCalled}
                onCheckedChange={(checked) => updateSetting('respondOnlyWhenCalled', checked)}
                className="data-[state=checked]:bg-green-500"
              />
              <Label>บอทจะตอบเมื่อถูกเรียกเท่านั้น</Label>
            </div>
            <p className="text-xs text-muted-foreground ml-10">
              เมื่อเปิดใช้งานการเรียกบอท การควบคุมบอทจะถูกปิดการใช้งาน เนื่องจากบอทจะตอบเฉพาะเมื่อถูกเรียกเท่านั้น
            </p>
          </CardContent>
        </Card>

        {/* 5. Lead Recovery */}
        <Card className="bg-white dark:bg-card">
          <CardHeader className="pb-4">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 bg-amber-100 dark:bg-amber-950/30 rounded-full flex items-center justify-center">
                  <Zap className="h-5 w-5 text-amber-600" />
                </div>
                <div>
                  <CardTitle className="text-lg">Lead Recovery</CardTitle>
                  <CardDescription>ติดตามลูกค้าอัตโนมัติเมื่อบทสนทนาเงียบ</CardDescription>
                </div>
              </div>
              <div className="flex items-center gap-3">
                <Switch
                  checked={settings.enableLeadRecovery}
                  onCheckedChange={(checked) => updateSetting('enableLeadRecovery', checked)}
                  className="data-[state=checked]:bg-green-500"
                />
                <Label className="text-sm">เปิดใช้งาน</Label>
              </div>
            </div>
          </CardHeader>
        </Card>

        {/* 6. Multi-balloon - การตอบแบบหลายบอลลูน */}
        <Card className="bg-white dark:bg-card">
          <CardHeader className="pb-4">
            <CardTitle className="text-lg">การตอบแบบหลายบอลลูน</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center gap-3">
              <Switch
                checked={settings.enableMultiBalloon}
                onCheckedChange={(checked) => updateSetting('enableMultiBalloon', checked)}
                className="data-[state=checked]:bg-green-500"
              />
              <Label>เปิดใช้งานการตอบแบบหลายบอลลูน</Label>
            </div>

            <div className="space-y-2">
              <p className="text-sm text-muted-foreground">
                เมื่อเปิดใช้งาน AI จะสามารถแยกคำตอบที่ยาวเป็นหลายๆ บอลลูนแยกกันเพื่อความอ่านง่าย
                <span className="ml-2 text-xs bg-amber-100 dark:bg-amber-950/30 text-amber-700 dark:text-amber-400 px-2 py-0.5 rounded">
                  Agentic mode only
                </span>
              </p>
              <p className="text-sm text-muted-foreground">
                เมื่อปิดใช้งาน AI จะตอบแบบบอลลูนเดียว
              </p>
            </div>

            {settings.enableMultiBalloon && (
              <div className="grid grid-cols-2 gap-4 mt-4">
                <div className="space-y-2">
                  <Label>จำนวนบอลลูนขั้นต่ำในการตอบ</Label>
                  <Input
                    type="number"
                    value={settings.minBalloons}
                    onChange={(e) => updateSetting('minBalloons', parseInt(e.target.value) || 1)}
                    min={1}
                    max={3}
                  />
                  <p className="text-xs text-muted-foreground">ค่าที่อนุญาต: 1-3 บอลลูน</p>
                </div>
                <div className="space-y-2">
                  <Label>จำนวนบอลลูนสูงสุดในการตอบ</Label>
                  <Input
                    type="number"
                    value={settings.maxBalloons}
                    onChange={(e) => updateSetting('maxBalloons', parseInt(e.target.value) || 3)}
                    min={2}
                    max={5}
                  />
                  <p className="text-xs text-muted-foreground">ค่าที่อนุญาต: 2-5 บอลลูน</p>
                </div>
              </div>
            )}

            <p className="text-xs text-muted-foreground">
              หมายเหตุ: จำนวนขั้นต่ำต้องไม่เกินจำนวนสูงสุด การตั้งค่านี้ใช้ได้เฉพาะในโหมด Agentic Mode เท่านั้น
            </p>
          </CardContent>
        </Card>

        {/* 7. Wait Reading - รออ่านหลายบอลลูน */}
        <Card className="bg-white dark:bg-card">
          <CardHeader className="pb-4">
            <CardTitle className="text-lg">รออ่านหลายบอลลูน</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center gap-3">
              <Switch
                checked={settings.enableWaitReading}
                onCheckedChange={(checked) => updateSetting('enableWaitReading', checked)}
                className="data-[state=checked]:bg-green-500"
              />
              <Label>เปิดใช้งานการรออ่านหลายบอลลูน</Label>
            </div>

            {settings.enableWaitReading && (
              <div className="space-y-2">
                <Label>เวลารอ (วินาที)</Label>
                <div className="flex items-center gap-2">
                  <Input
                    type="number"
                    value={settings.waitSeconds}
                    onChange={(e) => updateSetting('waitSeconds', parseInt(e.target.value) || 5)}
                    className="w-24"
                    min={5}
                    max={20}
                  />
                  <span className="text-sm text-muted-foreground">วินาที</span>
                </div>
                <div className="text-xs text-muted-foreground space-y-1">
                  <p>* ตั้งค่าได้ระหว่าง 5 วินาที ถึง 20 วินาที</p>
                  <p>** ระบบจะรออ่านข้อความจากลูกค้า ตามเวลาที่กำหนดก่อนที่จะเริ่มทำการคิดและตอบลูกค้าในครั้งเดียว</p>
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        {/* 8. Sticker Response - การตอบกลับ Sticker */}
        <Card className="bg-white dark:bg-card">
          <CardHeader className="pb-4">
            <CardTitle className="text-lg flex items-center gap-2">
              <Sticker className="h-5 w-5" />
              การตอบกลับ Sticker
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            <div className="flex items-center gap-3">
              <Switch
                checked={settings.enableStickerResponse}
                onCheckedChange={(checked) => updateSetting('enableStickerResponse', checked)}
                className="data-[state=checked]:bg-green-500"
              />
              <Label>เปิดใช้งาน AI ตอบกลับ Sticker</Label>
            </div>
            <div className="text-sm text-muted-foreground ml-10 space-y-1">
              <p>เมื่อเปิดใช้งาน: AI chatbot จะตอบกลับ sticker</p>
              <p>เมื่อปิดใช้งาน: AI chatbot จะไม่ตอบกลับ sticker</p>
            </div>
          </CardContent>
        </Card>

        {/* 9. Response Hours - เวลาทำงาน */}
        <Card className="bg-white dark:bg-card">
          <CardHeader className="pb-4">
            <CardTitle className="text-lg flex items-center gap-2">
              <Clock className="h-5 w-5" />
              เปิดใช้งาน Response Hours
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex items-center gap-3">
              <Switch
                checked={settings.enableResponseHours}
                onCheckedChange={(checked) => updateSetting('enableResponseHours', checked)}
                className="data-[state=checked]:bg-green-500"
              />
              <Label>บอทจะตอบตามวันและเวลาที่กำหนด</Label>
            </div>

            {settings.enableResponseHours && (
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label>เขตเวลา</Label>
                  <Select
                    value={settings.timezone}
                    onValueChange={(value) => updateSetting('timezone', value)}
                  >
                    <SelectTrigger className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="Asia/Bangkok">(UTC+07:00) Asia/Bangkok</SelectItem>
                      <SelectItem value="Asia/Tokyo">(UTC+09:00) Asia/Tokyo</SelectItem>
                      <SelectItem value="Asia/Shanghai">(UTC+08:00) Asia/Shanghai</SelectItem>
                      <SelectItem value="America/New_York">(UTC-05:00) America/New_York</SelectItem>
                      <SelectItem value="Europe/London">(UTC+00:00) Europe/London</SelectItem>
                    </SelectContent>
                  </Select>
                </div>

                <div className="space-y-2">
                  <Label>วันที่และเวลา</Label>
                  <ResponseHoursGrid
                    value={settings.responseHours}
                    onChange={(value) => updateSetting('responseHours', value)}
                  />
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Save Button */}
      <Button
        variant="orange"
        onClick={handleSave}
        disabled={isSaving}
        size="lg"
        className="w-full"
      >
        {isSaving ? (
          <Loader2 className="h-4 w-4 animate-spin" />
        ) : (
          <Save className="h-4 w-4" />
        )}
        บันทึกการตั้งค่า
      </Button>
    </div>
  );
}
