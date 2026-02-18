import { useState, useEffect } from 'react';
import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell,
} from 'recharts';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { DashboardStatCard } from '@/components/dashboard';
import {
  useOrderSummary,
  useOrders,
  useOrdersByCustomer,
  useOrdersByProduct,
} from '@/hooks/useOrders';
import { formatBaht } from '@/lib/currency';
import type { OrderFilters } from '@/types/api';
import {
  ShoppingCart,
  TrendingUp,
  DollarSign,
  Package,
  Search,
  ChevronLeft,
  ChevronRight,
} from 'lucide-react';

const COLORS = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'];

function getStatusBadgeVariant(status: string) {
  switch (status) {
    case 'completed':
      return 'success' as const;
    case 'cancelled':
      return 'destructive' as const;
    case 'refunded':
      return 'warning' as const;
    default:
      return 'secondary' as const;
  }
}

function getStatusLabel(status: string) {
  switch (status) {
    case 'completed':
      return 'สำเร็จ';
    case 'cancelled':
      return 'ยกเลิก';
    case 'refunded':
      return 'คืนเงิน';
    default:
      return status;
  }
}

export function OrdersAnalytics() {
  const [activeTab, setActiveTab] = useState('overview');
  const [filters, setFilters] = useState<OrderFilters>({
    page: 1,
    per_page: 20,
  });
  const [searchInput, setSearchInput] = useState('');

  // Debounce search: sync searchInput → filters.search after 300ms
  useEffect(() => {
    const timer = setTimeout(() => {
      setFilters((prev) => ({
        ...prev,
        search: searchInput || undefined,
        page: 1,
      }));
    }, 300);
    return () => clearTimeout(timer);
  }, [searchInput]);

  // Lazy-load: only fetch data for the active tab
  const { data: summaryData, isLoading: summaryLoading, error: summaryError } = useOrderSummary(filters);
  const { data: ordersData, isLoading: ordersLoading } = useOrders(filters, { enabled: activeTab === 'orders' });
  const { data: customersData, isLoading: customersLoading } = useOrdersByCustomer(filters, { enabled: activeTab === 'customers' });
  const { data: productsData, isLoading: productsLoading } = useOrdersByProduct(filters, { enabled: activeTab === 'overview' || activeTab === 'products' });

  if (summaryLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-muted-foreground">กำลังโหลดข้อมูล...</div>
      </div>
    );
  }

  if (summaryError) {
    return (
      <Card className="border-destructive">
        <CardContent className="py-8 text-center">
          <p className="text-destructive">เกิดข้อผิดพลาดในการโหลดข้อมูล</p>
          <Button
            variant="outline"
            className="mt-4"
            onClick={() => window.location.reload()}
          >
            ลองใหม่
          </Button>
        </CardContent>
      </Card>
    );
  }

  const summary = summaryData?.summary;
  const timeSeries = summaryData?.time_series ?? [];

  // Aggregate revenue by category from products data for pie chart
  const categoryData = productsData
    ? Object.values(
        productsData.reduce<Record<string, { category: string; total_revenue: number }>>(
          (acc, p) => {
            const cat = p.category || 'อื่นๆ';
            if (!acc[cat]) {
              acc[cat] = { category: cat, total_revenue: 0 };
            }
            acc[cat].total_revenue += p.total_revenue;
            return acc;
          },
          {}
        )
      )
    : [];

  return (
    <div className="space-y-6">
      {/* Date Range Filters */}
      <div className="flex flex-wrap items-center gap-4">
        <div className="flex items-center gap-2">
          <span className="text-sm text-muted-foreground">ตั้งแต่:</span>
          <Input
            type="date"
            className="w-[160px]"
            value={filters.start_date ?? ''}
            onChange={(e) => setFilters({ ...filters, start_date: e.target.value || undefined, page: 1 })}
          />
        </div>
        <div className="flex items-center gap-2">
          <span className="text-sm text-muted-foreground">ถึง:</span>
          <Input
            type="date"
            className="w-[160px]"
            value={filters.end_date ?? ''}
            onChange={(e) => setFilters({ ...filters, end_date: e.target.value || undefined, page: 1 })}
          />
        </div>
      </div>

      {/* Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab} className="space-y-6">
        <TabsList>
          <TabsTrigger value="overview">ภาพรวม</TabsTrigger>
          <TabsTrigger value="orders">รายการออเดอร์</TabsTrigger>
          <TabsTrigger value="customers">ลูกค้า</TabsTrigger>
          <TabsTrigger value="products">สินค้า</TabsTrigger>
        </TabsList>

        {/* Overview Tab */}
        <TabsContent value="overview" className="space-y-6">
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <DashboardStatCard
              title="ออเดอร์ทั้งหมด"
              value={summary?.total_orders ?? 0}
              description={`ยอดขายรวม ${formatBaht(summary?.total_revenue ?? 0)}`}
              icon={ShoppingCart}
            />
            <DashboardStatCard
              title="ยอดขายรวม"
              value={formatBaht(summary?.total_revenue ?? 0)}
              description={`${summary?.total_orders ?? 0} ออเดอร์`}
              icon={DollarSign}
            />
            <DashboardStatCard
              title="ออเดอร์วันนี้"
              value={summary?.today_orders ?? 0}
              description={`ยอดขาย ${formatBaht(summary?.today_revenue ?? 0)}`}
              icon={TrendingUp}
            />
            <DashboardStatCard
              title="ยอดขายเดือนนี้"
              value={formatBaht(summary?.this_month_revenue ?? 0)}
              description={`${summary?.this_month_orders ?? 0} ออเดอร์`}
              icon={Package}
            />
          </div>

          {/* Revenue Trend Chart */}
          {timeSeries.length > 0 && (
            <Card>
              <CardHeader>
                <CardTitle>แนวโน้มยอดขายรายวัน</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="h-[300px]">
                  <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={timeSeries}>
                      <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                      <XAxis
                        dataKey="date"
                        className="text-xs"
                        tick={{ fill: 'currentColor' }}
                      />
                      <YAxis
                        tickFormatter={(v) => `${formatBaht(Number(v) || 0)}`}
                        className="text-xs"
                        tick={{ fill: 'currentColor' }}
                      />
                      <Tooltip
                        formatter={(v) => [formatBaht(Number(v) || 0), 'ยอดขาย']}
                        contentStyle={{
                          backgroundColor: 'hsl(var(--card))',
                          border: '1px solid hsl(var(--border))',
                          borderRadius: '8px',
                        }}
                      />
                      <Line
                        type="monotone"
                        dataKey="revenue"
                        stroke="#3B82F6"
                        strokeWidth={2}
                        dot={{ fill: '#3B82F6', strokeWidth: 2 }}
                      />
                    </LineChart>
                  </ResponsiveContainer>
                </div>
              </CardContent>
            </Card>
          )}

          {/* Empty State */}
          {(!summary || summary.total_orders === 0) && (
            <Card>
              <CardContent className="flex flex-col items-center justify-center py-12">
                <ShoppingCart className="h-12 w-12 text-muted-foreground mb-4" />
                <h3 className="text-lg font-medium">ยังไม่มีออเดอร์</h3>
                <p className="text-sm text-muted-foreground text-center max-w-sm mt-2">
                  ออเดอร์จะแสดงที่นี่เมื่อ Bot ของคุณเริ่มรับออเดอร์จากลูกค้า
                </p>
              </CardContent>
            </Card>
          )}
        </TabsContent>

        {/* Orders List Tab */}
        <TabsContent value="orders" className="space-y-6">
          {/* Filters */}
          <div className="flex flex-wrap items-center gap-4">
            <Select
              value={filters.status ?? 'all'}
              onValueChange={(value) =>
                setFilters({ ...filters, status: value === 'all' ? undefined : value, page: 1 })
              }
            >
              <SelectTrigger className="w-[160px]">
                <SelectValue placeholder="สถานะ" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">ทั้งหมด</SelectItem>
                <SelectItem value="completed">สำเร็จ</SelectItem>
                <SelectItem value="cancelled">ยกเลิก</SelectItem>
                <SelectItem value="refunded">คืนเงิน</SelectItem>
              </SelectContent>
            </Select>

            <Select
              value={filters.category ?? 'all'}
              onValueChange={(value) =>
                setFilters({ ...filters, category: value === 'all' ? undefined : value, page: 1 })
              }
            >
              <SelectTrigger className="w-[160px]">
                <SelectValue placeholder="หมวดหมู่" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">ทุกหมวดหมู่</SelectItem>
                <SelectItem value="nolimit">Nolimit</SelectItem>
                <SelectItem value="page">Page</SelectItem>
                <SelectItem value="g3d">G3D</SelectItem>
              </SelectContent>
            </Select>

            <div className="relative flex-1 max-w-sm">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="ค้นหาสินค้า..."
                className="pl-9"
                value={searchInput}
                onChange={(e) => setSearchInput(e.target.value)}
              />
            </div>
          </div>

          {/* Orders Table */}
          {ordersLoading ? (
            <div className="flex items-center justify-center py-12">
              <div className="text-muted-foreground">กำลังโหลด...</div>
            </div>
          ) : ordersData && ordersData.orders.length > 0 ? (
            <>
              <Card>
                <CardContent className="p-0">
                  <div className="overflow-x-auto">
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>วันที่</TableHead>
                          <TableHead>ลูกค้า</TableHead>
                          <TableHead>สินค้า</TableHead>
                          <TableHead className="text-right">จำนวนเงิน</TableHead>
                          <TableHead>สถานะ</TableHead>
                          <TableHead>หมายเหตุ</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {ordersData.orders.map((order) => (
                          <TableRow key={order.id}>
                            <TableCell className="whitespace-nowrap">
                              {new Date(order.created_at).toLocaleDateString('th-TH', {
                                day: '2-digit',
                                month: 'short',
                                year: 'numeric',
                              })}
                            </TableCell>
                            <TableCell>
                              {order.customer_profile?.display_name ?? '-'}
                            </TableCell>
                            <TableCell>
                              {order.items.length > 0
                                ? order.items.map((item) => `${item.product_name} x${item.quantity}`).join(', ')
                                : '-'}
                            </TableCell>
                            <TableCell className="text-right font-medium">
                              {formatBaht(order.total_amount)}
                            </TableCell>
                            <TableCell>
                              <Badge variant={getStatusBadgeVariant(order.status)}>
                                {getStatusLabel(order.status)}
                              </Badge>
                            </TableCell>
                            <TableCell className="max-w-[200px] truncate">
                              {order.notes ?? '-'}
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                </CardContent>
              </Card>

              {/* Pagination */}
              {ordersData.meta && ordersData.meta.last_page > 1 && (
                <div className="flex items-center justify-between">
                  <p className="text-sm text-muted-foreground">
                    หน้า {ordersData.meta.current_page} จาก {ordersData.meta.last_page} ({ordersData.meta.total} รายการ)
                  </p>
                  <div className="flex items-center gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={ordersData.meta.current_page <= 1}
                      onClick={() => setFilters({ ...filters, page: (filters.page ?? 1) - 1 })}
                    >
                      <ChevronLeft className="h-4 w-4" />
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={ordersData.meta.current_page >= ordersData.meta.last_page}
                      onClick={() => setFilters({ ...filters, page: (filters.page ?? 1) + 1 })}
                    >
                      <ChevronRight className="h-4 w-4" />
                    </Button>
                  </div>
                </div>
              )}
            </>
          ) : (
            <Card>
              <CardContent className="flex flex-col items-center justify-center py-12">
                <ShoppingCart className="h-12 w-12 text-muted-foreground mb-4" />
                <h3 className="text-lg font-medium">ไม่พบออเดอร์</h3>
                <p className="text-sm text-muted-foreground text-center max-w-sm mt-2">
                  ลองปรับตัวกรองเพื่อดูผลลัพธ์ที่ต้องการ
                </p>
              </CardContent>
            </Card>
          )}
        </TabsContent>

        {/* Customers Tab */}
        <TabsContent value="customers" className="space-y-6">
          {customersLoading ? (
            <div className="flex items-center justify-center py-12">
              <div className="text-muted-foreground">กำลังโหลด...</div>
            </div>
          ) : customersData && customersData.length > 0 ? (
            <Card>
              <CardContent className="p-0">
                <div className="overflow-x-auto">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>ลูกค้า</TableHead>
                        <TableHead className="text-right">จำนวนออเดอร์</TableHead>
                        <TableHead className="text-right">ยอดซื้อรวม</TableHead>
                        <TableHead>สั่งล่าสุด</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {customersData.map((customer) => (
                        <TableRow key={customer.customer_profile_id}>
                          <TableCell className="font-medium">
                            {customer.customer_name}
                          </TableCell>
                          <TableCell className="text-right">
                            {customer.order_count}
                          </TableCell>
                          <TableCell className="text-right font-medium">
                            {formatBaht(customer.total_spent)}
                          </TableCell>
                          <TableCell className="whitespace-nowrap">
                            {new Date(customer.last_order_at).toLocaleDateString('th-TH', {
                              day: '2-digit',
                              month: 'short',
                              year: 'numeric',
                            })}
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>
          ) : (
            <Card>
              <CardContent className="flex flex-col items-center justify-center py-12">
                <ShoppingCart className="h-12 w-12 text-muted-foreground mb-4" />
                <h3 className="text-lg font-medium">ยังไม่มีข้อมูลลูกค้า</h3>
              </CardContent>
            </Card>
          )}
        </TabsContent>

        {/* Products Tab */}
        <TabsContent value="products" className="space-y-6">
          {productsLoading ? (
            <div className="flex items-center justify-center py-12">
              <div className="text-muted-foreground">กำลังโหลด...</div>
            </div>
          ) : productsData && productsData.length > 0 ? (
            <>
              <Card>
                <CardContent className="p-0">
                  <div className="overflow-x-auto">
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>สินค้า</TableHead>
                          <TableHead>หมวดหมู่</TableHead>
                          <TableHead className="text-right">จำนวนขาย</TableHead>
                          <TableHead className="text-right">ยอดขาย</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {productsData.map((product, index) => (
                          <TableRow key={index}>
                            <TableCell className="font-medium">
                              {product.product_name}
                            </TableCell>
                            <TableCell>
                              <Badge variant="outline">{product.category || 'อื่นๆ'}</Badge>
                            </TableCell>
                            <TableCell className="text-right">
                              {product.quantity_sold}
                            </TableCell>
                            <TableCell className="text-right font-medium">
                              {formatBaht(product.total_revenue)}
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                </CardContent>
              </Card>

              {/* Revenue by Category Pie Chart */}
              {categoryData.length > 0 && (
                <Card>
                  <CardHeader>
                    <CardTitle>ยอดขายตามหมวดหมู่</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="h-[300px]">
                      <ResponsiveContainer width="100%" height="100%">
                        <PieChart>
                          <Pie
                            data={categoryData}
                            dataKey="total_revenue"
                            nameKey="category"
                            cx="50%"
                            cy="50%"
                            outerRadius={100}
                            label={({ percent }) => {
                              const pct = (percent || 0) * 100;
                              if (pct < 5) return null;
                              return `${pct.toFixed(0)}%`;
                            }}
                            labelLine={false}
                          >
                            {categoryData.map((_, index) => (
                              <Cell
                                key={`cell-${index}`}
                                fill={COLORS[index % COLORS.length]}
                              />
                            ))}
                          </Pie>
                          <Tooltip
                            formatter={(v) => [formatBaht(Number(v) || 0), 'ยอดขาย']}
                            contentStyle={{
                              backgroundColor: 'hsl(var(--card))',
                              border: '1px solid hsl(var(--border))',
                              borderRadius: '8px',
                            }}
                          />
                        </PieChart>
                      </ResponsiveContainer>
                    </div>
                  </CardContent>
                </Card>
              )}
            </>
          ) : (
            <Card>
              <CardContent className="flex flex-col items-center justify-center py-12">
                <Package className="h-12 w-12 text-muted-foreground mb-4" />
                <h3 className="text-lg font-medium">ยังไม่มีข้อมูลสินค้า</h3>
              </CardContent>
            </Card>
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
}
