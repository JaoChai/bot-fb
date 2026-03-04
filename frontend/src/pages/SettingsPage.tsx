import { useState, useEffect } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Badge } from '@/components/ui/badge';
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
  useClearOpenRouterKey
} from '@/hooks/useUserSettings';
import { toast } from 'sonner';

export function SettingsPage() {
  const { user } = useAuthStore();
  const { data: settings } = useUserSettings();

  // Form state
  const [apiKey, setApiKey] = useState('');
  const [showApiKey, setShowApiKey] = useState(false);
  const [testStatus, setTestStatus] = useState<'idle' | 'success' | 'error'>('idle');

  // Mutations
  const updateMutation = useUpdateOpenRouterSettings();
  const testMutation = useTestOpenRouterConnection();
  const clearMutation = useClearOpenRouterKey();

  // Reset test status when settings change
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
      <div>
        <h1 className="text-2xl font-bold tracking-tight">ตั้งค่า</h1>
        <p className="text-muted-foreground">
          จัดการการตั้งค่าบัญชีและ API Keys
        </p>
      </div>

      {/* OpenRouter API Key Section */}
      <Card>
        <CardHeader>
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                <Key className="h-5 w-5 text-foreground" />
              </div>
              <div>
                <CardTitle>OpenRouter API Key</CardTitle>
                <CardDescription>
                  ใช้สำหรับสร้าง embeddings ในฐานความรู้
                </CardDescription>
              </div>
            </div>
            <Badge variant={isConfigured ? 'default' : 'secondary'} className="ml-2">
              {isConfigured ? (
                <><CheckCircle className="h-3 w-3 mr-1" /> ตั้งค่าแล้ว</>
              ) : (
                <><AlertCircle className="h-3 w-3 mr-1" /> ยังไม่ได้ตั้งค่า</>
              )}
            </Badge>
          </div>
        </CardHeader>
        <CardContent className="space-y-4">
          {/* Current key display */}
          {isConfigured && settings?.openrouter_api_key_masked && (
            <div className="flex items-center gap-2 p-3 rounded-lg bg-muted/50">
              <span className="text-sm text-muted-foreground">Key ปัจจุบัน:</span>
              <code className="font-mono text-sm">{settings.openrouter_api_key_masked}</code>
              {testStatus === 'success' && (
                <CheckCircle className="h-4 w-4 text-emerald-500 dark:text-emerald-400 ml-auto" />
              )}
              {testStatus === 'error' && (
                <XCircle className="h-4 w-4 text-destructive ml-auto" />
              )}
            </div>
          )}

          {/* API Key Input */}
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
                {showApiKey ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
              </Button>
            </div>
          </div>

          {/* External link */}
          <Button variant="link" className="h-auto p-0 text-sm" asChild>
            <a href="https://openrouter.ai/keys" target="_blank" rel="noopener noreferrer">
              รับ API Key ที่ OpenRouter <ExternalLink className="h-3 w-3 ml-1" />
            </a>
          </Button>

          {/* Action buttons */}
          <div className="flex gap-2 pt-2">
            <Button
              onClick={handleSaveApiKey}
              disabled={updateMutation.isPending || !apiKey.trim()}
            >
              {updateMutation.isPending ? (
                <><Loader2 className="h-4 w-4 mr-2 animate-spin" /> กำลังบันทึก...</>
              ) : (
                'บันทึก'
              )}
            </Button>

            {isConfigured && (
              <>
                <Button
                  variant="outline"
                  onClick={handleTestConnection}
                  disabled={testMutation.isPending}
                >
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
                  {clearMutation.isPending ? (
                    <Loader2 className="h-4 w-4 animate-spin" />
                  ) : (
                    'ลบ'
                  )}
                </Button>
              </>
            )}
          </div>
        </CardContent>
      </Card>

      <Separator />

      {/* Quick Replies - Owner only */}
      {user?.role === 'owner' && (
        <Card className="hover:bg-accent/50 transition-colors">
          <Link to="/settings/quick-replies">
            <CardHeader>
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                    <Zap className="h-5 w-5 text-foreground" />
                  </div>
                  <div>
                    <CardTitle>Quick Replies</CardTitle>
                    <CardDescription>
                      จัดการคำตอบสำเร็จรูปสำหรับทีม
                    </CardDescription>
                  </div>
                </div>
                <ChevronRight className="h-5 w-5 text-muted-foreground" />
              </div>
            </CardHeader>
          </Link>
        </Card>
      )}

      <Separator />

      {/* Profile Settings */}
      <Card>
        <CardHeader>
          <CardTitle>โปรไฟล์</CardTitle>
          <CardDescription>
            ข้อมูลส่วนตัวของคุณ
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="name">ชื่อ</Label>
            <Input id="name" defaultValue={user?.name || ''} disabled />
          </div>
          <div className="space-y-2">
            <Label htmlFor="email">อีเมล</Label>
            <Input id="email" type="email" defaultValue={user?.email || ''} disabled />
            <p className="text-xs text-muted-foreground">
              ติดต่อ support เพื่อเปลี่ยนอีเมล
            </p>
          </div>
        </CardContent>
      </Card>

      <Separator />

      {/* Danger Zone */}
      <Card className="border-destructive/50">
        <CardHeader>
          <CardTitle className="text-destructive">Danger Zone</CardTitle>
          <CardDescription>
            การดำเนินการที่ไม่สามารถย้อนกลับได้
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Button variant="destructive" disabled>ลบบัญชี</Button>
        </CardContent>
      </Card>
    </div>
  );
}
