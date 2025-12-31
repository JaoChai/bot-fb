import { useState, useEffect } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { useToast } from '@/hooks/use-toast';
import {
  useConnection,
  useCreateConnection,
  useUpdateConnection,
  useDeleteConnection,
} from '@/hooks/useConnections';
import { ArrowLeft, Loader2, Eye, EyeOff, ExternalLink, Trash2, MessageCircle, Settings, Cpu, Key, Zap, Copy, Check, Send, User } from 'lucide-react';
import { ModelConfiguration } from '@/components/ModelSelector';
import { cn } from '@/lib/utils';

const PLATFORMS = [
  { id: 'line', name: 'LINE Official Account', icon: 'line' },
  { id: 'facebook', name: 'Facebook Page', icon: 'facebook' },
  { id: 'telegram', name: 'Telegram Bot', icon: 'telegram' },
  { id: 'testing', name: 'Just Testing', icon: 'testing' },
];

// Reusable Section Component
function Section({
  icon: Icon,
  title,
  description,
  children,
  className,
}: {
  icon: React.ElementType;
  title: string;
  description?: string;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('space-y-4', className)}>
      <div className="flex items-center gap-3">
        <div className="flex-shrink-0 w-10 h-10 bg-muted rounded-lg flex items-center justify-center">
          <Icon className="h-5 w-5 text-foreground" />
        </div>
        <div>
          <h3 className="font-semibold">{title}</h3>
          {description && (
            <p className="text-sm text-muted-foreground">{description}</p>
          )}
        </div>
      </div>
      <div className="pl-[52px]">{children}</div>
    </div>
  );
}

interface ConnectionFormData {
  enabled: boolean;
  connection_name: string;
  platform: 'line' | 'facebook' | 'testing' | 'telegram';
  primary_chat_model: string;
  fallback_chat_model: string;
  decision_model: string;
  fallback_decision_model: string;
  line_channel_secret: string;
  line_channel_access_token: string;
  telegram_bot_token: string;
  webhook_forwarder_enabled: boolean;
}

const DEFAULT_FORM_DATA: ConnectionFormData = {
  enabled: true,
  connection_name: '',
  platform: 'testing',
  primary_chat_model: 'google/gemini-2.5-flash-preview',
  fallback_chat_model: 'google/gemini-2.0-flash-001',
  decision_model: 'openai/gpt-4o-mini',
  fallback_decision_model: 'openai/gpt-4o',
  line_channel_secret: '',
  line_channel_access_token: '',
  telegram_bot_token: '',
  webhook_forwarder_enabled: false,
};

const BACKEND_URL = import.meta.env.VITE_API_URL || 'https://backend-production-b216.up.railway.app';

export function EditConnectionPage() {
  const { botId } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { toast } = useToast();

  const isEditMode = !!botId;
  const botIdNumber = botId ? parseInt(botId, 10) : null;
  const platformFromUrl = searchParams.get('platform') as 'line' | 'facebook' | 'testing' | 'telegram' | null;

  // API Hooks
  const { data: existingBot, isLoading: isLoadingBot } = useConnection(botIdNumber);
  const createMutation = useCreateConnection();
  const updateMutation = useUpdateConnection(botIdNumber);
  const deleteMutation = useDeleteConnection();

  // Local state
  const [showLineSecretToggle, setShowLineSecretToggle] = useState(false);
  const [showTelegramToken, setShowTelegramToken] = useState(false);
  const [webhookCopied, setWebhookCopied] = useState(false);
  const [formData, setFormData] = useState<ConnectionFormData>({
    ...DEFAULT_FORM_DATA,
    platform: platformFromUrl || 'testing',
  });

  // Populate form when existing bot data is loaded
  useEffect(() => {
    if (existingBot) {
      setFormData({
        enabled: existingBot.status === 'active',
        connection_name: existingBot.name,
        platform: existingBot.channel_type,
        primary_chat_model: existingBot.primary_chat_model || DEFAULT_FORM_DATA.primary_chat_model,
        fallback_chat_model: existingBot.fallback_chat_model || DEFAULT_FORM_DATA.fallback_chat_model,
        decision_model: existingBot.decision_model || DEFAULT_FORM_DATA.decision_model,
        fallback_decision_model: existingBot.fallback_decision_model || DEFAULT_FORM_DATA.fallback_decision_model,
        line_channel_secret: '', // Hidden field - don't populate
        line_channel_access_token: '', // Hidden field - don't populate
        telegram_bot_token: '', // Hidden field - don't populate
        webhook_forwarder_enabled: existingBot.webhook_forwarder_enabled || false,
      });
    }
  }, [existingBot]);

  const handleChange = <K extends keyof ConnectionFormData>(
    field: K,
    value: ConnectionFormData[K]
  ) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
  };

  const handleSave = async () => {
    // Validation
    if (!formData.connection_name.trim()) {
      toast({ title: 'ข้อผิดพลาด', description: 'กรุณากรอกชื่อการเชื่อมต่อ', variant: 'destructive' });
      return;
    }

    if (formData.platform === 'line' && !isEditMode) {
      if (!formData.line_channel_secret?.trim() || !formData.line_channel_access_token?.trim()) {
        toast({
          title: 'ข้อผิดพลาด',
          description: 'กรุณากรอก LINE Channel Secret และ Access Token',
          variant: 'destructive',
        });
        return;
      }
    }

    if (formData.platform === 'telegram' && !isEditMode) {
      if (!formData.telegram_bot_token?.trim()) {
        toast({
          title: 'ข้อผิดพลาด',
          description: 'กรุณากรอก Telegram Bot Token',
          variant: 'destructive',
        });
        return;
      }
    }

    try {
      if (isEditMode) {
        // Update existing connection
        await updateMutation.mutateAsync({
          name: formData.connection_name,
          status: formData.enabled ? 'active' : 'inactive',
          channel_type: formData.platform,
          primary_chat_model: formData.primary_chat_model,
          fallback_chat_model: formData.fallback_chat_model,
          decision_model: formData.decision_model,
          fallback_decision_model: formData.fallback_decision_model,
          webhook_forwarder_enabled: formData.webhook_forwarder_enabled,
          // Only send credentials if they were changed (not empty)
          ...(formData.platform === 'line' && formData.line_channel_secret && { channel_secret: formData.line_channel_secret }),
          ...(formData.platform === 'line' && formData.line_channel_access_token && { channel_access_token: formData.line_channel_access_token }),
          ...(formData.platform === 'telegram' && formData.telegram_bot_token && { channel_access_token: formData.telegram_bot_token }),
        });
        toast({
          title: 'บันทึกสำเร็จ',
          description: 'การเชื่อมต่อได้รับการอัปเดตแล้ว',
        });
      } else {
        // Create new connection
        const createData: Parameters<typeof createMutation.mutateAsync>[0] = {
          name: formData.connection_name,
          channel_type: formData.platform,
          primary_chat_model: formData.primary_chat_model,
          fallback_chat_model: formData.fallback_chat_model,
          decision_model: formData.decision_model,
          fallback_decision_model: formData.fallback_decision_model,
          webhook_forwarder_enabled: formData.webhook_forwarder_enabled,
        };

        // Set credentials based on platform
        if (formData.platform === 'line') {
          createData.channel_secret = formData.line_channel_secret;
          createData.channel_access_token = formData.line_channel_access_token;
        } else if (formData.platform === 'telegram') {
          // Telegram uses channel_access_token to store bot token
          createData.channel_access_token = formData.telegram_bot_token;
        }

        await createMutation.mutateAsync(createData);
        toast({
          title: 'สร้างสำเร็จ',
          description: 'การเชื่อมต่อใหม่ถูกสร้างแล้ว',
        });
      }
      navigate('/bots');
    } catch (error) {
      toast({
        title: 'ข้อผิดพลาด',
        description: error instanceof Error ? error.message : 'ไม่สามารถบันทึกการเชื่อมต่อได้',
        variant: 'destructive',
      });
    }
  };

  const handleDelete = async () => {
    if (!botIdNumber) return;

    try {
      await deleteMutation.mutateAsync(botIdNumber);
      toast({
        title: 'ลบสำเร็จ',
        description: 'การเชื่อมต่อได้รับการลบแล้ว',
      });
      navigate('/bots');
    } catch (error) {
      toast({
        title: 'ข้อผิดพลาด',
        description: error instanceof Error ? error.message : 'ไม่สามารถลบการเชื่อมต่อได้',
        variant: 'destructive',
      });
    }
  };

  const isSaving = createMutation.isPending || updateMutation.isPending;
  const isDeleting = deleteMutation.isPending;

  // Show loading state while fetching existing bot
  if (isEditMode && isLoadingBot) {
    return (
      <div className="min-h-screen bg-background flex items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  const getPlatformBadge = () => {
    const platformData = PLATFORMS.find(p => p.id === formData.platform);
    if (!platformData) return null;

    const colors: Record<string, string> = {
      line: 'bg-[#06C755]/10 text-[#06C755] border-[#06C755]/30',
      facebook: 'bg-[#0084FF]/10 text-[#0084FF] border-[#0084FF]/30',
      telegram: 'bg-[#0088CC]/10 text-[#0088CC] border-[#0088CC]/30',
      testing: 'bg-slate-100 text-slate-600 border-slate-300 dark:bg-slate-800 dark:text-slate-400',
    };

    return (
      <Badge variant="outline" className={cn('ml-3', colors[formData.platform])}>
        {platformData.name}
      </Badge>
    );
  };

  return (
    <div className="min-h-screen bg-background">
      <div className="max-w-3xl mx-auto px-4 py-8">
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
          <div className="flex-1">
            <div className="flex items-center">
              <h1 className="text-2xl font-bold tracking-tight">
                {isEditMode ? 'แก้ไขการเชื่อมต่อ' : 'สร้างการเชื่อมต่อใหม่'}
              </h1>
              {isEditMode && getPlatformBadge()}
            </div>
            <p className="text-muted-foreground text-sm mt-1">
              {formData.platform === 'telegram'
                ? 'ตั้งค่า Bot Token และ Webhook'
                : 'กำหนดค่า API และ LLM Models'}
            </p>
          </div>
          {isEditMode && (
            <div className="flex items-center gap-2">
              <span className="text-sm text-muted-foreground">สถานะ</span>
              <Switch
                id="enabled"
                checked={formData.enabled}
                onCheckedChange={(checked) => handleChange('enabled', checked)}
              />
              <span className={cn('text-sm font-medium', formData.enabled ? 'text-foreground' : 'text-muted-foreground')}>
                {formData.enabled ? 'เปิด' : 'ปิด'}
              </span>
            </div>
          )}
        </div>

        {/* Platform Mode Banner - Telegram Human Only */}
        {formData.platform === 'telegram' && (
          <div className="bg-[#0088CC]/5 border border-[#0088CC]/20 rounded-lg p-4 mb-6">
            <div className="flex items-center gap-3">
              <div className="bg-[#0088CC]/10 p-2 rounded-full">
                <User className="h-5 w-5 text-[#0088CC]" />
              </div>
              <div>
                <div className="flex items-center gap-2">
                  <span className="font-medium">Human Only</span>
                  <Badge variant="outline" className="bg-[#0088CC]/10 text-[#0088CC] border-[#0088CC]/30">
                    Telegram
                  </Badge>
                </div>
                <p className="text-sm text-muted-foreground">
                  ข้อความจะส่งตรงถึงทีม Support ไม่มี AI ตอบอัตโนมัติ
                </p>
              </div>
            </div>
          </div>
        )}

        <Card>
          <CardContent className="p-6 space-y-8">
            {/* Basic Info Section */}
            <Section
              icon={MessageCircle}
              title="ข้อมูลพื้นฐาน"
              description="ตั้งชื่อให้จำง่ายและสื่อความหมาย"
            >
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="connection_name">ชื่อการเชื่อมต่อ</Label>
                  <Input
                    id="connection_name"
                    placeholder={
                      formData.platform === 'telegram'
                        ? 'เช่น Telegram Support ร้านกาแฟ'
                        : formData.platform === 'facebook'
                        ? 'เช่น Facebook Bot ร้านกาแฟ'
                        : formData.platform === 'testing'
                        ? 'เช่น Bot ทดสอบ'
                        : 'เช่น LINE Bot สำหรับร้านกาแฟ'
                    }
                    value={formData.connection_name}
                    onChange={(e) => handleChange('connection_name', e.target.value)}
                    className="max-w-md"
                  />
                </div>

                {!isEditMode && (
                  <div className="text-xs text-muted-foreground bg-muted/50 rounded-lg px-3 py-2 max-w-md">
                    Platform: <strong>{PLATFORMS.find(p => p.id === formData.platform)?.name}</strong>
                    <span className="text-muted-foreground"> (เลือกจากหน้าก่อนหน้า)</span>
                  </div>
                )}
              </div>
            </Section>

            {/* LINE Credentials Section */}
            {formData.platform === 'line' && (
              <>
                <div className="border-t" />
                <Section
                  icon={Key}
                  title="LINE Credentials"
                  description="ข้อมูลจาก LINE Developers Console"
                >
                  <div className="space-y-4">
                    <div className="space-y-2">
                      <Label htmlFor="line-secret">
                        Channel Secret
                        {isEditMode && (
                          <span className="text-muted-foreground font-normal ml-2 text-xs">
                            (เว้นว่างถ้าไม่เปลี่ยน)
                          </span>
                        )}
                      </Label>
                      <div className="flex gap-2 max-w-md">
                        <Input
                          id="line-secret"
                          type={showLineSecretToggle ? 'text' : 'password'}
                          placeholder={isEditMode ? '••••••••' : 'Channel Secret'}
                          value={formData.line_channel_secret}
                          onChange={(e) => handleChange('line_channel_secret', e.target.value)}
                          className="font-mono text-sm"
                        />
                        <Button
                          variant="outline"
                          size="icon"
                          type="button"
                          onClick={() => setShowLineSecretToggle(!showLineSecretToggle)}
                          aria-label="Toggle visibility"
                        >
                          {showLineSecretToggle ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                        </Button>
                      </div>
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="line-token">
                        Channel Access Token
                        {isEditMode && (
                          <span className="text-muted-foreground font-normal ml-2 text-xs">
                            (เว้นว่างถ้าไม่เปลี่ยน)
                          </span>
                        )}
                      </Label>
                      <Input
                        id="line-token"
                        type="password"
                        placeholder={isEditMode ? '••••••••' : 'Channel Access Token'}
                        value={formData.line_channel_access_token}
                        onChange={(e) => handleChange('line_channel_access_token', e.target.value)}
                        className="font-mono text-sm max-w-md"
                      />
                    </div>
                    <Button variant="link" className="h-auto p-0 text-sm" asChild>
                      <a href="https://developers.line.biz" target="_blank" rel="noopener noreferrer">
                        ดูวิธีการเชื่อมต่อ LINE OA <ExternalLink className="h-3 w-3 ml-1" />
                      </a>
                    </Button>
                  </div>
                </Section>
              </>
            )}

            {/* Telegram Credentials Section */}
            {formData.platform === 'telegram' && (
              <>
                <div className="border-t" />
                <Section
                  icon={Send}
                  title="Telegram Bot Token"
                  description="ข้อมูลจาก @BotFather บน Telegram"
                >
                  <div className="space-y-4">
                    <div className="space-y-2">
                      <Label htmlFor="telegram-token">
                        Bot Token
                        {isEditMode && (
                          <span className="text-muted-foreground font-normal ml-2 text-xs">
                            (เว้นว่างถ้าไม่เปลี่ยน)
                          </span>
                        )}
                      </Label>
                      <div className="flex gap-2 max-w-md">
                        <Input
                          id="telegram-token"
                          type={showTelegramToken ? 'text' : 'password'}
                          placeholder={isEditMode ? '••••••••' : '123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ'}
                          value={formData.telegram_bot_token}
                          onChange={(e) => handleChange('telegram_bot_token', e.target.value)}
                          className="font-mono text-sm"
                        />
                        <Button
                          variant="outline"
                          size="icon"
                          type="button"
                          onClick={() => setShowTelegramToken(!showTelegramToken)}
                          aria-label="Toggle visibility"
                        >
                          {showTelegramToken ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                        </Button>
                      </div>
                    </div>

                    {/* Webhook URL - Show after bot is created */}
                    {isEditMode && existingBot && (
                      <div className="space-y-2">
                        <Label>Webhook URL</Label>
                        <div className="flex gap-2 max-w-md">
                          <Input
                            readOnly
                            value={`${BACKEND_URL.replace('/api', '')}/webhook/telegram/${existingBot.webhook_url?.split('/').pop() || '[token]'}`}
                            className="font-mono text-xs bg-muted"
                          />
                          <Button
                            variant="outline"
                            size="icon"
                            type="button"
                            onClick={() => {
                              const webhookUrl = `${BACKEND_URL.replace('/api', '')}/webhook/telegram/${existingBot.webhook_url?.split('/').pop() || ''}`;
                              navigator.clipboard.writeText(webhookUrl);
                              setWebhookCopied(true);
                              setTimeout(() => setWebhookCopied(false), 2000);
                              toast({ title: 'คัดลอกแล้ว', description: 'Webhook URL ถูกคัดลอกไปยังคลิปบอร์ด' });
                            }}
                            aria-label="Copy webhook URL"
                          >
                            {webhookCopied ? <Check className="h-4 w-4 text-green-500" /> : <Copy className="h-4 w-4" />}
                          </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">
                          นำ URL นี้ไปตั้งค่าที่ @BotFather ด้วยคำสั่ง /setwebhook
                        </p>
                      </div>
                    )}

                    <div className="bg-[#0088CC]/5 border border-[#0088CC]/20 rounded-lg p-4 max-w-md">
                      <h4 className="font-medium text-sm mb-2">วิธีสร้าง Telegram Bot</h4>
                      <ol className="text-xs text-muted-foreground space-y-1 list-decimal list-inside">
                        <li>เปิด Telegram แล้วค้นหา @BotFather</li>
                        <li>ส่งคำสั่ง /newbot และทำตามขั้นตอน</li>
                        <li>คัดลอก Bot Token มาวางที่นี่</li>
                        {isEditMode && <li>คัดลอก Webhook URL ด้านบนไปตั้งค่าด้วย /setwebhook</li>}
                      </ol>
                    </div>

                    <Button variant="link" className="h-auto p-0 text-sm" asChild>
                      <a href="https://core.telegram.org/bots#how-do-i-create-a-bot" target="_blank" rel="noopener noreferrer">
                        ดูวิธีการสร้าง Telegram Bot <ExternalLink className="h-3 w-3 ml-1" />
                      </a>
                    </Button>
                  </div>
                </Section>
              </>
            )}

            {/* AI-related sections - Hide for Telegram (Human Only mode) */}
            {formData.platform !== 'telegram' && (
              <>
                {/* OpenRouter API Note */}
                <div className="border-t" />
                <Section
                  icon={Key}
                  title="OpenRouter API"
                  description="ตั้งค่า API Key สำหรับเชื่อมต่อกับ AI Models"
                >
                  <div className="text-sm text-muted-foreground bg-muted/50 rounded-lg px-4 py-3 max-w-md">
                    <p className="mb-2">OpenRouter API Key ตั้งค่าที่หน้า Settings เพียงที่เดียว</p>
                    <Button variant="link" className="h-auto p-0 text-sm" asChild>
                      <a href="/settings">
                        ไปที่หน้า Settings <ExternalLink className="h-3 w-3 ml-1" />
                      </a>
                    </Button>
                  </div>
                </Section>

                {/* LLM Models Section */}
                <div className="border-t" />
                <Section
                  icon={Cpu}
                  title="AI Models"
                  description="เลือก model สำหรับตอบคำถามและตัดสินใจ"
                >
                  <ModelConfiguration
                    primaryModel={formData.primary_chat_model}
                    fallbackModel={formData.fallback_chat_model}
                    decisionModel={formData.decision_model}
                    fallbackDecisionModel={formData.fallback_decision_model}
                    onPrimaryChange={(value) => handleChange('primary_chat_model', value)}
                    onFallbackChange={(value) => handleChange('fallback_chat_model', value)}
                    onDecisionChange={(value) => handleChange('decision_model', value)}
                    onFallbackDecisionChange={(value) => handleChange('fallback_decision_model', value)}
                    showDecisionModels={true}
                  />
                </Section>

                {/* Advanced Options Section */}
                <div className="border-t" />
                <Section
                  icon={Settings}
                  title="ตัวเลือกขั้นสูง"
                >
                  <div className="flex items-center justify-between max-w-md">
                    <div>
                      <Label htmlFor="webhook-forwarder" className="font-normal">Webhook Forwarder</Label>
                      <p className="text-xs text-muted-foreground">ส่ง webhook ไปยัง URL อื่นด้วย</p>
                    </div>
                    <Switch
                      id="webhook-forwarder"
                      checked={formData.webhook_forwarder_enabled}
                      onCheckedChange={(checked) => handleChange('webhook_forwarder_enabled', checked)}
                    />
                  </div>
                </Section>
              </>
            )}
          </CardContent>
        </Card>

        {/* Action Buttons - Fixed at bottom */}
        <div className="flex items-center justify-between mt-6 pt-6 border-t">
          {isEditMode ? (
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button variant="ghost" className="text-destructive hover:text-destructive hover:bg-destructive/10" disabled={isDeleting}>
                  {isDeleting ? (
                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                  ) : (
                    <Trash2 className="h-4 w-4 mr-2" />
                  )}
                  ลบการเชื่อมต่อ
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>ยืนยันการลบ</AlertDialogTitle>
                  <AlertDialogDescription>
                    คุณต้องการลบการเชื่อมต่อนี้หรือไม่? การดำเนินการนี้ไม่สามารถยกเลิกได้
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>ยกเลิก</AlertDialogCancel>
                  <AlertDialogAction
                    onClick={handleDelete}
                    className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                  >
                    ลบ
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          ) : (
            <div />
          )}

          <Button
            onClick={handleSave}
            disabled={isSaving}
            size="lg"
            className="min-w-[180px]"
          >
            {isSaving ? (
              <>
                <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                {isEditMode ? 'กำลังบันทึก...' : 'กำลังสร้าง...'}
              </>
            ) : (
              <>
                <Zap className="h-4 w-4 mr-2" />
                {isEditMode ? 'บันทึกการเปลี่ยนแปลง' : 'สร้างการเชื่อมต่อ'}
              </>
            )}
          </Button>
        </div>
      </div>
    </div>
  );
}
