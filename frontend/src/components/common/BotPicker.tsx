import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Bot } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface BotOption {
  id: number | string;
  name: string;
}

interface BotPickerProps {
  bots: BotOption[];
  value?: string | number;
  onChange: (id: string) => void;
  placeholder?: string;
  disabled?: boolean;
  className?: string;
  showIcon?: boolean;
}

export function BotPicker({
  bots,
  value,
  onChange,
  placeholder = 'เลือกบอท',
  disabled,
  className,
  showIcon = true,
}: BotPickerProps) {
  return (
    <Select
      value={value !== undefined ? String(value) : undefined}
      onValueChange={onChange}
      disabled={disabled}
    >
      <SelectTrigger className={cn('w-full', className)}>
        {showIcon && (
          <Bot className="h-4 w-4 text-muted-foreground mr-2" strokeWidth={1.5} />
        )}
        <SelectValue placeholder={placeholder} />
      </SelectTrigger>
      <SelectContent>
        {bots.map((bot) => (
          <SelectItem key={bot.id} value={String(bot.id)}>
            {bot.name}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}
