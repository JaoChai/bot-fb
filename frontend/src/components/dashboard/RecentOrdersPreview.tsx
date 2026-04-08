import { ShoppingCart } from 'lucide-react';
import { Link } from 'react-router';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">ออเดอร์ล่าสุด</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-sm text-muted-foreground">กำลังโหลด...</p>
        </CardContent>
      </Card>
    );
  }

  const orders = data?.orders ?? [];

  if (!orders.length) {
    return (
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base">ออเดอร์ล่าสุด</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center gap-2 py-8">
            <ShoppingCart className="h-8 w-8 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">ยังไม่มีออเดอร์</p>
          </div>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-base">ออเดอร์ล่าสุด</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="overflow-x-auto">
          <Table className="text-sm">
            <TableHeader>
              <TableRow>
                <TableHead className="whitespace-nowrap">วันที่</TableHead>
                <TableHead className="whitespace-nowrap">ลูกค้า</TableHead>
                <TableHead className="whitespace-nowrap">สินค้า</TableHead>
                <TableHead className="whitespace-nowrap text-right">จำนวนเงิน</TableHead>
                <TableHead className="whitespace-nowrap">สถานะ</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {orders.map((order: Order) => (
                <TableRow key={order.id}>
                  <TableCell className="whitespace-nowrap">
                    {new Date(order.created_at).toLocaleDateString('th-TH', {
                      day: '2-digit',
                      month: 'short',
                    })}
                  </TableCell>
                  <TableCell className="whitespace-nowrap">
                    {order.customer_profile?.display_name ?? '-'}
                  </TableCell>
                  <TableCell className="max-w-xs truncate">
                    {order.items.length > 0
                      ? order.items.map((item) => `${item.product_name} x${item.quantity}`).join(', ')
                      : '-'}
                  </TableCell>
                  <TableCell className="whitespace-nowrap text-right font-medium">
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

        {/* View All Button */}
        <div className="mt-4 flex justify-center">
          <Link to="/orders">
            <Button variant="ghost" size="sm" className="text-xs">
              ดูทั้งหมด →
            </Button>
          </Link>
        </div>
      </CardContent>
    </Card>
  );
}
