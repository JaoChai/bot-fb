import { BotPicker } from '@/components/common';
import { Button } from '@/components/ui/button';
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
import { Loader2, RotateCcw } from 'lucide-react';

interface BotSelectorPanelProps {
  bots: Array<{ id: number; name: string }>;
  botId: number;
  onBotSelect: (value: string) => void;
  onClearContextAll: () => void;
  isClearPending: boolean;
}

export function BotSelectorPanel({
  bots,
  botId,
  onBotSelect,
  onClearContextAll,
  isClearPending,
}: BotSelectorPanelProps) {
  return (
    <div className="p-3 border-b bg-muted/30">
      <BotPicker
        bots={bots}
        value={botId}
        onChange={onBotSelect}
        showIcon={false}
      />

      <AlertDialog>
        <AlertDialogTrigger asChild>
          <Button
            variant="outline"
            size="sm"
            className="w-full mt-2"
            disabled={isClearPending || !botId}
          >
            {isClearPending ? (
              <Loader2 className="h-4 w-4 mr-2 animate-spin" />
            ) : (
              <RotateCcw className="h-4 w-4 mr-2" strokeWidth={1.5} />
            )}
            Reset All Contexts
          </Button>
        </AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Reset all contexts?</AlertDialogTitle>
            <AlertDialogDescription>
              Bot will start fresh with all open conversations.
              Chat history will be preserved but bot will not reference previous messages.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={onClearContextAll}>
              Reset All
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
