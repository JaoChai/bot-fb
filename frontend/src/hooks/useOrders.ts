import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { buildFilterParams } from '@/lib/params';
import { useAuthStore } from '@/stores/authStore';
import type {
  OrderSummaryData,
  Order,
  CustomerOrderBreakdown,
  ProductOrderBreakdown,
  OrderFilters,
  PaginationMeta,
} from '@/types/api';

interface OrderSummaryResponse {
  data: OrderSummaryData;
}

interface OrdersResponse {
  data: Order[];
  meta: PaginationMeta;
}

interface CustomerBreakdownResponse {
  data: CustomerOrderBreakdown[];
}

interface ProductBreakdownResponse {
  data: ProductOrderBreakdown[];
}

interface OrderResponse {
  data: Order;
}

/**
 * Hook to fetch order summary with time series data
 */
export function useOrderSummary(filters: OrderFilters = {}, options?: { enabled?: boolean }) {
  const { user } = useAuthStore();

  return useQuery({
    queryKey: ['orders', 'summary', filters],
    queryFn: async () => {
      const params = buildFilterParams({
        bot_id: filters.bot_id,
        start_date: filters.start_date,
        end_date: filters.end_date,
        status: filters.status,
        category: filters.category,
      });

      const queryString = params.toString();
      const url = queryString ? `/orders/summary?${queryString}` : '/orders/summary';

      const response = await api.get<OrderSummaryResponse>(url);
      return response.data.data;
    },
    staleTime: 5 * 60 * 1000,
    enabled: !!user && options?.enabled !== false,
  });
}

/**
 * Hook to fetch paginated orders list
 */
export function useOrders(filters: OrderFilters = {}, options?: { enabled?: boolean }) {
  const { user } = useAuthStore();

  return useQuery({
    queryKey: ['orders', 'list', filters],
    queryFn: async () => {
      const params = buildFilterParams({
        bot_id: filters.bot_id,
        start_date: filters.start_date,
        end_date: filters.end_date,
        status: filters.status,
        category: filters.category,
        customer_profile_id: filters.customer_profile_id,
        search: filters.search,
        page: filters.page,
        per_page: filters.per_page,
      });

      const queryString = params.toString();
      const url = queryString ? `/orders?${queryString}` : '/orders';

      const response = await api.get<OrdersResponse>(url);
      return {
        orders: response.data.data,
        meta: response.data.meta,
      };
    },
    staleTime: 5 * 60 * 1000,
    enabled: !!user && options?.enabled !== false,
  });
}

/**
 * Hook to fetch orders grouped by customer
 */
export function useOrdersByCustomer(filters: OrderFilters = {}, options?: { enabled?: boolean }) {
  const { user } = useAuthStore();

  return useQuery({
    queryKey: ['orders', 'by-customer', filters],
    queryFn: async () => {
      const params = buildFilterParams({
        bot_id: filters.bot_id,
        start_date: filters.start_date,
        end_date: filters.end_date,
        status: filters.status,
      });

      const queryString = params.toString();
      const url = queryString ? `/orders/by-customer?${queryString}` : '/orders/by-customer';

      const response = await api.get<CustomerBreakdownResponse>(url);
      return response.data.data;
    },
    staleTime: 5 * 60 * 1000,
    enabled: !!user && options?.enabled !== false,
  });
}

/**
 * Hook to fetch orders grouped by product
 */
export function useOrdersByProduct(filters: OrderFilters = {}, options?: { enabled?: boolean }) {
  const { user } = useAuthStore();

  return useQuery({
    queryKey: ['orders', 'by-product', filters],
    queryFn: async () => {
      const params = buildFilterParams({
        bot_id: filters.bot_id,
        start_date: filters.start_date,
        end_date: filters.end_date,
        status: filters.status,
        category: filters.category,
      });

      const queryString = params.toString();
      const url = queryString ? `/orders/by-product?${queryString}` : '/orders/by-product';

      const response = await api.get<ProductBreakdownResponse>(url);
      return response.data.data;
    },
    staleTime: 5 * 60 * 1000,
    enabled: !!user && options?.enabled !== false,
  });
}

/**
 * Hook to update an order (status, notes, etc.)
 */
export function useUpdateOrder() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ id, data }: { id: number; data: Partial<Order> }) => {
      const response = await api.put<OrderResponse>(`/orders/${id}`, data);
      return response.data.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['orders'] });
    },
  });
}
