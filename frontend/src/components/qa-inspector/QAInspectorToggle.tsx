import { useState, useEffect } from 'react';
import { Link } from 'react-router';
import { toast } from 'sonner';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Loader2, CheckCircle2, XCircle, AlertCircle, Settings, ExternalLink } from 'lucide-react';
import { useQAInspectorSettings, useToggleQAInspector, useBotSettingsSync } from '@/hooks/useQAInspector';
import { cn } from '@/lib/utils';

interface QAInspectorToggleProps {
  botId: number;
  className?: string;
  showDescription?: boolean;
}

export function QAInspectorToggle({
  botId,
  className,
  showDescription = true,
}: QAInspectorToggleProps) {
  const { data: settings, isLoading, isError, error } = useQAInspectorSettings(botId);
  const toggleMutation = useToggleQAInspector(botId);

  // Enable realtime sync for multi-tab support
  useBotSettingsSync(botId);

  // Local state for immediate UI feedback
  const [localEnabled, setLocalEnabled] = useState<boolean>(false);

  // Sync local state with server data
  useEffect(() => {
    if (settings?.qa_inspector_enabled !== undefined) {
      setLocalEnabled(settings.qa_inspector_enabled);
    }
  }, [settings?.qa_inspector_enabled]);

  const handleToggle = (checked: boolean) => {
    // Update local state immediately for instant feedback
    setLocalEnabled(checked);

    // Send mutation to server
    toggleMutation.mutate(checked, {
      onSuccess: () => {
        toast.success(
          checked ? 'เปิดใช้งานการตรวจสอบคุณภาพแล้ว' : 'ปิดการตรวจสอบคุณภาพแล้ว'
        );
      },
      onError: () => {
        // Rollback local state on error
        setLocalEnabled(!checked);
        toast.error('ไม่สามารถอัปเดตการตั้งค่าได้');
      },
    });
  };

  if (isLoading) {
    return (
      <div className={cn('flex items-center gap-2', className)}>
        <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
        <span className="text-sm text-muted-foreground">กำลังโหลด...</span>
      </div>
    );
  }

  if (isError) {
    return (
      <div className={cn('flex items-center gap-2 text-destructive', className)}>
        <XCircle className="h-4 w-4" />
        <span className="text-sm">
          {(error as { message?: string })?.message || 'ไม่สามารถโหลดการตั้งค่าได้'}
        </span>
      </div>
    );
  }

  return (
    <div className={cn('space-y-3', className)}>
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-3">
          <Switch
            id="qa-inspector-toggle"
            checked={localEnabled}
            onCheckedChange={handleToggle}
            disabled={toggleMutation.isPending}
          />
          <Label
            htmlFor="qa-inspector-toggle"
            className="text-sm font-medium cursor-pointer"
          >
            ตรวจสอบคุณภาพ
          </Label>
          {toggleMutation.isPending && (
            <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
          )}
        </div>
        <Badge
          variant={localEnabled ? 'success' : 'secondary'}
        >
          {localEnabled ? (
            <span className="flex items-center gap-1">
              <CheckCircle2 className="h-3 w-3" />
              เปิดใช้งาน
            </span>
          ) : (
            'ปิดใช้งาน'
          )}
        </Badge>
      </div>

      {showDescription && (
        <p className="text-sm text-muted-foreground">
          {localEnabled
            ? 'กำลังตรวจสอบการตอบของบอทและสร้างบันทึกการประเมินอยู่'
            : 'เปิดใช้งานเพื่อให้ AI ประเมินคุณภาพการตอบของบอทโดยอัตโนมัติ'}
        </p>
      )}

      {/* Link to full QA Inspector page when enabled */}
      {localEnabled && (
        <div className="pt-2 flex flex-wrap gap-2">
          <Button variant="outline" size="sm" asChild>
            <Link to={`/bots/${botId}/qa-inspector`}>
              <ExternalLink className="h-4 w-4 mr-2" />
              ดูแดชบอร์ด
            </Link>
          </Button>
          <Button variant="ghost" size="sm" asChild>
            <Link to={`/bots/${botId}/qa-inspector?tab=settings`}>
              <Settings className="h-4 w-4 mr-2" />
              ตั้งค่าโมเดล
            </Link>
          </Button>
        </div>
      )}

      {toggleMutation.isError && (
        <div className="flex items-center gap-2 text-sm text-destructive">
          <AlertCircle className="h-4 w-4" />
          <span>
            {(toggleMutation.error as { message?: string })?.message || 'ไม่สามารถอัปเดตการตั้งค่าได้'}
          </span>
        </div>
      )}
    </div>
  );
}

