import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import type { ProductStock } from '@/types/api';

interface ProductStocksResponse {
  data: ProductStock[];
}

/**
 * Fetch all product stocks
 */
export function useProductStocks() {
  return useQuery({
    queryKey: queryKeys.productStocks.list(),
    queryFn: async () => {
      const { data } = await api.get<ProductStocksResponse>('/product-stocks');
      return data.data;
    },
    staleTime: 5 * 60 * 1000,
  });
}

/**
 * Toggle product stock in_stock status
 */
export function useUpdateProductStock() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async ({ slug, in_stock }: { slug: string; in_stock: boolean }) => {
      const { data } = await api.put<{ data: ProductStock }>(
        `/product-stocks/${slug}`,
        { in_stock },
      );
      return data.data;
    },
    onMutate: async ({ slug, in_stock }) => {
      await queryClient.cancelQueries({ queryKey: queryKeys.productStocks.list() });

      const previous = queryClient.getQueryData<ProductStock[]>(
        queryKeys.productStocks.list(),
      );

      // Optimistic update
      if (previous) {
        queryClient.setQueryData<ProductStock[]>(
          queryKeys.productStocks.list(),
          previous.map((p) => (p.slug === slug ? { ...p, in_stock } : p)),
        );
      }

      return { previous };
    },
    onError: (_err, _vars, context) => {
      if (context?.previous) {
        queryClient.setQueryData(queryKeys.productStocks.list(), context.previous);
      }
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.productStocks.list() });
    },
  });
}
