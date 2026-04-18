import type { ReactNode } from 'react';
import { useNavigate } from 'react-router';
import { ArrowLeft } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Breadcrumb, type BreadcrumbItem } from '@/components/common';
import { cn } from '@/lib/utils';

interface PageHeaderProps {
  title: string;
  description?: string;
  backTo?: string | number;
  backLabel?: string;
  badge?: ReactNode;
  actions?: ReactNode;
  breadcrumb?: BreadcrumbItem[];
  meta?: ReactNode;
  className?: string;
}

export function PageHeader({
  title,
  description,
  backTo,
  backLabel = 'กลับ',
  badge,
  actions,
  breadcrumb,
  meta,
  className,
}: PageHeaderProps) {
  const navigate = useNavigate();

  const handleBack = () => {
    if (backTo === undefined) return;
    if (typeof backTo === 'number') {
      navigate(backTo);
    } else {
      navigate(backTo);
    }
  };

  return (
    <div className={cn('flex flex-col gap-2', className)}>
      {breadcrumb && <Breadcrumb items={breadcrumb} />}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex items-start gap-3 min-w-0 flex-1">
          {backTo !== undefined && (
            <Button
              variant="ghost"
              size="icon"
              onClick={handleBack}
              aria-label={backLabel}
              className="-ml-2 h-9 w-9 shrink-0 text-muted-foreground hover:text-foreground"
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
          )}
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <h1 className="text-xl sm:text-2xl font-semibold tracking-tight text-foreground">
                {title}
              </h1>
              {badge}
            </div>
            {description && (
              <p className="text-sm text-muted-foreground mt-1">{description}</p>
            )}
          </div>
        </div>
        {(actions || meta) && (
          <div className="flex flex-col items-stretch gap-1 shrink-0 sm:ml-4 sm:items-end">
            {actions && <div className="flex items-center gap-2">{actions}</div>}
            {meta && <div className="text-sm text-muted-foreground tabular-nums">{meta}</div>}
          </div>
        )}
      </div>
    </div>
  );
}
