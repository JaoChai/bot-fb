import { useState } from 'react';
import {
  Card,
  CardContent,
  CardHeader,
} from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Loader2,
  CheckCircle2,
  AlertTriangle,
  Wand2,
} from 'lucide-react';
import { toast } from 'sonner';
import { useApplyPromptSuggestion } from '@/hooks/useQAInspector';
import { cn } from '@/lib/utils';
import type { QAPromptSuggestion, ApplySuggestionConflict } from '@/types/qa-inspector';

interface FlowOption {
  id: number;
  name: string;
}

interface PromptSuggestionCardProps {
  suggestion: QAPromptSuggestion;
  suggestionIndex: number;
  botId: number;
  reportId: number;
  flows: FlowOption[];
  onApplySuccess?: () => void;
}

export function PromptSuggestionCard({
  suggestion,
  suggestionIndex,
  botId,
  reportId,
  flows,
  onApplySuccess,
}: PromptSuggestionCardProps) {
  const [showConfirmDialog, setShowConfirmDialog] = useState(false);
  const [showConflictDialog, setShowConflictDialog] = useState(false);
  const [selectedFlowId, setSelectedFlowId] = useState<number | null>(
    flows.length === 1 ? flows[0].id : null
  );
  const [conflictData, setConflictData] = useState<ApplySuggestionConflict | null>(null);

  const applyMutation = useApplyPromptSuggestion(botId, reportId);

  const getPriorityVariant = (priority: number): 'destructive' | 'default' | 'secondary' => {
    if (priority === 1) return 'destructive';
    if (priority <= 3) return 'default';
    return 'secondary';
  };

  const handleApplyClick = () => {
    if (!selectedFlowId) {
      toast.error('Please select a flow to apply the suggestion to');
      return;
    }
    setShowConfirmDialog(true);
  };

  const handleConfirmApply = async () => {
    if (!selectedFlowId) return;

    setShowConfirmDialog(false);

    try {
      await applyMutation.mutateAsync({
        suggestionIndex,
        flowId: selectedFlowId,
        force: false,
      });
      toast.success('Suggestion applied successfully');
      onApplySuccess?.();
    } catch (error) {
      // Check if it's a conflict response
      const apiError = error as { conflict?: boolean; message?: string; expected?: string; actual?: string; can_force?: boolean };
      if (apiError.conflict) {
        setConflictData(apiError as ApplySuggestionConflict);
        setShowConflictDialog(true);
      } else {
        toast.error(apiError.message || 'Failed to apply suggestion');
      }
    }
  };

  const handleForceApply = async () => {
    if (!selectedFlowId) return;

    setShowConflictDialog(false);
    setConflictData(null);

    try {
      await applyMutation.mutateAsync({
        suggestionIndex,
        flowId: selectedFlowId,
        force: true,
      });
      toast.success('Suggestion force-applied successfully');
      onApplySuccess?.();
    } catch (error) {
      const apiError = error as { message?: string };
      toast.error(apiError.message || 'Failed to force apply suggestion');
    }
  };

  const isApplied = suggestion.applied;
  const isApplying = applyMutation.isPending;

  return (
    <>
      <Card className="border">
        <CardHeader className="pb-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Badge variant={getPriorityVariant(suggestion.priority)}>
                Priority #{suggestion.priority}
              </Badge>
              <span className="text-sm font-medium">
                Section: {suggestion.section}
              </span>
              {suggestion.line_range && (
                <span className="text-xs text-muted-foreground">
                  (Lines {suggestion.line_range})
                </span>
              )}
            </div>
            <div className="flex items-center gap-2">
              {isApplied ? (
                <Badge variant="default" className="bg-green-600 hover:bg-green-600">
                  <CheckCircle2 className="h-3 w-3 mr-1" />
                  Applied
                </Badge>
              ) : (
                <Badge variant="secondary">Not Applied</Badge>
              )}
            </div>
          </div>
        </CardHeader>

        <CardContent className="space-y-4">
          {/* Issue and Impact */}
          <div className="space-y-2">
            <p className="text-sm text-muted-foreground">
              <span className="font-medium text-foreground">Issue Addressed:</span>{' '}
              {suggestion.issue_addressed}
            </p>
            <p className="text-sm text-muted-foreground">
              <span className="font-medium text-foreground">Expected Impact:</span>{' '}
              {suggestion.expected_impact}
            </p>
          </div>

          {/* Before/After Diff */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <p className="text-sm font-medium text-destructive mb-2 flex items-center gap-1">
                <span className="w-3 h-3 rounded-full bg-red-500/20 flex items-center justify-center">
                  <span className="w-1.5 h-1.5 rounded-full bg-red-500" />
                </span>
                Before
              </p>
              <pre className="text-xs bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-900/30 p-3 rounded-md overflow-x-auto whitespace-pre-wrap max-h-40">
                {suggestion.before || 'N/A'}
              </pre>
            </div>
            <div>
              <p className="text-sm font-medium text-green-600 mb-2 flex items-center gap-1">
                <span className="w-3 h-3 rounded-full bg-green-500/20 flex items-center justify-center">
                  <span className="w-1.5 h-1.5 rounded-full bg-green-500" />
                </span>
                After
              </p>
              <pre className="text-xs bg-green-50 dark:bg-green-950/20 border border-green-200 dark:border-green-900/30 p-3 rounded-md overflow-x-auto whitespace-pre-wrap max-h-40">
                {suggestion.after || 'N/A'}
              </pre>
            </div>
          </div>

          {/* Apply Action */}
          <div className="flex items-center justify-between pt-2 border-t">
            <div className="flex items-center gap-3">
              {flows.length > 1 && !isApplied && (
                <Select
                  value={selectedFlowId?.toString() || ''}
                  onValueChange={(value) => setSelectedFlowId(Number(value))}
                  disabled={isApplied || isApplying}
                >
                  <SelectTrigger className="w-[200px]">
                    <SelectValue placeholder="Select flow..." />
                  </SelectTrigger>
                  <SelectContent>
                    {flows.map((flow) => (
                      <SelectItem key={flow.id} value={flow.id.toString()}>
                        {flow.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
              {flows.length === 1 && !isApplied && (
                <span className="text-sm text-muted-foreground">
                  Apply to: <span className="font-medium">{flows[0].name}</span>
                </span>
              )}
            </div>

            <div className="flex items-center gap-2">
              {isApplied && suggestion.applied_at && (
                <span className="text-xs text-muted-foreground">
                  Applied on {new Date(suggestion.applied_at).toLocaleString()}
                </span>
              )}
              {!isApplied && (
                <Button
                  onClick={handleApplyClick}
                  disabled={isApplying || !selectedFlowId}
                  size="sm"
                  className={cn(
                    'cursor-pointer',
                    isApplying && 'cursor-wait'
                  )}
                >
                  {isApplying ? (
                    <>
                      <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                      Applying...
                    </>
                  ) : (
                    <>
                      <Wand2 className="h-4 w-4 mr-2" />
                      Apply to Flow
                    </>
                  )}
                </Button>
              )}
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Confirmation Dialog */}
      <Dialog open={showConfirmDialog} onOpenChange={setShowConfirmDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Apply Prompt Suggestion</DialogTitle>
            <DialogDescription>
              This will modify the system prompt for the selected flow. Are you sure you want to apply this suggestion?
            </DialogDescription>
          </DialogHeader>
          <div className="py-4">
            <p className="text-sm mb-2">
              <span className="font-medium">Section:</span> {suggestion.section}
            </p>
            <p className="text-sm mb-2">
              <span className="font-medium">Expected Impact:</span> {suggestion.expected_impact}
            </p>
            {flows.length > 0 && selectedFlowId && (
              <p className="text-sm">
                <span className="font-medium">Target Flow:</span>{' '}
                {flows.find((f) => f.id === selectedFlowId)?.name}
              </p>
            )}
          </div>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setShowConfirmDialog(false)}
              className="cursor-pointer"
            >
              Cancel
            </Button>
            <Button
              onClick={handleConfirmApply}
              disabled={isApplying}
              className="cursor-pointer"
            >
              {isApplying ? (
                <>
                  <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                  Applying...
                </>
              ) : (
                'Apply Suggestion'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Conflict Dialog */}
      <Dialog open={showConflictDialog} onOpenChange={setShowConflictDialog}>
        <DialogContent className="max-w-3xl">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-yellow-600">
              <AlertTriangle className="h-5 w-5" />
              Prompt Conflict Detected
            </DialogTitle>
            <DialogDescription>
              {conflictData?.message || 'The prompt was modified since this report was generated.'}
            </DialogDescription>
          </DialogHeader>

          <div className="py-4 space-y-4">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <p className="text-sm font-medium text-muted-foreground mb-2">
                  Expected (from suggestion):
                </p>
                <pre className="text-xs bg-muted p-3 rounded-md overflow-x-auto whitespace-pre-wrap max-h-48 border">
                  {conflictData?.expected || 'N/A'}
                </pre>
              </div>
              <div>
                <p className="text-sm font-medium text-muted-foreground mb-2">
                  Actual (current in flow):
                </p>
                <pre className="text-xs bg-muted p-3 rounded-md overflow-x-auto whitespace-pre-wrap max-h-48 border">
                  {conflictData?.actual || 'N/A'}
                </pre>
              </div>
            </div>

            {conflictData?.can_force && (
              <p className="text-sm text-yellow-600 bg-yellow-50 dark:bg-yellow-950/20 p-3 rounded-md">
                You can force apply this suggestion, which will overwrite the current prompt content with the suggested changes.
              </p>
            )}
          </div>

          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => {
                setShowConflictDialog(false);
                setConflictData(null);
              }}
              className="cursor-pointer"
            >
              Cancel
            </Button>
            {conflictData?.can_force && (
              <Button
                variant="destructive"
                onClick={handleForceApply}
                disabled={isApplying}
                className="cursor-pointer"
              >
                {isApplying ? (
                  <>
                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                    Applying...
                  </>
                ) : (
                  'Force Apply'
                )}
              </Button>
            )}
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

export default PromptSuggestionCard;
