/**
 * Clear Context Dialog component
 * Extracted from ChatWindow.tsx
 */
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
import { Button } from '@/components/ui/button';
import { Loader2, RotateCcw } from 'lucide-react';

interface ClearContextDialogProps {
  onClearContext: () => Promise<void>;
  isPending: boolean;
}

export function ClearContextDialog({
  onClearContext,
  isPending,
}: ClearContextDialogProps) {
  return (
    <AlertDialog>
      <AlertDialogTrigger asChild>
        <Button
          variant="outline"
          size="icon"
          disabled={isPending}
          title="Reset bot context"
        >
          {isPending ? (
            <Loader2 className="h-4 w-4 animate-spin" />
          ) : (
            <RotateCcw className="h-4 w-4" />
          )}
        </Button>
      </AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>Reset bot context?</AlertDialogTitle>
          <AlertDialogDescription>
            Bot will start with a new context. You can still view the history.
          </AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel>Cancel</AlertDialogCancel>
          <AlertDialogAction onClick={onClearContext}>
            Reset Context
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
