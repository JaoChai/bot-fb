import { useNavigate } from 'react-router';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
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
  useCreateConnection,
  useUpdateConnection,
  useDeleteConnection,
  useToggleBotStatus,
} from '@/hooks/useConnections';
import { useConnectionForm } from '@/hooks/useConnectionForm';
import { Loader2, Zap, Trash2, User } from 'lucide-react';
import { PageHeader, StickyActionBar } from '@/components/connections';
import { BasicInfoSection } from '@/components/connections/sections/BasicInfoSection';
import { LineCredentialsSection } from '@/components/connections/sections/LineCredentialsSection';
import { TelegramCredentialsSection } from '@/components/connections/sections/TelegramCredentialsSection';
import { AIModelsSection } from '@/components/connections/sections/AIModelsSection';
import { AdvancedOptionsSection } from '@/components/connections/sections/AdvancedOptionsSection';
import { cn } from '@/lib/utils';

const PLATFORMS = [
  { id: 'line', name: 'LINE Official Account' },
  { id: 'facebook', name: 'Facebook Page' },
  { id: 'telegram', name: 'Telegram Bot' },
  { id: 'testing', name: 'Just Testing' },
];

export function EditConnectionPage() {
  const navigate = useNavigate();
  const { toast } = useToast();

  const { formData, handleChange, existingBot, isLoadingBot, isEditMode, botIdNumber } =
    useConnectionForm();

  const createMutation = useCreateConnection();
  const updateMutation = useUpdateConnection(botIdNumber);
  const deleteMutation = useDeleteConnection();
  const toggleStatusMutation = useToggleBotStatus();

  const isSaving = createMutation.isPending || updateMutation.isPending;
  const isDeleting = deleteMutation.isPending;

  const getPlatformBadge = () => {
    const platformData = PLATFORMS.find((p) => p.id === formData.platform);
    if (!platformData) return null;

    const colors: Record<string, string> = {
      line: 'bg-[#06C755]/10 text-[#06C755] border-[#06C755]/30',
      facebook: 'bg-[#0084FF]/10 text-[#0084FF] border-[#0084FF]/30',
      telegram: 'bg-[#0088CC]/10 text-[#0088CC] border-[#0088CC]/30',
      testing: 'bg-slate-100 text-slate-600 border-slate-300 dark:bg-slate-800 dark:text-slate-400',
    };

    return (
      <Badge variant="outline" className={colors[formData.platform]}>
        {platformData.name}
      </Badge>
    );
  };

  const handleStatusToggle = async (checked: boolean) => {
    if (!botIdNumber) return;

    const newStatus = checked ? 'active' : 'inactive';
    handleChange('enabled', checked);

    try {
      await toggleStatusMutation.mutateAsync({ botId: botIdNumber, status: newStatus });
      toast({
        title: newStatus === 'active' ? 'เปิดใช้งานแล้ว' : 'ปิดใช้งานแล้ว',
        description: `สถานะถูกเปลี่ยนเป็น "${newStatus === 'active' ? 'เปิด' : 'ปิด'}"`,
      });
    } catch (err) {
      handleChange('enabled', !checked);
      toast({
        title: 'เกิดข้อผิดพลาด',
        description: err instanceof Error ? err.message : 'ไม่สามารถเปลี่ยนสถานะได้',
        variant: 'destructive',
      });
    }
  };

  const handleSave = async () => {
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
        await updateMutation.mutateAsync({
          name: formData.connection_name,
          status: formData.enabled ? 'active' : 'inactive',
          channel_type: formData.platform,
          primary_chat_model: formData.primary_chat_model,
          fallback_chat_model: formData.fallback_chat_model,
          decision_model: formData.decision_model,
          fallback_decision_model: formData.fallback_decision_model,
          webhook_forwarder_enabled: formData.webhook_forwarder_enabled,
          auto_handover: formData.auto_handover,
          use_confidence_cascade: formData.use_confidence_cascade,
          cascade_cheap_model: formData.cascade_cheap_model,
          cascade_expensive_model: formData.cascade_expensive_model,
          ...(formData.platform === 'line' && formData.line_channel_secret && {
            channel_secret: formData.line_channel_secret,
          }),
          ...(formData.platform === 'line' && formData.line_channel_access_token && {
            channel_access_token: formData.line_channel_access_token,
          }),
          ...(formData.platform === 'telegram' && formData.telegram_bot_token && {
            channel_access_token: formData.telegram_bot_token,
          }),
        });
        toast({ title: 'บันทึกสำเร็จ', description: 'การเชื่อมต่อได้รับการอัปเดตแล้ว' });
      } else {
        const createData: Parameters<typeof createMutation.mutateAsync>[0] = {
          name: formData.connection_name,
          channel_type: formData.platform,
          primary_chat_model: formData.primary_chat_model,
          fallback_chat_model: formData.fallback_chat_model,
          decision_model: formData.decision_model,
          fallback_decision_model: formData.fallback_decision_model,
          webhook_forwarder_enabled: formData.webhook_forwarder_enabled,
          auto_handover: formData.auto_handover,
          use_confidence_cascade: formData.use_confidence_cascade,
          cascade_cheap_model: formData.cascade_cheap_model,
          cascade_expensive_model: formData.cascade_expensive_model,
        };

        if (formData.platform === 'line') {
          createData.channel_secret = formData.line_channel_secret;
          createData.channel_access_token = formData.line_channel_access_token;
        } else if (formData.platform === 'telegram') {
          createData.channel_access_token = formData.telegram_bot_token;
        }

        await createMutation.mutateAsync(createData);
        toast({ title: 'สร้างสำเร็จ', description: 'การเชื่อมต่อใหม่ถูกสร้างแล้ว' });
        navigate('/bots');
      }
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
      toast({ title: 'ลบสำเร็จ', description: 'การเชื่อมต่อได้รับการลบแล้ว' });
      navigate('/bots');
    } catch (error) {
      toast({
        title: 'ข้อผิดพลาด',
        description: error instanceof Error ? error.message : 'ไม่สามารถลบการเชื่อมต่อได้',
        variant: 'destructive',
      });
    }
  };

  if (isEditMode && isLoadingBot) {
    return (
      <div className="flex items-center justify-center min-h-[50vh]">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  const statusToggle = isEditMode ? (
    <div className="flex items-center gap-2">
      <span className="text-sm text-muted-foreground">สถานะ</span>
      <Switch
        id="enabled"
        checked={formData.enabled}
        onCheckedChange={handleStatusToggle}
        disabled={toggleStatusMutation.isPending}
      />
      <span
        className={cn(
          'text-sm font-medium transition-colors duration-150',
          formData.enabled ? 'text-foreground' : 'text-muted-foreground'
        )}
      >
        {toggleStatusMutation.isPending ? '...' : formData.enabled ? 'เปิด' : 'ปิด'}
      </span>
    </div>
  ) : null;

  return (
    <div className="mx-auto max-w-3xl w-full">
      <div className="mb-8">
        <PageHeader
          title={isEditMode ? 'แก้ไขการเชื่อมต่อ' : 'สร้างการเชื่อมต่อใหม่'}
          description={
            formData.platform === 'telegram'
              ? 'ตั้งค่า Bot Token และ Webhook'
              : 'กำหนดค่า API และ LLM Models'
          }
          backTo="/bots"
          backLabel="กลับไปหน้าการเชื่อมต่อ"
          badge={isEditMode ? getPlatformBadge() : undefined}
          actions={statusToggle ?? undefined}
        />
      </div>

      {/* Telegram Human Only banner */}
      {formData.platform === 'telegram' && (
        <div className="bg-muted/30 border rounded-lg p-4 mb-6">
          <div className="flex items-center gap-3">
            <User className="h-5 w-5 text-muted-foreground shrink-0" strokeWidth={1.5} />
            <div>
              <div className="flex items-center gap-2">
                <span className="font-medium">Human Only</span>
                <Badge variant="outline">
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

      {/* Sections */}
      <div className="space-y-8">
        <BasicInfoSection
          formData={formData}
          handleChange={handleChange}
          isEditMode={isEditMode}
        />

        {formData.platform === 'line' && (
          <LineCredentialsSection
            formData={formData}
            handleChange={handleChange}
            isEditMode={isEditMode}
          />
        )}

        {formData.platform === 'telegram' && (
          <TelegramCredentialsSection
            formData={formData}
            handleChange={handleChange}
            isEditMode={isEditMode}
            existingBot={existingBot}
          />
        )}

        <AIModelsSection formData={formData} handleChange={handleChange} />

        <AdvancedOptionsSection formData={formData} handleChange={handleChange} />
      </div>

      {/* Sticky action bar */}
      <StickyActionBar>
        {isEditMode ? (
          <AlertDialog>
            <AlertDialogTrigger asChild>
              <Button
                variant="ghost"
                className="text-destructive hover:text-destructive hover:bg-destructive/10 transition-colors duration-150"
                disabled={isDeleting}
              >
                {isDeleting ? (
                  <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                ) : (
                  <Trash2 className="h-4 w-4 mr-2" strokeWidth={1.5} />
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
          className="min-w-[180px] transition-colors duration-150"
        >
          {isSaving ? (
            <>
              <Loader2 className="h-4 w-4 mr-2 animate-spin" />
              {isEditMode ? 'กำลังบันทึก...' : 'กำลังสร้าง...'}
            </>
          ) : (
            <>
              <Zap className="h-4 w-4 mr-2" strokeWidth={1.5} />
              {isEditMode ? 'บันทึกการเปลี่ยนแปลง' : 'สร้างการเชื่อมต่อ'}
            </>
          )}
        </Button>
      </StickyActionBar>
    </div>
  );
}
