import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';
import { Zap } from 'lucide-react';
import { QuickReplyList } from './QuickReplyList';
import type { QuickReply } from '@/types/quick-reply';

interface QuickReplyButtonProps {
  onSelect: (quickReply: QuickReply) => void;
  disabled?: boolean;
}

export function QuickReplyButton({ onSelect, disabled }: QuickReplyButtonProps) {
  const [open, setOpen] = useState(false);

  const handleSelect = (quickReply: QuickReply) => {
    onSelect(quickReply);
    setOpen(false);
  };

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          size="icon"
          className="h-11 w-11 flex-shrink-0"
          disabled={disabled}
          title="Quick Reply"
        >
          <Zap className="h-5 w-5" />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        className="w-80 p-0"
        align="start"
        side="top"
        sideOffset={8}
      >
        <QuickReplyList onSelect={handleSelect} />
      </PopoverContent>
    </Popover>
  );
}
