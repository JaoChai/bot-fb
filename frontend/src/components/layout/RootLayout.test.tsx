import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RootLayout } from './RootLayout';
import { useChatStore } from '@/stores/chatStore';

// Sidebar กับ useConnectionStatus ไม่เกี่ยวกับสิ่งที่ test นี้ตรวจ และลาก
// dependency (auth, Echo) มาเต็มไปหมด — mock ทิ้งให้ test อ่านง่าย
vi.mock('./Sidebar', () => ({ Sidebar: () => <aside data-testid="sidebar" /> }));
vi.mock('@/hooks/useConnectionStatus', () => ({ useConnectionStatus: () => {} }));

function renderAt(path: string) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route element={<RootLayout />}>
            <Route path="/chat" element={<div>chat page</div>} />
            <Route path="/dashboard" element={<div>dashboard page</div>} />
          <Route path="/chat-archived" element={<div>chat archived page</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>
  );
}

describe('RootLayout', () => {
  beforeEach(() => {
    useChatStore.setState({ showMobileChat: false });
  });

  it('แสดง Header ในหน้ารายการแชท (ยังไม่ได้เปิดห้องแชท)', () => {
    useChatStore.setState({ showMobileChat: false });
    renderAt('/chat');

    expect(screen.getByRole('banner')).toBeInTheDocument();
  });

  it('ซ่อน Header เมื่อเปิดห้องแชทบนมือถือ ให้ ChatHeader ทำหน้าที่ nav แทน', () => {
    useChatStore.setState({ showMobileChat: true });
    renderAt('/chat');

    expect(screen.queryByRole('banner')).not.toBeInTheDocument();
  });

  it('ไม่ซ่อน Header ในหน้าอื่น แม้ showMobileChat ยังค้างเป็น true', () => {
    useChatStore.setState({ showMobileChat: true });
    renderAt('/dashboard');

    expect(screen.getByRole('banner')).toBeInTheDocument();
  });

  it('ไม่ใส่ padding ให้ main ในหน้าแชท เพราะหน้าแชทจัดการพื้นที่เอง', () => {
    const { container } = renderAt('/chat');
    const main = container.querySelector('main');

    expect(main).toHaveClass('overflow-hidden');
    expect(main).not.toHaveClass('p-4');
    expect(main).not.toHaveClass('md:p-6');
  });

  it('route ที่ขึ้นต้นด้วย /chat แต่ไม่ใช่หน้าแชท ต้องไม่โดนโหมดเต็มพื้นที่', () => {
    useChatStore.setState({ showMobileChat: true });
    const { container } = renderAt('/chat-archived');

    expect(screen.getByRole('banner')).toBeInTheDocument();
    expect(container.querySelector('main')).toHaveClass('p-4');
  });

  it('ยังใส่ padding ให้ main ในหน้าอื่นเหมือนเดิม', () => {
    const { container } = renderAt('/dashboard');
    const main = container.querySelector('main');

    expect(main).toHaveClass('p-4');
    expect(main).toHaveClass('md:p-6');
    expect(main).toHaveClass('overflow-auto');
  });
});
