import type { ReactNode } from 'react';
import { AlertCircle } from 'lucide-react';
import { cn } from '@/lib/utils';

interface ErrorStateProps {
  title?: string;
  description?: string;
  action?: ReactNode;
  className?: string;
}

export function ErrorState({
  title = 'เกิดข้อผิดพลาด',
  description,
  action,
  className,
}: ErrorStateProps) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center rounded-lg border border-destructive/30 bg-destructive/5 px-6 py-10 text-center',
        className,
      )}
      role="alert"
    >
      <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-md border border-destructive/30 bg-background">
        <AlertCircle className="h-4 w-4 text-destructive" strokeWidth={1.5} />
      </div>
      <h3 className="text-sm font-medium text-destructive">{title}</h3>
      {description && <p className="mt-1 max-w-sm text-sm text-muted-foreground">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}
