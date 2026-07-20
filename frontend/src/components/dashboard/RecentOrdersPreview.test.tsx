import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { RecentOrdersPreview } from './RecentOrdersPreview';

vi.mock('@/hooks/useOrders', () => ({
  useOrders: () => ({
    isLoading: false,
    data: {
      orders: [
        {
          id: 1,
          created_at: '2026-07-19T10:00:00Z',
          customer_profile: { display_name: 'คุณเอ' },
          items: [{ product_name: 'BM50', quantity: 1 }],
          total_amount: 450,
          status: 'completed',
        },
      ],
    },
  }),
}));

describe('RecentOrdersPreview responsive layouts', () => {
  it('แสดงออเดอร์ทั้งใน card list (มือถือ) และตาราง (เดสก์ท็อป)', () => {
    render(
      <MemoryRouter>
        <RecentOrdersPreview />
      </MemoryRouter>,
    );
    // ชื่อลูกค้าต้องปรากฏ 2 ที่: mobile card + desktop table
    expect(screen.getAllByText('คุณเอ')).toHaveLength(2);
    expect(screen.getAllByText('สำเร็จ')).toHaveLength(2);
  });
});
