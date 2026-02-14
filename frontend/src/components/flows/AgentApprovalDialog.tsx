import { useState, useEffect, useCallback } from 'react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { approveAgentAction, rejectAgentAction } from '@/lib/api';
import { Loader2, ShieldAlert, Clock } from 'lucide-react';

export interface AgentApprovalData {
  approval_id: string;
  tool_name: string;
  tool_args: Record<string, unknown>;
  timeout_seconds: number;
}

interface AgentApprovalDialogProps {
  open: boolean;
  approvalId: string;
  toolName: string;
  toolArgs: Record<string, unknown>;
  timeoutSeconds: number;
  onClose: () => void;
}

export function AgentApprovalDialog({
  open,
  approvalId,
  toolName,
  toolArgs,
  timeoutSeconds,
  onClose,
}: AgentApprovalDialogProps) {
  const [remaining, setRemaining] = useState(timeoutSeconds);
  const [isLoading, setIsLoading] = useState(false);
  const [action, setAction] = useState<'approve' | 'reject' | null>(null);

  // Countdown timer
  useEffect(() => {
    if (!open) return;
    setRemaining(timeoutSeconds);

    const interval = setInterval(() => {
      setRemaining((prev) => {
        if (prev <= 1) {
          clearInterval(interval);
          return 0;
        }
        return prev - 1;
      });
    }, 1000);

    return () => clearInterval(interval);
  }, [open, timeoutSeconds]);

  // Auto-reject when countdown reaches 0
  useEffect(() => {
    if (remaining === 0 && open && !isLoading) {
      handleReject();
    }
  }, [remaining, open, isLoading]);

  const handleApprove = useCallback(async () => {
    setIsLoading(true);
    setAction('approve');
    try {
      await approveAgentAction(approvalId);
      onClose();
    } catch (err) {
      console.error('Failed to approve agent action:', err);
    } finally {
      setIsLoading(false);
      setAction(null);
    }
  }, [approvalId, onClose]);

  const handleReject = useCallback(async () => {
    setIsLoading(true);
    setAction('reject');
    try {
      await rejectAgentAction(approvalId, remaining === 0 ? 'Timed out' : undefined);
      onClose();
    } catch (err) {
      console.error('Failed to reject agent action:', err);
    } finally {
      setIsLoading(false);
      setAction(null);
    }
  }, [approvalId, remaining, onClose]);

  const progressPercent = timeoutSeconds > 0 ? (remaining / timeoutSeconds) * 100 : 0;

  return (
    <Dialog open={open} onOpenChange={(isOpen) => { if (!isOpen && !isLoading) onClose(); }}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <div className="p-2 rounded-lg bg-amber-500/10">
              <ShieldAlert className="h-5 w-5 text-amber-500" />
            </div>
            <div>
              <DialogTitle>Agent Approval Required</DialogTitle>
              <DialogDescription>
                AI ต้องการดำเนินการที่ต้องได้รับอนุมัติ
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <div className="space-y-4 py-2">
          {/* Tool Name */}
          <div>
            <span className="text-sm font-medium text-muted-foreground">Tool</span>
            <p className="text-sm font-mono mt-1 bg-muted px-3 py-2 rounded-md">{toolName}</p>
          </div>

          {/* Tool Arguments */}
          <div>
            <span className="text-sm font-medium text-muted-foreground">Arguments</span>
            <pre className="text-xs font-mono mt-1 bg-muted px-3 py-2 rounded-md overflow-auto max-h-40">
              {JSON.stringify(toolArgs, null, 2)}
            </pre>
          </div>

          {/* Countdown Timer */}
          <div className="space-y-2">
            <div className="flex items-center justify-between text-sm">
              <div className="flex items-center gap-1.5 text-muted-foreground">
                <Clock className="h-3.5 w-3.5" />
                <span>Auto-reject in</span>
              </div>
              <span className={`font-mono font-medium ${remaining <= 10 ? 'text-destructive' : ''}`}>
                {remaining}s
              </span>
            </div>
            <div className="h-1.5 bg-muted rounded-full overflow-hidden">
              <div
                className={`h-full rounded-full transition-all duration-1000 ease-linear ${
                  remaining <= 10 ? 'bg-destructive' : 'bg-amber-500'
                }`}
                style={{ width: `${progressPercent}%` }}
              />
            </div>
          </div>
        </div>

        <DialogFooter className="gap-2 sm:gap-0">
          <Button
            variant="destructive"
            onClick={handleReject}
            disabled={isLoading}
          >
            {isLoading && action === 'reject' && <Loader2 className="h-4 w-4 animate-spin" />}
            Reject
          </Button>
          <Button
            variant="default"
            onClick={handleApprove}
            disabled={isLoading}
            className="bg-green-600 hover:bg-green-700 text-white"
          >
            {isLoading && action === 'approve' && <Loader2 className="h-4 w-4 animate-spin" />}
            Approve
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
