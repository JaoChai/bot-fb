import { ShoppingCart } from 'lucide-react';
import { Link } from 'react-router';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatBaht } from '@/lib/currency';
import { useOrders } from '@/hooks/useOrders';
import type { Order } from '@/types/api';

const STATUS_VARIANTS: Record<string, 'default' | 'destructive' | 'outline' | 'secondary'> = {
  completed: 'default',
  cancelled: 'destructive',
  refunded: 'outline',
};

const STATUS_LABELS: Record<string, string> = {
  completed: 'สำเร็จ',
  cancelled: 'ยกเลิก',
  refunded: 'คืนเงิน',
};

export function RecentOrdersPreview() {
  const { data, isLoading } = useOrders({ per_page: 5 });

  if (isLoading) {
    return (
      <div className="rounded-xl border bg-card p-6 shadow-sm">
        <h3 className="text-base font-semibold">ออเดอร์ล่าสุด</h3>
        <p className="mt-4 text-sm text-muted-foreground">กำลังโหลด...</p>
      </div>
    );
  }

  const orders = data?.orders ?? [];

  if (!orders.length) {
    return (
      <div className="rounded-xl border bg-card p-6 shadow-sm">
        <h3 className="text-base font-semibold">ออเดอร์ล่าสุด</h3>
        <div className="flex flex-col items-center justify-center gap-2 py-8">
          <div className="rounded-full bg-muted p-3">
            <ShoppingCart className="h-6 w-6 text-muted-foreground" />
          </div>
          <p className="text-sm text-muted-foreground">ยังไม่มีออเดอร์</p>
        </div>
      </div>
    );
  }

  return (
    <div className="rounded-xl border bg-card p-6 shadow-sm">
      <h3 className="mb-4 text-base font-semibold">ออเดอร์ล่าสุด</h3>
      <div className="overflow-x-auto rounded-lg border">
        <Table className="text-sm">
          <TableHeader>
            <TableRow className="bg-accent/30 hover:bg-accent/30">
              <TableHead className="whitespace-nowrap font-semibold">วันที่</TableHead>
              <TableHead className="whitespace-nowrap font-semibold">ลูกค้า</TableHead>
              <TableHead className="whitespace-nowrap font-semibold">สินค้า</TableHead>
              <TableHead className="whitespace-nowrap text-right font-semibold">จำนวนเงิน</TableHead>
              <TableHead className="whitespace-nowrap font-semibold">สถานะ</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {orders.map((order: Order) => (
              <TableRow key={order.id} className="transition-colors hover:bg-accent/20">
                <TableCell className="whitespace-nowrap">
                  {new Date(order.created_at).toLocaleDateString('th-TH', {
                    day: '2-digit',
                    month: 'short',
                  })}
                </TableCell>
                <TableCell className="whitespace-nowrap font-medium">
                  {order.customer_profile?.display_name ?? '-'}
                </TableCell>
                <TableCell className="max-w-xs truncate text-muted-foreground">
                  {order.items.length > 0
                    ? order.items.map((item) => `${item.product_name} x${item.quantity}`).join(', ')
                    : '-'}
                </TableCell>
                <TableCell className="whitespace-nowrap text-right font-semibold">
                  {formatBaht(order.total_amount)}
                </TableCell>
                <TableCell className="whitespace-nowrap">
                  <Badge variant={STATUS_VARIANTS[order.status] || 'secondary'}>
                    {STATUS_LABELS[order.status] || order.status}
                  </Badge>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      <div className="mt-4 flex justify-center">
        <Link to="/orders">
          <Button variant="ghost" size="sm" className="text-xs text-primary hover:text-primary">
            ดูทั้งหมด →
          </Button>
        </Link>
      </div>
    </div>
  );
}
