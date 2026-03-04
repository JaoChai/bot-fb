import type { ProductOrderBreakdown } from '@/types/api';

export const CHART_COLORS = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'];

export const CHART_TOOLTIP_STYLE = {
  backgroundColor: 'hsl(var(--card))',
  border: '1px solid hsl(var(--border))',
  borderRadius: '8px',
  fontSize: '13px',
} as const;

export function groupProductsByCategory(
  products: ProductOrderBreakdown[],
): { category: string; total_revenue: number }[] {
  const grouped = products.reduce<Record<string, number>>((acc, p) => {
    const cat = p.category || 'อื่นๆ';
    acc[cat] = (acc[cat] ?? 0) + p.total_revenue;
    return acc;
  }, {});
  return Object.entries(grouped)
    .map(([category, total_revenue]) => ({ category, total_revenue }))
    .sort((a, b) => b.total_revenue - a.total_revenue);
}
