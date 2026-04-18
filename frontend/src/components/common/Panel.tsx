import type { ReactNode, ElementType } from 'react';
import { cn } from '@/lib/utils';

interface PanelProps {
  title?: string;
  description?: string;
  icon?: ElementType;
  actions?: ReactNode;
  children: ReactNode;
  tone?: 'default' | 'destructive';
  className?: string;
  bodyClassName?: string;
}

export function Panel({
  title,
  description,
  icon: Icon,
  actions,
  children,
  tone = 'default',
  className,
  bodyClassName,
}: PanelProps) {
  return (
    <section
      className={cn(
        'rounded-lg border bg-card',
        tone === 'destructive' && 'border-destructive/40',
        className,
      )}
    >
      {(title || actions) && (
        <header className="flex items-start justify-between gap-4 border-b px-5 py-4">
          <div className="flex items-start gap-3 min-w-0">
            {Icon && (
              <Icon
                className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0"
                strokeWidth={1.5}
              />
            )}
            <div className="min-w-0">
              {title && (
                <h2
                  className={cn(
                    'text-sm font-medium',
                    tone === 'destructive' && 'text-destructive',
                  )}
                >
                  {title}
                </h2>
              )}
              {description && (
                <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>
              )}
            </div>
          </div>
          {actions && <div className="flex items-center gap-2 shrink-0">{actions}</div>}
        </header>
      )}
      <div className={cn('px-5 py-4', bodyClassName)}>{children}</div>
    </section>
  );
}
