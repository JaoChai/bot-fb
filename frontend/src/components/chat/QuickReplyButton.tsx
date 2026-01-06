import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Zap } from 'lucide-react';
import { QuickReplyList } from './QuickReplyList';
import { cn } from '@/lib/utils';
import type { QuickReply } from '@/types/quick-reply';

interface QuickReplyButtonProps {
  onSelect: (quickReply: QuickReply) => void;
  disabled?: boolean;
  variant?: 'outline' | 'ghost';
  className?: string;
}

export function QuickReplyButton({
  onSelect,
  disabled,
  variant = 'outline',
  className
}: QuickReplyButtonProps) {
  const [open, setOpen] = useState(false);

  const handleSelect = (quickReply: QuickReply) => {
    onSelect(quickReply);
    setOpen(false);
  };

  return (
    <DropdownMenu open={open} onOpenChange={setOpen}>
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant={variant}
          size="icon"
          className={cn("h-11 w-11 flex-shrink-0", className)}
          disabled={disabled}
          title="Quick Reply"
        >
          <Zap className="h-5 w-5" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent
        className="w-80 p-0"
        align="start"
        side="top"
        sideOffset={8}
      >
        <QuickReplyList onSelect={handleSelect} />
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
