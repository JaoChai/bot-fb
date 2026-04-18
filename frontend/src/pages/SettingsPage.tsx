import { useState, useEffect } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
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
} from 'lucide-react';
import { Link } from 'react-router';
import {
  useUserSettings,
  useUpdateOpenRouterSettings,
  useTestOpenRouterConnection,
  useClearOpenRouterKey,
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

  useEffect(() => {
    setTestStatus('idle');
  }, [settings?.openrouter_configured]);

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

  const isConfigured = settings?.openrouter_configured ?? false;

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
                <CheckCircle className="h-3 w-3 mr-1" strokeWidth={1.5} /> ตั้งค่าแล้ว
              </>
            ) : (
              <>
                <AlertCircle className="h-3 w-3 mr-1" strokeWidth={1.5} /> ยังไม่ได้ตั้งค่า
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
                <CheckCircle className="h-4 w-4 text-emerald-600 dark:text-emerald-400 ml-auto" strokeWidth={1.5} />
              )}
              {testStatus === 'error' && (
                <XCircle className="h-4 w-4 text-destructive ml-auto" strokeWidth={1.5} />
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
                {showApiKey ? <EyeOff className="h-4 w-4" strokeWidth={1.5} /> : <Eye className="h-4 w-4" strokeWidth={1.5} />}
              </Button>
            </div>
          </div>

          <Button variant="link" className="h-auto p-0 text-sm" asChild>
            <a href="https://openrouter.ai/keys" target="_blank" rel="noopener noreferrer">
              รับ API Key ที่ OpenRouter <ExternalLink className="h-3 w-3 ml-1" strokeWidth={1.5} />
            </a>
          </Button>

          <div className="flex gap-2 pt-2">
            <Button onClick={handleSaveApiKey} disabled={updateMutation.isPending || !apiKey.trim()}>
              {updateMutation.isPending ? (
                <><Loader2 className="h-4 w-4 mr-2 animate-spin" /> กำลังบันทึก...</>
              ) : (
                'บันทึก'
              )}
            </Button>

            {isConfigured && (
              <>
                <Button variant="outline" onClick={handleTestConnection} disabled={testMutation.isPending}>
                  {testMutation.isPending ? (
                    <><Loader2 className="h-4 w-4 mr-2 animate-spin" /> กำลังทดสอบ...</>
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
                  {clearMutation.isPending ? <Loader2 className="h-4 w-4 animate-spin" /> : 'ลบ'}
                </Button>
              </>
            )}
          </div>
        </div>
      </Panel>

      {user?.role === 'owner' && (
        <Link
          to="/settings/quick-replies"
          className="flex items-center justify-between rounded-lg border bg-card p-4 transition-colors hover:bg-muted/40"
        >
          <div className="flex items-center gap-3">
            <Zap className="h-4 w-4 text-muted-foreground" strokeWidth={1.5} />
            <div>
              <p className="font-medium text-sm">Quick Replies</p>
              <p className="text-sm text-muted-foreground">จัดการคำตอบสำเร็จรูปสำหรับทีม</p>
            </div>
          </div>
          <ChevronRight className="h-4 w-4 text-muted-foreground" strokeWidth={1.5} />
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
