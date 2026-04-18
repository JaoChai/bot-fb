import type { ReactNode } from 'react';
import { Search } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

interface ToolbarProps {
  search?: string;
  onSearchChange?: (v: string) => void;
  searchPlaceholder?: string;
  filters?: ReactNode;
  actions?: ReactNode;
  className?: string;
}

export function Toolbar({
  search,
  onSearchChange,
  searchPlaceholder = 'ค้นหา...',
  filters,
  actions,
  className,
}: ToolbarProps) {
  return (
    <div className={cn('flex flex-wrap items-center gap-2', className)}>
      {onSearchChange && (
        <div className="relative flex-1 min-w-[200px] max-w-sm">
          <Search
            className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground"
            strokeWidth={1.5}
          />
          <Input
            value={search ?? ''}
            onChange={(e) => onSearchChange(e.target.value)}
            placeholder={searchPlaceholder}
            className="pl-9"
          />
        </div>
      )}
      {filters && <div className="flex items-center gap-2">{filters}</div>}
      {actions && <div className="ml-auto flex items-center gap-2">{actions}</div>}
    </div>
  );
}
