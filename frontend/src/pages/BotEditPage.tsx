import { useState, useEffect } from 'react';
import { useParams, useNavigate } from 'react-router';
import { useToast } from '@/hooks/use-toast';

// UI Components
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';

// Hooks
import { useBots } from '@/hooks/useKnowledgeBase';

// Icons
import { ArrowLeft, Save, Loader2, CheckCircle2, Eye, EyeOff, Trash2, ShieldCheck } from 'lucide-react';

// Channel type
type ChannelType = 'line' | 'facebook' | 'testing';
type LLMSelectType = 'preset' | 'custom';

// LINE icon
function LineIcon({ className }: { className?: string }) {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" className={className} fill="#06C755">
      <path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.627-.63h2.386c.349 0 .63.285.63.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.627-.63.349 0 .631.285.631.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/>
    </svg>
  );
}

// Facebook Messenger icon
function MessengerIcon({ className }: { className?: string }) {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" className={className} fill="#0084FF">
      <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.497 1.745 6.616 4.472 8.652V24l4.086-2.242c1.09.301 2.246.464 3.442.464 6.627 0 12-4.974 12-11.111C24 4.974 18.627 0 12 0zm1.193 14.963l-3.056-3.259-5.963 3.259 6.559-6.963 3.13 3.259 5.889-3.259-6.559 6.963z"/>
    </svg>
  );
}

// Testing icon
function TestingIcon({ className }: { className?: string }) {
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" className={className} fill="none" stroke="currentColor" strokeWidth="2">
      <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
    </svg>
  );
}

interface FormState {
  name: string;
  status: 'active' | 'inactive';
  aiProvider: 'openrouter' | 'custom';
  apiKey: string;
  llmMainType: LLMSelectType;
  llmMain: string;
  llmFallbackType: LLMSelectType;
  llmFallback: string;
  llmGeneralType: LLMSelectType;
  llmGeneral: string;
  llmGeneralFallbackType: LLMSelectType;
  llmGeneralFallback: string;
  channelType: ChannelType;
  channelSecret: string;
  channelAccessToken: string;
}

export function BotEditPage() {
  const { botId } = useParams();
  const navigate = useNavigate();
  const { toast } = useToast();
  const numericBotId = botId ? parseInt(botId, 10) : null;

  const { data: botsResponse, isLoading: isLoadingBots } = useBots();
  const [isSaving, setIsSaving] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [showApiKey, setShowApiKey] = useState(false);
  const [showChannelSecret, setShowChannelSecret] = useState(false);
  const [showAccessToken, setShowAccessToken] = useState(false);

  // Mock existing API key indicator (dabby.io style)
  const hasExistingApiKey = true; // TODO: Get from backend
  const maskedApiKey = '**** **** **** d08d'; // TODO: Get from backend

  // Get the current bot
  const currentBot = botsResponse?.data?.find(b => b.id === numericBotId);

  const [formState, setFormState] = useState<FormState>({
    name: '',
    status: 'active',
    aiProvider: 'openrouter',
    apiKey: '',
    llmMainType: 'custom',
    llmMain: 'openai/gpt-4o-mini',
    llmFallbackType: 'custom',
    llmFallback: 'openai/gpt-4o-mini',
    llmGeneralType: 'custom',
    llmGeneral: 'openai/gpt-4o-mini',
    llmGeneralFallbackType: 'custom',
    llmGeneralFallback: 'openai/gpt-4o-mini',
    channelType: 'line',
    channelSecret: '',
    channelAccessToken: '',
  });

  // Initialize form when bot loads
  useEffect(() => {
    if (currentBot) {
      setFormState(prev => ({
        ...prev,
        name: currentBot.name || '',
        status: currentBot.status as 'active' | 'inactive' || 'active',
        channelType: (currentBot.channel_type as ChannelType) || 'line',
      }));
    }
  }, [currentBot]);

  const updateField = <K extends keyof FormState>(field: K, value: FormState[K]) => {
    setFormState(prev => ({ ...prev, [field]: value }));
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

  const handleTestConnection = async () => {
    toast({
      title: 'กำลังทดสอบ...',
      description: 'กำลังทดสอบการเชื่อมต่อกับ LINE OA',
    });
    // TODO: Implement actual test
  };

  const handleDelete = async () => {
    if (!confirm('คุณแน่ใจหรือไม่ที่จะลบการเชื่อมต่อนี้?')) return;

    setIsDeleting(true);
    try {
      // TODO: Implement actual delete API call
      await new Promise(resolve => setTimeout(resolve, 1000));
      toast({
        title: 'ลบสำเร็จ',
        description: 'การเชื่อมต่อถูกลบเรียบร้อยแล้ว',
      });
      navigate('/bots');
    } catch {
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: 'ไม่สามารถลบการเชื่อมต่อได้',
        variant: 'destructive',
      });
    } finally {
      setIsDeleting(false);
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
        {/* Back link */}
        <button
          onClick={() => navigate('/bots')}
          className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground transition-colors"
        >
          <ArrowLeft className="h-4 w-4" />
          กลับไปหน้าการเชื่อมต่อ
        </button>

        {/* Title */}
        <h1 className="text-2xl font-bold tracking-tight text-center">แก้ไขการเชื่อมต่อ</h1>
      </div>

      <Card className="bg-white dark:bg-card">
        <CardContent className="p-6 space-y-8">
          {/* Enable/Disable Toggle - dabby.io style at top */}
          <div className="flex items-center gap-3">
            <Switch
              checked={formState.status === 'active'}
              onCheckedChange={(checked) => updateField('status', checked ? 'active' : 'inactive')}
              className="data-[state=checked]:bg-green-500"
            />
            <Label className={formState.status === 'active' ? 'text-green-600 font-medium' : 'text-muted-foreground'}>
              {formState.status === 'active' ? 'เปิดใช้งาน' : 'ปิดใช้งาน'}
            </Label>
          </div>

          {/* Connection Name */}
          <div className="space-y-2">
            <Label htmlFor="name">ชื่อการเชื่อมต่อ</Label>
            <Input
              id="name"
              value={formState.name}
              onChange={(e) => updateField('name', e.target.value)}
              placeholder="เช่น Line ร้าน ABC"
            />
          </div>

          <Separator />

          {/* AI Provider Section */}
          <div className="space-y-4">
            <div className="space-y-2">
              <Label>ผู้ให้บริการ AI Provider</Label>
              <Select
                value={formState.aiProvider}
                onValueChange={(value) => updateField('aiProvider', value as 'openrouter' | 'custom')}
              >
                <SelectTrigger className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="openrouter">OpenRouter (แนะนำ)</SelectItem>
                  <SelectItem value="custom">Custom API</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="apiKey">OpenRouter API Key</Label>
              <div className="relative">
                <Input
                  id="apiKey"
                  type={showApiKey ? 'text' : 'password'}
                  value={formState.apiKey}
                  onChange={(e) => updateField('apiKey', e.target.value)}
                  placeholder={hasExistingApiKey ? "มี key ทำงานอยู่แล้ว เว้นว่างไว้หากไม่ต้องการเปลี่ยน" : "sk-or-v1-xxxxxxxxxx..."}
                  className="pr-20"
                />
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="absolute right-0 top-0 h-full px-3"
                  onClick={() => setShowApiKey(!showApiKey)}
                >
                  {showApiKey ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                </Button>
              </div>
              {/* dabby.io style - Show existing API key indicator */}
              {hasExistingApiKey && (
                <div className="flex items-center gap-2 text-sm text-green-600">
                  <CheckCircle2 className="h-4 w-4" />
                  <span>API key ที่ใช้งานอยู่ปัจจุบัน: {maskedApiKey}</span>
                </div>
              )}
              <div className="flex items-center gap-2 text-xs text-muted-foreground">
                <ShieldCheck className="h-3 w-3" />
                <span>OpenRouter API key ของคุณจะถูกเข้ารหัสเพื่อความเป็นส่วนตัว</span>
              </div>
            </div>
          </div>

          <Separator />

          {/* LLM Models Grid */}
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-6">
              {/* Main Model */}
              <div className="space-y-2">
                <Label>LLM Model ที่ต้องการใช้ในการสนทนา & Personality</Label>
                <Select
                  value={formState.llmMainType}
                  onValueChange={(value) => updateField('llmMainType', value as LLMSelectType)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="custom">Custom LLM (ใส่ชื่อ LLM เอง)</SelectItem>
                    <SelectItem value="preset">เลือกจากรายการ</SelectItem>
                  </SelectContent>
                </Select>
                <Input
                  value={formState.llmMain}
                  onChange={(e) => updateField('llmMain', e.target.value)}
                  placeholder="openai/gpt-4o-mini"
                />
              </div>

              {/* Fallback Model */}
              <div className="space-y-2">
                <Label>โมเดลสำรองฉุกเฉิน ที่ใช้ในการสนทนา (fallback)</Label>
                <Select
                  value={formState.llmFallbackType}
                  onValueChange={(value) => updateField('llmFallbackType', value as LLMSelectType)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="custom">Custom LLM (ใส่ชื่อ LLM เอง)</SelectItem>
                    <SelectItem value="preset">เลือกจากรายการ</SelectItem>
                  </SelectContent>
                </Select>
                <Input
                  value={formState.llmFallback}
                  onChange={(e) => updateField('llmFallback', e.target.value)}
                  placeholder="openai/gpt-4o-mini"
                />
              </div>
            </div>

            <div className="grid grid-cols-2 gap-6">
              {/* General Model */}
              <div className="space-y-2">
                <Label>LLM Model สำหรับการตอบคำถามทั่วไป</Label>
                <Select
                  value={formState.llmGeneralType}
                  onValueChange={(value) => updateField('llmGeneralType', value as LLMSelectType)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="custom">Custom LLM (ใส่ชื่อ LLM เอง)</SelectItem>
                    <SelectItem value="preset">เลือกจากรายการ</SelectItem>
                  </SelectContent>
                </Select>
                <Input
                  value={formState.llmGeneral}
                  onChange={(e) => updateField('llmGeneral', e.target.value)}
                  placeholder="openai/gpt-4o-mini"
                />
              </div>

              {/* General Fallback Model */}
              <div className="space-y-2">
                <Label>โมเดลสำรองฉุกเฉินที่ใช้เพื่อใน (fallback)</Label>
                <Select
                  value={formState.llmGeneralFallbackType}
                  onValueChange={(value) => updateField('llmGeneralFallbackType', value as LLMSelectType)}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="custom">Custom LLM (ใส่ชื่อ LLM เอง)</SelectItem>
                    <SelectItem value="preset">เลือกจากรายการ</SelectItem>
                  </SelectContent>
                </Select>
                <Input
                  value={formState.llmGeneralFallback}
                  onChange={(e) => updateField('llmGeneralFallback', e.target.value)}
                  placeholder="openai/gpt-4o-mini"
                />
              </div>
            </div>
          </div>

          <Separator />

          {/* Platform Selection */}
          <div className="space-y-4">
            <Label>ใช้แพลตฟอร์ม</Label>
            <div className="grid grid-cols-3 gap-4">
              {/* LINE */}
              <div
                className={`p-4 border-2 rounded-lg cursor-pointer transition-all ${
                  formState.channelType === 'line'
                    ? 'border-green-500 bg-green-50 dark:bg-green-950/20'
                    : 'border-border hover:border-muted-foreground/50'
                }`}
                onClick={() => updateField('channelType', 'line')}
              >
                <div className="flex items-center gap-3">
                  <LineIcon className="h-8 w-8" />
                  <div>
                    <p className="font-semibold">LINE</p>
                    <p className="text-xs text-muted-foreground">LINE Messaging API</p>
                  </div>
                </div>
              </div>

              {/* Facebook */}
              <div
                className={`p-4 border-2 rounded-lg cursor-pointer transition-all ${
                  formState.channelType === 'facebook'
                    ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/20'
                    : 'border-border hover:border-muted-foreground/50'
                }`}
                onClick={() => updateField('channelType', 'facebook')}
              >
                <div className="flex items-center gap-3">
                  <MessengerIcon className="h-8 w-8" />
                  <div>
                    <p className="font-semibold">Facebook</p>
                    <p className="text-xs text-muted-foreground">Messenger Platform</p>
                  </div>
                </div>
              </div>

              {/* Testing */}
              <div
                className={`p-4 border-2 rounded-lg cursor-pointer transition-all ${
                  formState.channelType === 'testing'
                    ? 'border-primary bg-primary/5'
                    : 'border-border hover:border-muted-foreground/50'
                }`}
                onClick={() => updateField('channelType', 'testing')}
              >
                <div className="flex items-center gap-3">
                  <TestingIcon className="h-8 w-8 text-muted-foreground" />
                  <div>
                    <p className="font-semibold">Just testing</p>
                    <p className="text-xs text-muted-foreground">Not connected to any platform</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* Channel Credentials - Only show for LINE/Facebook */}
          {formState.channelType !== 'testing' && (
            <>
              <Separator />

              <div className="space-y-4">
                {formState.channelType === 'line' && (
                  <>
                    <div className="space-y-2">
                      <Label htmlFor="channelSecret">Channel Secret (LINE)</Label>
                      <div className="relative">
                        <Input
                          id="channelSecret"
                          type={showChannelSecret ? 'text' : 'password'}
                          value={formState.channelSecret}
                          onChange={(e) => updateField('channelSecret', e.target.value)}
                          placeholder="ใส่ Channel Secret จาก LINE Developers Console"
                        />
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="absolute right-0 top-0 h-full px-3"
                          onClick={() => setShowChannelSecret(!showChannelSecret)}
                        >
                          {showChannelSecret ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                        </Button>
                      </div>
                    </div>

                    <div className="space-y-2">
                      <Label htmlFor="accessToken">Channel Access Token (LINE)</Label>
                      <div className="relative">
                        <Input
                          id="accessToken"
                          type={showAccessToken ? 'text' : 'password'}
                          value={formState.channelAccessToken}
                          onChange={(e) => updateField('channelAccessToken', e.target.value)}
                          placeholder="ใส่ Channel Access Token จาก LINE Developers Console"
                        />
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="absolute right-0 top-0 h-full px-3"
                          onClick={() => setShowAccessToken(!showAccessToken)}
                        >
                          {showAccessToken ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                        </Button>
                      </div>
                    </div>

                    <Button
                      variant="outline"
                      className="text-blue-600 border-blue-600 hover:bg-blue-50"
                      onClick={handleTestConnection}
                    >
                      ทดสอบการเชื่อมต่อกับ LINE OA นี้
                    </Button>
                  </>
                )}

                {formState.channelType === 'facebook' && (
                  <>
                    <div className="space-y-2">
                      <Label htmlFor="pageId">Facebook Page ID</Label>
                      <Input
                        id="pageId"
                        placeholder="ใส่ Page ID จาก Facebook"
                      />
                    </div>

                    <div className="space-y-2">
                      <Label htmlFor="fbAccessToken">Page Access Token</Label>
                      <div className="relative">
                        <Input
                          id="fbAccessToken"
                          type={showAccessToken ? 'text' : 'password'}
                          placeholder="ใส่ Page Access Token"
                        />
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          className="absolute right-0 top-0 h-full px-3"
                          onClick={() => setShowAccessToken(!showAccessToken)}
                        >
                          {showAccessToken ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                        </Button>
                      </div>
                    </div>

                    <Button
                      variant="outline"
                      className="text-blue-600 border-blue-600 hover:bg-blue-50"
                      onClick={handleTestConnection}
                    >
                      ทดสอบการเชื่อมต่อกับ Facebook Page นี้
                    </Button>
                  </>
                )}
              </div>
            </>
          )}
        </CardContent>
      </Card>

      {/* Save Button - dabby.io style orange */}
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
        อัพเดทข้อมูล
      </Button>

      {/* Delete Button - dabby.io style */}
      <Button
        variant="destructive"
        onClick={handleDelete}
        disabled={isDeleting}
        size="lg"
        className="w-full"
      >
        {isDeleting ? (
          <Loader2 className="h-4 w-4 animate-spin" />
        ) : (
          <Trash2 className="h-4 w-4" />
        )}
        ลบการเชื่อมต่อนี้
      </Button>
    </div>
  );
}
