import { useState, useEffect } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { PageHeader } from '@/components/connections';
import { Panel } from '@/components/common';
import {
  Key,
  Eye,
  EyeOff,
  ExternalLink,
  Loader2,
  CheckCircle,
  XCircle,
  AlertCircle,
  Zap,
  ChevronRight,
  Moon,
} from 'lucide-react';
import { Link } from 'react-router';
import {
  useUserSettings,
  useUpdateOpenRouterSettings,
  useTestOpenRouterConnection,
  useClearOpenRouterKey,
  useUpdateEasySlipToken,
  useTestEasySlipConnection,
  useClearEasySlipToken,
  useUpdateQuietHours,
} from '@/hooks/useUserSettings';
import { toast } from 'sonner';

export function SettingsPage() {
  const { user } = useAuthStore();
  const { data: settings } = useUserSettings();

  const [apiKey, setApiKey] = useState('');
  const [showApiKey, setShowApiKey] = useState(false);
  const [testStatus, setTestStatus] = useState<'idle' | 'success' | 'error'>('idle');

  const updateMutation = useUpdateOpenRouterSettings();
  const testMutation = useTestOpenRouterConnection();
  const clearMutation = useClearOpenRouterKey();

  const [easySlipToken, setEasySlipToken] = useState('');
  const [showEasySlipToken, setShowEasySlipToken] = useState(false);
  const [easySlipTestStatus, setEasySlipTestStatus] = useState<'idle' | 'success' | 'error'>('idle');

  const updateEasySlipMutation = useUpdateEasySlipToken();
  const testEasySlipMutation = useTestEasySlipConnection();
  const clearEasySlipMutation = useClearEasySlipToken();

  const [quietEnabled, setQuietEnabled] = useState(true);
  const [quietStart, setQuietStart] = useState('23:00');
  const [quietEnd, setQuietEnd] = useState('08:00');
  const updateQuietHoursMutation = useUpdateQuietHours();

  useEffect(() => {
    if (settings) {
      setQuietEnabled(settings.quiet_hours_enabled);
      setQuietStart(settings.quiet_hours_start);
      setQuietEnd(settings.quiet_hours_end);
    }
  }, [settings]);

  const handleSaveQuietHours = async () => {
    if (quietStart === quietEnd) {
      toast.error('เวลาเริ่มและสิ้นสุดต้องไม่เท่ากัน');
      return;
    }
    try {
      await updateQuietHoursMutation.mutateAsync({
        enabled: quietEnabled, start: quietStart, end: quietEnd,
      });
      toast.success('บันทึกช่วงเวลาเงียบแล้ว');
    } catch {
      toast.error('บันทึกช่วงเวลาเงียบไม่สำเร็จ');
    }
  };

  useEffect(() => {
    setTestStatus('idle');
  }, [settings?.openrouter_configured]);

  useEffect(() => {
    setEasySlipTestStatus('idle');
  }, [settings?.easyslip_configured]);

  const handleSaveApiKey = async () => {
    if (!apiKey.trim()) {
      toast.error('กรุณากรอก API Key');
      return;
    }
    try {
      await updateMutation.mutateAsync({
        api_key: apiKey.trim(),
        model: settings?.openrouter_model || 'openai/gpt-4o-mini',
      });
      setApiKey('');
      setTestStatus('idle');
      toast.success('บันทึก API Key สำเร็จ');
    } catch {
      toast.error('ไม่สามารถบันทึก API Key ได้');
    }
  };

  const handleTestConnection = async () => {
    try {
      const result = await testMutation.mutateAsync();
      if (result.success) {
        setTestStatus('success');
        toast.success(result.message || 'เชื่อมต่อสำเร็จ');
      } else {
        setTestStatus('error');
        toast.error(result.message || 'เชื่อมต่อไม่สำเร็จ');
      }
    } catch {
      setTestStatus('error');
      toast.error('ไม่สามารถทดสอบการเชื่อมต่อได้');
    }
  };

  const handleClearApiKey = async () => {
    if (!confirm('คุณต้องการลบ API Key หรือไม่?')) return;
    try {
      await clearMutation.mutateAsync();
      setTestStatus('idle');
      toast.success('ลบ API Key สำเร็จ');
    } catch {
      toast.error('ไม่สามารถลบ API Key ได้');
    }
  };

  const handleSaveEasySlipToken = async () => {
    if (!easySlipToken.trim()) {
      toast.error('กรุณากรอก API Token');
      return;
    }
    try {
      await updateEasySlipMutation.mutateAsync({ token: easySlipToken.trim() });
      setEasySlipToken('');
      setEasySlipTestStatus('idle');
      toast.success('บันทึก API Token สำเร็จ');
    } catch {
      toast.error('ไม่สามารถบันทึก API Token ได้');
    }
  };

  const handleTestEasySlipConnection = async () => {
    try {
      const result = await testEasySlipMutation.mutateAsync();
      if (result.success) {
        setEasySlipTestStatus('success');
        const remaining = result.quota?.remaining;
        toast.success(
          remaining != null ? `${result.message} — เหลือโควตา ${remaining} สลิป` : result.message || 'เชื่อมต่อสำเร็จ'
        );
      } else {
        setEasySlipTestStatus('error');
        toast.error(result.message || 'เชื่อมต่อไม่สำเร็จ');
      }
    } catch {
      setEasySlipTestStatus('error');
      toast.error('ไม่สามารถทดสอบการเชื่อมต่อได้');
    }
  };

  const handleClearEasySlipToken = async () => {
    if (!confirm('คุณต้องการลบ API Token หรือไม่?')) return;
    try {
      await clearEasySlipMutation.mutateAsync();
      setEasySlipTestStatus('idle');
      toast.success('ลบ API Token สำเร็จ');
    } catch {
      toast.error('ไม่สามารถลบ API Token ได้');
    }
  };

  const isConfigured = settings?.openrouter_configured ?? false;
  const isEasySlipConfigured = settings?.easyslip_configured ?? false;

  return (
    <div className="space-y-6">
      <PageHeader title="ตั้งค่า" description="จัดการการตั้งค่าบัญชีและ API Keys" />

      <Panel
        icon={Key}
        title="OpenRouter API Key"
        description="ใช้สำหรับสร้าง embeddings ในฐานความรู้"
        actions={
          <Badge variant={isConfigured ? 'default' : 'secondary'}>
            {isConfigured ? (
              <>
                <CheckCircle className="size-3 mr-1" strokeWidth={1.5} /> ตั้งค่าแล้ว
              </>
            ) : (
              <>
                <AlertCircle className="size-3 mr-1" strokeWidth={1.5} /> ยังไม่ได้ตั้งค่า
              </>
            )}
          </Badge>
        }
      >
        <div className="space-y-4">
          {isConfigured && settings?.openrouter_api_key_masked && (
            <div className="flex items-center gap-2 p-3 rounded-md border bg-muted/30">
              <span className="text-sm text-muted-foreground">Key ปัจจุบัน:</span>
              <code className="font-mono text-sm">{settings.openrouter_api_key_masked}</code>
              {testStatus === 'success' && (
                <CheckCircle className="size-4 text-emerald-600 dark:text-emerald-400 ml-auto" strokeWidth={1.5} />
              )}
              {testStatus === 'error' && (
                <XCircle className="size-4 text-destructive ml-auto" strokeWidth={1.5} />
              )}
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="api-key">
              {isConfigured ? 'เปลี่ยน API Key' : 'API Key'}
              {isConfigured && (
                <span className="text-muted-foreground font-normal ml-2 text-xs">
                  (เว้นว่างถ้าไม่เปลี่ยน)
                </span>
              )}
            </Label>
            <div className="flex gap-2 max-w-md">
              <Input
                id="api-key"
                type={showApiKey ? 'text' : 'password'}
                placeholder={isConfigured ? '••••••••' : 'sk-or-v1-...'}
                value={apiKey}
                onChange={(e) => setApiKey(e.target.value)}
                className="font-mono text-sm"
              />
              <Button
                variant="outline"
                size="icon"
                type="button"
                onClick={() => setShowApiKey(!showApiKey)}
                aria-label={showApiKey ? 'ซ่อน API Key' : 'แสดง API Key'}
              >
                {showApiKey ? <EyeOff className="size-4" strokeWidth={1.5} /> : <Eye className="size-4" strokeWidth={1.5} />}
              </Button>
            </div>
          </div>

          <Button variant="link" className="h-auto p-0 text-sm" asChild>
            <a href="https://openrouter.ai/keys" target="_blank" rel="noopener noreferrer">
              รับ API Key ที่ OpenRouter <ExternalLink className="size-3 ml-1" strokeWidth={1.5} />
            </a>
          </Button>

          <div className="flex gap-2 pt-2">
            <Button onClick={handleSaveApiKey} disabled={updateMutation.isPending || !apiKey.trim()}>
              {updateMutation.isPending ? (
                <><Loader2 className="size-4 mr-2 animate-spin" /> กำลังบันทึก...</>
              ) : (
                'บันทึก'
              )}
            </Button>

            {isConfigured && (
              <>
                <Button variant="outline" onClick={handleTestConnection} disabled={testMutation.isPending}>
                  {testMutation.isPending ? (
                    <><Loader2 className="size-4 mr-2 animate-spin" /> กำลังทดสอบ...</>
                  ) : (
                    'ทดสอบการเชื่อมต่อ'
                  )}
                </Button>

                <Button
                  variant="ghost"
                  className="text-destructive hover:text-destructive"
                  onClick={handleClearApiKey}
                  disabled={clearMutation.isPending}
                >
                  {clearMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : 'ลบ'}
                </Button>
              </>
            )}
          </div>
        </div>
      </Panel>

      <Panel
        icon={Key}
        title="EasySlip API Token"
        description="ใช้สำหรับฟีเจอร์ตรวจสลิปอัตโนมัติ — เปิดใช้ต่อบอทได้ที่ ตั้งค่าบอท → ตรวจสลิป"
        actions={
          <Badge variant={isEasySlipConfigured ? 'default' : 'secondary'}>
            {isEasySlipConfigured ? (
              <>
                <CheckCircle className="size-3 mr-1" strokeWidth={1.5} /> ตั้งค่าแล้ว
              </>
            ) : (
              <>
                <AlertCircle className="size-3 mr-1" strokeWidth={1.5} /> ยังไม่ได้ตั้งค่า
              </>
            )}
          </Badge>
        }
      >
        <div className="space-y-4">
          {isEasySlipConfigured && settings?.easyslip_token_masked && (
            <div className="flex items-center gap-2 p-3 rounded-md border bg-muted/30">
              <span className="text-sm text-muted-foreground">Token ปัจจุบัน:</span>
              <code className="font-mono text-sm">{settings.easyslip_token_masked}</code>
              {easySlipTestStatus === 'success' && (
                <CheckCircle className="size-4 text-emerald-600 dark:text-emerald-400 ml-auto" strokeWidth={1.5} />
              )}
              {easySlipTestStatus === 'error' && (
                <XCircle className="size-4 text-destructive ml-auto" strokeWidth={1.5} />
              )}
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="easyslip-token">
              {isEasySlipConfigured ? 'เปลี่ยน API Token' : 'API Token'}
              {isEasySlipConfigured && (
                <span className="text-muted-foreground font-normal ml-2 text-xs">
                  (เว้นว่างถ้าไม่เปลี่ยน)
                </span>
              )}
            </Label>
            <div className="flex gap-2 max-w-md">
              <Input
                id="easyslip-token"
                type={showEasySlipToken ? 'text' : 'password'}
                placeholder={isEasySlipConfigured ? '••••••••' : 'EasySlip API Token'}
                value={easySlipToken}
                onChange={(e) => setEasySlipToken(e.target.value)}
                className="font-mono text-sm"
              />
              <Button
                variant="outline"
                size="icon"
                type="button"
                onClick={() => setShowEasySlipToken(!showEasySlipToken)}
                aria-label={showEasySlipToken ? 'ซ่อน API Token' : 'แสดง API Token'}
              >
                {showEasySlipToken ? <EyeOff className="size-4" strokeWidth={1.5} /> : <Eye className="size-4" strokeWidth={1.5} />}
              </Button>
            </div>
          </div>

          <Button variant="link" className="h-auto p-0 text-sm" asChild>
            <a href="https://easyslip.com/" target="_blank" rel="noopener noreferrer">
              รับ API Token ที่ EasySlip <ExternalLink className="size-3 ml-1" strokeWidth={1.5} />
            </a>
          </Button>

          <div className="flex gap-2 pt-2">
            <Button onClick={handleSaveEasySlipToken} disabled={updateEasySlipMutation.isPending || !easySlipToken.trim()}>
              {updateEasySlipMutation.isPending ? (
                <><Loader2 className="size-4 mr-2 animate-spin" /> กำลังบันทึก...</>
              ) : (
                'บันทึก'
              )}
            </Button>

            {isEasySlipConfigured && (
              <>
                <Button variant="outline" onClick={handleTestEasySlipConnection} disabled={testEasySlipMutation.isPending}>
                  {testEasySlipMutation.isPending ? (
                    <><Loader2 className="size-4 mr-2 animate-spin" /> กำลังทดสอบ...</>
                  ) : (
                    'ทดสอบการเชื่อมต่อ'
                  )}
                </Button>

                <Button
                  variant="ghost"
                  className="text-destructive hover:text-destructive"
                  onClick={handleClearEasySlipToken}
                  disabled={clearEasySlipMutation.isPending}
                >
                  {clearEasySlipMutation.isPending ? <Loader2 className="size-4 animate-spin" /> : 'ลบ'}
                </Button>
              </>
            )}
          </div>
        </div>
      </Panel>

      <Panel
        icon={Moon}
        title="ช่วงเวลาเงียบแจ้งเตือน"
        description="ช่วงเวลานี้จะเงียบเฉพาะแจ้งเตือนซ้ำ (งานค้างกดยืนยัน/ของค้างสต๊อก) — ออเดอร์ใหม่ยังแจ้งเตือนปกติ"
        actions={
          <Switch checked={quietEnabled} onCheckedChange={setQuietEnabled} />
        }
      >
        <div className="space-y-4">
          <div className="flex flex-wrap items-end gap-4">
            <div className="space-y-2">
              <Label htmlFor="quiet-start">เริ่มเงียบ</Label>
              <Input
                id="quiet-start"
                type="time"
                value={quietStart}
                onChange={(e) => setQuietStart(e.target.value)}
                disabled={!quietEnabled}
                className="w-32"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="quiet-end">สิ้นสุด</Label>
              <Input
                id="quiet-end"
                type="time"
                value={quietEnd}
                onChange={(e) => setQuietEnd(e.target.value)}
                disabled={!quietEnabled}
                className="w-32"
              />
            </div>
            <Button
              onClick={handleSaveQuietHours}
              disabled={updateQuietHoursMutation.isPending}
            >
              {updateQuietHoursMutation.isPending ? 'กำลังบันทึก...' : 'บันทึก'}
            </Button>
          </div>
          <p className="text-sm text-muted-foreground">
            ค่าเริ่มต้น 23:00–08:00 ตามเวลาไทย — พ้นช่วงเงียบแล้วงานที่ค้างจะถูกเตือนทันทีในรอบถัดไป
          </p>
        </div>
      </Panel>

      {user?.role === 'owner' && (
        <Link
          to="/settings/quick-replies"
          className="flex items-center justify-between rounded-lg border bg-card p-4 transition-colors hover:bg-muted/40"
        >
          <div className="flex items-center gap-3">
            <Zap className="size-4 text-muted-foreground" strokeWidth={1.5} />
            <div>
              <p className="font-medium text-sm">Quick Replies</p>
              <p className="text-sm text-muted-foreground">จัดการคำตอบสำเร็จรูปสำหรับทีม</p>
            </div>
          </div>
          <ChevronRight className="size-4 text-muted-foreground" strokeWidth={1.5} />
        </Link>
      )}

      <Panel title="โปรไฟล์" description="ข้อมูลส่วนตัวของคุณ">
        <div className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="name">ชื่อ</Label>
            <Input id="name" defaultValue={user?.name || ''} disabled />
          </div>
          <div className="space-y-2">
            <Label htmlFor="email">อีเมล</Label>
            <Input id="email" type="email" defaultValue={user?.email || ''} disabled />
            <p className="text-xs text-muted-foreground">ติดต่อ support เพื่อเปลี่ยนอีเมล</p>
          </div>
        </div>
      </Panel>

      <Panel tone="destructive" title="Danger Zone" description="การดำเนินการที่ไม่สามารถย้อนกลับได้">
        <Button variant="destructive" disabled>ลบบัญชี</Button>
      </Panel>
    </div>
  );
}
