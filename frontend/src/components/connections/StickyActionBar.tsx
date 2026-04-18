import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface StickyActionBarProps {
  children: ReactNode;
  className?: string;
}

export function StickyActionBar({ children, className }: StickyActionBarProps) {
  return (
    <div
      className={cn(
        'sticky bottom-0 -mx-4 md:-mx-6 mt-6 border-t bg-background/95 px-4 md:px-6 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/80 pb-safe z-10',
        className
      )}
    >
      <div className="flex items-center justify-between gap-3">{children}</div>
    </div>
  );
}
