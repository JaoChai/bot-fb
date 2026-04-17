import { Star } from 'lucide-react';
import { cn } from '@/lib/utils';

type Variant = 'auto' | 'manual';

interface VipBadgeProps {
  variant?: Variant;
  className?: string;
  onClick?: () => void;
  tooltipContent?: string;
}

export function VipBadge({
  variant = 'auto',
  className,
  onClick,
  tooltipContent,
}: VipBadgeProps) {
  const colorClasses =
    variant === 'manual'
      ? 'bg-purple-100 text-purple-800 border-purple-300'
      : 'bg-amber-100 text-amber-800 border-amber-300';

  return (
    <button
      type="button"
      onClick={onClick}
      title={tooltipContent ?? (variant === 'manual' ? 'VIP (กำหนดเอง)' : 'VIP (อัตโนมัติ)')}
      className={cn(
        'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium',
        colorClasses,
        onClick && 'cursor-pointer hover:opacity-80',
        className,
      )}
    >
      <Star className="h-3 w-3 fill-current" />
      <span>VIP</span>
    </button>
  );
}
