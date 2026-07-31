/**
 * Clear Context Dialog component
 * Extracted from ChatWindow.tsx
 */
import { useState } from 'react';
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
  /** คุม open จากข้างนอก — ไม่ส่งจะใช้ state ในตัวเอง */
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  /** ส่ง false เมื่อจะเปิด dialog จากเมนูข้างนอกแทนปุ่มในตัว */
  showTrigger?: boolean;
}

export function ClearContextDialog({
  onClearContext,
  isPending,
  open: controlledOpen,
  onOpenChange,
  showTrigger = true,
}: ClearContextDialogProps) {
  const [internalOpen, setInternalOpen] = useState(false);
  const isControlled = controlledOpen !== undefined;
  const open = isControlled ? controlledOpen : internalOpen;
  const setOpen = isControlled ? (onOpenChange ?? (() => {})) : setInternalOpen;

  return (
    <AlertDialog open={open} onOpenChange={setOpen}>
      {showTrigger && (
        <AlertDialogTrigger asChild>
          <Button
            variant="outline"
            size="icon"
            disabled={isPending}
            title="Reset bot context"
          >
            {isPending ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <RotateCcw className="size-4" />
            )}
          </Button>
        </AlertDialogTrigger>
      )}
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
