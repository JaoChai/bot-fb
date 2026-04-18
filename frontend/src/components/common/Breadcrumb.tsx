import { Fragment } from 'react';
import { Link } from 'react-router';
import { ChevronRight } from 'lucide-react';
import { cn } from '@/lib/utils';

export interface BreadcrumbItem {
  label: string;
  to?: string;
}

interface BreadcrumbProps {
  items: BreadcrumbItem[];
  className?: string;
}

export function Breadcrumb({ items, className }: BreadcrumbProps) {
  return (
    <nav aria-label="breadcrumb" className={cn('flex items-center gap-1 text-sm', className)}>
      {items.map((item, i) => {
        const isLast = i === items.length - 1;
        return (
          <Fragment key={i}>
            {item.to && !isLast ? (
              <Link
                to={item.to}
                className="text-muted-foreground transition-colors hover:text-foreground"
              >
                {item.label}
              </Link>
            ) : (
              <span
                className={cn(
                  isLast ? 'text-foreground font-medium' : 'text-muted-foreground',
                )}
              >
                {item.label}
              </span>
            )}
            {!isLast && (
              <ChevronRight
                className="h-3.5 w-3.5 text-muted-foreground/60"
                strokeWidth={1.5}
              />
            )}
          </Fragment>
        );
      })}
    </nav>
  );
}
