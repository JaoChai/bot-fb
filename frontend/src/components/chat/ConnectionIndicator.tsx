import { useConnectionStore } from '@/stores/connectionStore';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export function ConnectionIndicator() {
  const isConnected = useConnectionStore((s) => s.isConnected);

  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="flex items-center gap-1.5 text-xs text-muted-foreground cursor-default">
            <span
              data-testid="connection-dot"
              className={cn(
                'h-2 w-2 rounded-full transition-colors',
                isConnected ? 'bg-green-500' : 'bg-red-500 animate-pulse'
              )}
            />
          </span>
        </TooltipTrigger>
        <TooltipContent side="bottom">
          {isConnected ? 'เชื่อมต่อแล้ว — ข้อมูลอัพเดทอัตโนมัติ' : 'ขาดการเชื่อมต่อ — กำลังเชื่อมใหม่...'}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  );
}
