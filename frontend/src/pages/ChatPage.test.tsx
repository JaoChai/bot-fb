import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { ChatPage } from './ChatPage';
import { useChatStore } from '@/stores/chatStore';
import type { Conversation } from '@/types/api';

// ChatPage ลาก query/websocket มาทั้งชุด — mock ให้เหลือเฉพาะสิ่งที่ test นี้ตรวจ
const conversationsRef = { current: [] as Conversation[], loading: false };

vi.mock('@/hooks/useKnowledgeBase', () => ({
  useBots: () => ({ data: { data: [{ id: 1, name: 'bot' }] }, isLoading: false }),
}));
vi.mock('@/hooks/chat', () => ({
  useInfiniteConversationList: () => ({
    data: { pages: [{ data: conversationsRef.current }] },
    isLoading: conversationsRef.loading,
    isFetching: conversationsRef.loading,
    hasNextPage: false,
    isFetchingNextPage: false,
    fetchNextPage: vi.fn(),
  }),
  useRealtime: () => {},
  useMarkAsRead: () => ({ mutate: vi.fn() }),
}));
vi.mock('@/hooks/useConversations', () => ({
  useClearContextAll: () => ({ mutate: vi.fn(), isPending: false }),
}));
vi.mock('@/hooks/use-toast', () => ({ useToast: () => ({ toast: vi.fn() }) }));
vi.mock('@/components/chat/ChatWindow', () => ({
  ChatWindow: () => <div data-testid="chat-window" />,
}));
vi.mock('@/components/chat/CustomerInfoPanel', () => ({
  CustomerInfoPanel: () => <div />,
}));

// jsdom ไม่มี matchMedia — ตอบว่าเป็นมือถือ (ไม่เข้าเงื่อนไข min-width: 768px)
// เพราะเคสที่ test นี้ตรวจเกิดบนมือถือเท่านั้น
function stubMobileViewport() {
  Object.defineProperty(window, 'matchMedia', {
    value: vi.fn().mockReturnValue({
      matches: false,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    }),
    configurable: true,
    writable: true,
  });
}

function makeConversation(id: number): Conversation {
  return {
    id,
    channel_type: 'line',
    status: 'active',
    is_handover: false,
    unread_count: 0,
    message_count: 1,
    created_at: '2026-07-31T00:00:00Z',
  } as Conversation;
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/chat?botId=1']}>
      <ChatPage />
    </MemoryRouter>
  );
}

describe('ChatPage — กันติดอยู่ในห้องแชทบนมือถือ', () => {
  beforeEach(() => {
    stubMobileViewport();
    conversationsRef.current = [];
    conversationsRef.loading = false;
    useChatStore.setState({
      selectedConversationId: null,
      showMobileChat: false,
      isCustomerPanelOpen: false,
      searchQuery: '',
    });
  });

  it('ห้องแชทที่เลือกหลุดจากรายการ -> พากลับหน้ารายการเอง', async () => {
    // เปิดห้องแชท #7 อยู่ แต่ #7 ไม่อยู่ในรายการที่โหลดมาแล้ว
    // (เกิดได้เมื่อ realtime อัพเดตแล้วมันตกนอกหน้าที่โหลดไว้)
    // ถ้าปล่อยไว้ = จอเปล่าที่ไม่มีทั้ง header ของแอปและปุ่มย้อนกลับ = ออกไปไหนไม่ได้
    conversationsRef.current = [makeConversation(9)];
    useChatStore.setState({ selectedConversationId: 7, showMobileChat: true });

    renderPage();

    await waitFor(() =>
      expect(useChatStore.getState().showMobileChat).toBe(false)
    );
  });

  it('ห้องแชทที่เลือกยังอยู่ในรายการ -> ไม่พากลับ', async () => {
    conversationsRef.current = [makeConversation(7)];
    useChatStore.setState({ selectedConversationId: 7, showMobileChat: true });

    const { findByTestId } = renderPage();

    await findByTestId('chat-window');
    expect(useChatStore.getState().showMobileChat).toBe(true);
  });

  it('ยังโหลดรายการไม่เสร็จ -> ยังไม่พากลับ (กันเด้งออกตอนโหลด)', async () => {
    conversationsRef.current = [];
    conversationsRef.loading = true;
    useChatStore.setState({ selectedConversationId: 7, showMobileChat: true });

    renderPage();

    await new Promise((r) => setTimeout(r, 50));
    expect(useChatStore.getState().showMobileChat).toBe(true);
  });
});
