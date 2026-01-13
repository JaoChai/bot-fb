import { useState, useEffect } from 'react';
import { Link } from 'react-router';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Loader2, CheckCircle2, XCircle, AlertCircle, Settings, ExternalLink } from 'lucide-react';
import { useQAInspectorSettings, useToggleQAInspector } from '@/hooks/useQAInspector';
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
      onError: () => {
        // Rollback local state on error
        setLocalEnabled(!checked);
      },
    });
  };

  if (isLoading) {
    return (
      <div className={cn('flex items-center gap-2', className)}>
        <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
        <span className="text-sm text-muted-foreground">Loading...</span>
      </div>
    );
  }

  if (isError) {
    return (
      <div className={cn('flex items-center gap-2 text-destructive', className)}>
        <XCircle className="h-4 w-4" />
        <span className="text-sm">
          {(error as { message?: string })?.message || 'Failed to load settings'}
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
            QA Inspector
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
              Enabled
            </span>
          ) : (
            'Disabled'
          )}
        </Badge>
      </div>

      {showDescription && (
        <p className="text-sm text-muted-foreground">
          {localEnabled
            ? 'QA Inspector is actively monitoring bot responses and creating evaluation logs.'
            : 'Enable to automatically evaluate bot response quality with AI-powered analysis.'}
        </p>
      )}

      {/* Link to full QA Inspector page when enabled */}
      {localEnabled && (
        <div className="pt-2 flex flex-wrap gap-2">
          <Button variant="outline" size="sm" asChild>
            <Link to={`/bots/${botId}/qa-inspector`}>
              <ExternalLink className="h-4 w-4 mr-2" />
              View Dashboard
            </Link>
          </Button>
          <Button variant="ghost" size="sm" asChild>
            <Link to={`/bots/${botId}/qa-inspector?tab=settings`}>
              <Settings className="h-4 w-4 mr-2" />
              Configure Models
            </Link>
          </Button>
        </div>
      )}

      {toggleMutation.isError && (
        <div className="flex items-center gap-2 text-sm text-destructive">
          <AlertCircle className="h-4 w-4" />
          <span>
            {(toggleMutation.error as { message?: string })?.message || 'Failed to update setting'}
          </span>
        </div>
      )}
    </div>
  );
}

export default QAInspectorToggle;
