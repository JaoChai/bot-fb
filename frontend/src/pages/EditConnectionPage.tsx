import { useState, useEffect } from 'react';
import { useNavigate, useParams, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
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
import { ArrowLeft, Loader2, Eye, EyeOff, ExternalLink, Trash2 } from 'lucide-react';

const LLM_MODELS = [
  { id: 'google/gemini-2.5-flash-preview', name: 'Google Gemini 2.5 Flash (แนะนำ)' },
  { id: 'google/gemini-2.0-flash-001', name: 'Google Gemini 2.0 Flash' },
  { id: 'openai/gpt-4o-mini', name: 'OpenAI GPT-4o Mini (แนะนำสำหรับ Chatbot)' },
  { id: 'openai/gpt-4o', name: 'OpenAI GPT-4o' },
  { id: 'anthropic/claude-3-5-sonnet', name: 'Anthropic Claude 3.5 Sonnet' },
];

const PLATFORMS = [
  { id: 'line', name: 'LINE Official Account' },
  { id: 'facebook', name: 'Facebook Page' },
  { id: 'testing', name: 'Just Testing' },
];

interface ConnectionFormData {
  enabled: boolean;
  connection_name: string;
  platform: 'line' | 'facebook' | 'testing';
  openrouter_api_key: string;
  primary_chat_model: string;
  fallback_chat_model: string;
  decision_model: string;
  fallback_decision_model: string;
  line_channel_secret: string;
  line_channel_access_token: string;
  webhook_forwarder_enabled: boolean;
}

const DEFAULT_FORM_DATA: ConnectionFormData = {
  enabled: true,
  connection_name: '',
  platform: 'testing',
  openrouter_api_key: '',
  primary_chat_model: 'google/gemini-2.5-flash-preview',
  fallback_chat_model: 'google/gemini-2.0-flash-001',
  decision_model: 'openai/gpt-4o-mini',
  fallback_decision_model: 'openai/gpt-4o',
  line_channel_secret: '',
  line_channel_access_token: '',
  webhook_forwarder_enabled: false,
};

export function EditConnectionPage() {
  const { botId } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { toast } = useToast();

  const isEditMode = !!botId;
  const botIdNumber = botId ? parseInt(botId, 10) : null;
  const platformFromUrl = searchParams.get('platform') as 'line' | 'facebook' | 'testing' | null;

  // API Hooks
  const { data: existingBot, isLoading: isLoadingBot } = useConnection(botIdNumber);
  const createMutation = useCreateConnection();
  const updateMutation = useUpdateConnection(botIdNumber);
  const deleteMutation = useDeleteConnection();

  // Local state
  const [showApiKey, setShowApiKey] = useState(false);
  const [showLineSecretToggle, setShowLineSecretToggle] = useState(false);
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
        openrouter_api_key: '', // Hidden field - don't populate
        primary_chat_model: existingBot.primary_chat_model || DEFAULT_FORM_DATA.primary_chat_model,
        fallback_chat_model: existingBot.fallback_chat_model || DEFAULT_FORM_DATA.fallback_chat_model,
        decision_model: existingBot.decision_model || DEFAULT_FORM_DATA.decision_model,
        fallback_decision_model: existingBot.fallback_decision_model || DEFAULT_FORM_DATA.fallback_decision_model,
        line_channel_secret: '', // Hidden field - don't populate
        line_channel_access_token: '', // Hidden field - don't populate
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

  const maskApiKey = (key: string) => {
    if (!key) return '';
    if (key.length <= 4) return key;
    return '*'.repeat(key.length - 4) + key.slice(-4);
  };

  const handleSave = async () => {
    // Validation
    if (!formData.connection_name.trim()) {
      toast({ title: 'ข้อผิดพลาด', description: 'กรุณากรอกชื่อการเชื่อมต่อ', variant: 'destructive' });
      return;
    }

    if (formData.platform !== 'testing' && !formData.openrouter_api_key.trim() && !isEditMode) {
      toast({ title: 'ข้อผิดพลาด', description: 'กรุณากรอก OpenRouter API Key', variant: 'destructive' });
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
          ...(formData.openrouter_api_key && { openrouter_api_key: formData.openrouter_api_key }),
          ...(formData.line_channel_secret && { channel_secret: formData.line_channel_secret }),
          ...(formData.line_channel_access_token && { channel_access_token: formData.line_channel_access_token }),
        });
        toast({
          title: 'บันทึกสำเร็จ',
          description: 'การเชื่อมต่อได้รับการอัปเดตแล้ว',
        });
      } else {
        // Create new connection
        await createMutation.mutateAsync({
          name: formData.connection_name,
          channel_type: formData.platform,
          primary_chat_model: formData.primary_chat_model,
          fallback_chat_model: formData.fallback_chat_model,
          decision_model: formData.decision_model,
          fallback_decision_model: formData.fallback_decision_model,
          webhook_forwarder_enabled: formData.webhook_forwarder_enabled,
          openrouter_api_key: formData.openrouter_api_key,
          channel_secret: formData.line_channel_secret,
          channel_access_token: formData.line_channel_access_token,
        });
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
            <h1 className="text-3xl font-bold tracking-tight">
              {isEditMode ? 'แก้ไขการเชื่อมต่อ' : 'สร้างการเชื่อมต่อใหม่'}
            </h1>
            <p className="text-muted-foreground mt-1">กำหนดค่า API และ LLM Models สำหรับการเชื่อมต่อ</p>
          </div>
        </div>

        <div className="space-y-6">
          {/* Connection Enabled Toggle - Only show in edit mode */}
          {isEditMode && (
            <Card>
              <CardHeader>
                <CardTitle className="text-lg">สถานะการเชื่อมต่อ</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex items-center justify-between">
                  <Label htmlFor="enabled" className="font-semibold">
                    เปิดใช้งานการเชื่อมต่อ
                  </Label>
                  <Switch
                    id="enabled"
                    checked={formData.enabled}
                    onCheckedChange={(checked) => handleChange('enabled', checked)}
                  />
                </div>
              </CardContent>
            </Card>
          )}

          {/* Connection Name */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">ชื่อการเชื่อมต่อ</CardTitle>
            </CardHeader>
            <CardContent>
              <Input
                placeholder="เช่น My Facebook Bot"
                value={formData.connection_name}
                onChange={(e) => handleChange('connection_name', e.target.value)}
              />
            </CardContent>
          </Card>

          {/* Platform Selection */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">Platform</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="platform" className="font-semibold">
                  เลือก Platform
                </Label>
                <Select
                  value={formData.platform}
                  onValueChange={(value) => handleChange('platform', value as 'line' | 'facebook' | 'testing')}
                  disabled={isEditMode} // Can't change platform in edit mode
                >
                  <SelectTrigger id="platform">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {PLATFORMS.map((platform) => (
                      <SelectItem key={platform.id} value={platform.id}>
                        {platform.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                {isEditMode && (
                  <p className="text-sm text-muted-foreground">ไม่สามารถเปลี่ยน Platform ได้หลังสร้างแล้ว</p>
                )}
              </div>

              {/* LINE Credentials */}
              {formData.platform === 'line' && (
                <div className="space-y-4 pt-4 border-t">
                  <div className="space-y-2">
                    <Label htmlFor="line-secret" className="font-semibold">
                      LINE Channel Secret
                      {isEditMode && <span className="text-muted-foreground font-normal ml-2">(เว้นว่างถ้าไม่ต้องการเปลี่ยน)</span>}
                    </Label>
                    <div className="flex gap-2">
                      <Input
                        id="line-secret"
                        type={showLineSecretToggle ? 'text' : 'password'}
                        placeholder={isEditMode ? '••••••••' : 'Channel Secret'}
                        value={formData.line_channel_secret}
                        onChange={(e) => handleChange('line_channel_secret', e.target.value)}
                        className="font-mono"
                      />
                      <Button
                        variant="outline"
                        size="icon"
                        onClick={() => setShowLineSecretToggle(!showLineSecretToggle)}
                        aria-label="Toggle visibility"
                      >
                        {showLineSecretToggle ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                      </Button>
                    </div>
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="line-token" className="font-semibold">
                      LINE Channel Access Token
                      {isEditMode && <span className="text-muted-foreground font-normal ml-2">(เว้นว่างถ้าไม่ต้องการเปลี่ยน)</span>}
                    </Label>
                    <Input
                      id="line-token"
                      type="password"
                      placeholder={isEditMode ? '••••••••' : 'Channel Access Token'}
                      value={formData.line_channel_access_token}
                      onChange={(e) => handleChange('line_channel_access_token', e.target.value)}
                      className="font-mono"
                    />
                  </div>
                  <Button variant="link" className="text-slate-600 h-auto p-0" asChild>
                    <a href="https://developers.line.biz" target="_blank" rel="noopener noreferrer">
                      วิธีการเชื่อมต่อ LINE OA <ExternalLink className="h-3 w-3 ml-1" />
                    </a>
                  </Button>
                </div>
              )}
            </CardContent>
          </Card>

          {/* OpenRouter API Settings */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">OpenRouter API Settings</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="api-key" className="font-semibold">
                  OpenRouter API Key
                  {isEditMode && <span className="text-muted-foreground font-normal ml-2">(เว้นว่างถ้าไม่ต้องการเปลี่ยน)</span>}
                </Label>
                <div className="flex gap-2">
                  <Input
                    id="api-key"
                    type={showApiKey ? 'text' : 'password'}
                    placeholder={isEditMode ? '••••••••' : 'sk-or-...'}
                    value={formData.openrouter_api_key}
                    onChange={(e) => handleChange('openrouter_api_key', e.target.value)}
                    className="font-mono"
                  />
                  <Button
                    variant="outline"
                    size="icon"
                    onClick={() => setShowApiKey(!showApiKey)}
                    aria-label={showApiKey ? 'Hide API Key' : 'Show API Key'}
                  >
                    {showApiKey ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                  </Button>
                </div>
                {formData.openrouter_api_key && (
                  <p className="text-sm text-muted-foreground">
                    API Key จะแสดงเป็น {maskApiKey(formData.openrouter_api_key)}
                  </p>
                )}
                <Button variant="link" className="text-slate-600 h-auto p-0" asChild>
                  <a href="https://openrouter.ai" target="_blank" rel="noopener noreferrer">
                    ไปสร้าง API Key <ExternalLink className="h-3 w-3 ml-1" />
                  </a>
                </Button>
              </div>
            </CardContent>
          </Card>

          {/* LLM Models Selection */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">LLM Models</CardTitle>
              <p className="text-sm text-muted-foreground mt-2">เลือก model สำหรับแต่ละงาน</p>
            </CardHeader>
            <CardContent className="space-y-6">
              {/* Primary Chat Model */}
              <div className="space-y-2">
                <div className="flex items-center gap-2">
                  <Label htmlFor="primary-model" className="font-semibold">
                    Primary Chat Model
                  </Label>
                  <Badge variant="outline" className="text-xs">สนทนา & Personality</Badge>
                </div>
                <Select value={formData.primary_chat_model} onValueChange={(value) => handleChange('primary_chat_model', value)}>
                  <SelectTrigger id="primary-model">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {LLM_MODELS.map((model) => (
                      <SelectItem key={model.id} value={model.id}>
                        {model.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {/* Fallback Chat Model */}
              <div className="space-y-2">
                <div className="flex items-center gap-2">
                  <Label htmlFor="fallback-model" className="font-semibold">
                    Fallback Chat Model
                  </Label>
                  <Badge variant="outline" className="text-xs">สำรองเมื่อ Primary ล้มเหลว</Badge>
                </div>
                <Select value={formData.fallback_chat_model} onValueChange={(value) => handleChange('fallback_chat_model', value)}>
                  <SelectTrigger id="fallback-model">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {LLM_MODELS.map((model) => (
                      <SelectItem key={model.id} value={model.id}>
                        {model.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {/* Decision Model */}
              <div className="space-y-2">
                <div className="flex items-center gap-2">
                  <Label htmlFor="decision-model" className="font-semibold">
                    Decision Model
                  </Label>
                  <Badge variant="outline" className="text-xs">Agentic Mode</Badge>
                </div>
                <Select value={formData.decision_model} onValueChange={(value) => handleChange('decision_model', value)}>
                  <SelectTrigger id="decision-model">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {LLM_MODELS.map((model) => (
                      <SelectItem key={model.id} value={model.id}>
                        {model.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              {/* Fallback Decision Model */}
              <div className="space-y-2">
                <div className="flex items-center gap-2">
                  <Label htmlFor="fallback-decision-model" className="font-semibold">
                    Fallback Decision Model
                  </Label>
                  <Badge variant="outline" className="text-xs">สำรอง</Badge>
                </div>
                <Select value={formData.fallback_decision_model} onValueChange={(value) => handleChange('fallback_decision_model', value)}>
                  <SelectTrigger id="fallback-decision-model">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {LLM_MODELS.map((model) => (
                      <SelectItem key={model.id} value={model.id}>
                        {model.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </CardContent>
          </Card>

          {/* Additional Settings */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">ตัวเลือกเพิ่มเติม</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex items-center justify-between">
                <Label htmlFor="webhook-forwarder" className="font-semibold">
                  Webhook Forwarder
                </Label>
                <Switch
                  id="webhook-forwarder"
                  checked={formData.webhook_forwarder_enabled}
                  onCheckedChange={(checked) => handleChange('webhook_forwarder_enabled', checked)}
                />
              </div>
            </CardContent>
          </Card>

          {/* Action Buttons */}
          <div className="flex gap-3 pt-4">
            <Button
              onClick={handleSave}
              disabled={isSaving}
              variant="orange"
              className="flex-1"
            >
              {isSaving ? (
                <>
                  <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                  {isEditMode ? 'กำลังบันทึก...' : 'กำลังสร้าง...'}
                </>
              ) : (
                isEditMode ? 'บันทึกการเปลี่ยนแปลง' : 'สร้างการเชื่อมต่อ'
              )}
            </Button>

            {isEditMode && (
              <AlertDialog>
                <AlertDialogTrigger asChild>
                  <Button variant="destructive" disabled={isDeleting}>
                    {isDeleting ? (
                      <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                    ) : (
                      <Trash2 className="h-4 w-4 mr-2" />
                    )}
                    ลบ
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
            )}
          </div>
        </div>
      </div>
    </div>
  );
}
