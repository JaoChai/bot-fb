/**
 * F1b: /chat?botId=<b>&conversationId=<c> must open exactly that room.
 *
 * Page rules under test (card Task):
 * 1. pending -> loader `กำลังเปิดห้องแชต`, no room rendered, first-room
 *    auto-select blocked, phone list stays visible.
 * 2. success -> selectConversation(id), phone chat view opens, list copy wins
 *    over the hook copy, mark-as-read once when unread_count > 0.
 * 3. the link applies once per botId+conversationId (no re-select on refresh).
 * 4. the parameter leaves the URL (replace, botId kept) on leave.
 * 5. bad link / 404 / 403 -> `เปิดห้องแชตนี้ไม่ได้` panel, no request for a
 *    bad link.
 * 6. other failure -> `โหลดห้องแชตไม่สำเร็จ` panel with retry.
 * 7. phone: the error panel replaces the list.
 *
 * The real useLinkedConversation runs here (only its transport and the
 * realtime/list hooks are mocked), so envelope unpacking, the enabled rule and
 * the mutation invalidation subscription are exercised end to end with the page.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';
import { MemoryRouter, useLocation, useNavigate } from 'react-router';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ChatPage } from './ChatPage';
import { useChatStore } from '@/stores/chatStore';
import type { Conversation } from '@/types/api';

// Review 1 (item 1): record every ChatWindow / CustomerInfoPanel MOUNT id.
// The stored-room flash defect is visible only in mount history — by the time
// an assertion runs the link has already applied and room 77 is on screen.
const mountProbe = vi.hoisted(() => ({
  chatWindow: [] as string[],
  infoPanel: [] as string[],
}));

const listState = {
  conversations: [] as Conversation[],
  isLoading: false,
};

vi.mock('@/hooks/chat/useConversationList', () => ({
  useInfiniteConversationList: () => ({
    data: { pages: [{ data: listState.conversations }] },
    isLoading: listState.isLoading,
    isFetching: listState.isLoading,
    hasNextPage: false,
    isFetchingNextPage: false,
    fetchNextPage: vi.fn(),
  }),
}));

// Keeps the real '@/hooks/chat' barrel intact (useLinkedConversation stays real)
// while cutting the Echo/WebSocket layer.
vi.mock('@/hooks/chat/useRealtime', () => ({
  useRealtime: () => {},
}));

vi.mock('@/hooks/useKnowledgeBase', () => ({
  useBots: () => ({
    data: { data: [{ id: 1, name: 'bot' }, { id: 2, name: 'bot2' }] },
    isLoading: false,
  }),
}));

const apiGetMock = vi.fn();
const apiPostMock = vi.fn();
vi.mock('@/lib/api', () => ({
  apiGet: (...args: unknown[]) => apiGetMock(...args),
  api: { post: (...args: unknown[]) => apiPostMock(...args) },
  getErrorMessage: (error: unknown) =>
    (error as { message?: string })?.message ?? String(error),
}));

vi.mock('@/components/chat/ChatWindow', async () => {
  const { useEffect } = await import('react');
  return {
    ChatWindow: ({
      conversation,
      onBack,
    }: {
      conversation: Conversation;
      onBack?: () => void;
    }) => {
      useEffect(() => {
        mountProbe.chatWindow.push(String(conversation.id));
      }, [conversation.id]);
      return (
        <div
          data-testid="chat-window"
          data-conversation-id={conversation.id}
          data-unread={conversation.unread_count}
        >
          {onBack && (
            <button type="button" onClick={onBack}>
              mock-back
            </button>
          )}
        </div>
      );
    },
  };
});

vi.mock('@/components/chat/CustomerInfoPanel', async () => {
  const { useEffect } = await import('react');
  return {
    CustomerInfoPanel: ({ conversation }: { conversation: Conversation }) => {
      useEffect(() => {
        mountProbe.infoPanel.push(String(conversation.id));
      }, [conversation.id]);
      return <div data-testid="info-panel" data-conversation-id={conversation.id} />;
    },
  };
});

vi.mock('@/components/chat/BotSelectorPanel', () => ({
  BotSelectorPanel: ({ onBotSelect }: { onBotSelect: (value: string) => void }) => (
    <button type="button" onClick={() => onBotSelect('2')}>
      mock-bot-2
    </button>
  ),
}));

function makeRoom(
  id: number,
  displayName: string,
  overrides: Partial<Conversation> = {}
): Conversation {
  return {
    id,
    bot_id: 1,
    channel_type: 'line',
    status: 'active',
    is_handover: false,
    unread_count: 0,
    message_count: 1,
    memory_notes: null,
    tags: [],
    created_at: '2026-07-31T00:00:00Z',
    updated_at: '2026-07-31T00:00:00Z',
    customer_profile: { display_name: displayName },
    ...overrides,
  } as Conversation;
}

const room9 = () => makeRoom(9, 'E2E ลูกค้าเก้า');
const room77 = (overrides: Partial<Conversation> = {}) =>
  makeRoom(77, 'E2E ลูกค้าลิงก์', { unread_count: 1, ...overrides });

function stubViewport(desktop: boolean) {
  Object.defineProperty(window, 'matchMedia', {
    value: vi.fn().mockReturnValue({
      matches: desktop,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    }),
    configurable: true,
    writable: true,
  });
}

function LocationProbe() {
  return <div data-testid="location" data-search={useLocation().search} />;
}

function NavProbe({ to, label }: { to: string; label: string }) {
  const navigate = useNavigate();
  return (
    <button type="button" onClick={() => navigate(to)}>
      {label}
    </button>
  );
}

function renderPage(initialUrl: string, options: { navTo?: string } = {}) {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false, staleTime: 0, gcTime: 0 },
      mutations: { retry: false },
    },
  });
  const utils = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialUrl]}>
        <ChatPage />
        <LocationProbe />
        {options.navTo && <NavProbe to={options.navTo} label="mock-nav" />}
      </MemoryRouter>
    </QueryClientProvider>
  );
  return { queryClient, ...utils };
}

const listPanel = () => screen.getByTestId('chat-list-panel');
const centerPanel = () => screen.getByTestId('chat-center-panel');
const search = () => screen.getByTestId('location').getAttribute('data-search');

describe('ChatPage room link — pending', () => {
  beforeEach(() => {
    stubViewport(false); // phone
    apiGetMock.mockReset();
    apiPostMock.mockReset();
    listState.conversations = [];
    listState.isLoading = false;
    useChatStore.setState({
      selectedConversationId: null,
      showMobileChat: false,
      isCustomerPanelOpen: false,
      searchQuery: '',
    });
  });

  it('shows the link loader and renders no room — not even the stored selection — while the room request is pending', () => {
    listState.conversations = [room9()];
    useChatStore.setState({ selectedConversationId: 9, showMobileChat: true });
    apiGetMock.mockReturnValue(new Promise(() => {}));

    renderPage('/chat?botId=1&conversationId=77');

    expect(screen.getByText('กำลังเปิดห้องแชต')).toBeInTheDocument();
    expect(screen.queryByTestId('chat-window')).not.toBeInTheDocument();
    expect(screen.queryByTestId('info-panel')).not.toBeInTheDocument();
    // phone: the list stays visible while pending
    expect(listPanel().classList.contains('hidden')).toBe(false);
  });

  it('blocks the desktop first-room auto-select while the link is pending', async () => {
    stubViewport(true); // desktop
    listState.conversations = [room9()];
    apiGetMock.mockReturnValue(new Promise(() => {}));

    renderPage('/chat?botId=1&conversationId=77');

    expect(screen.getByText('กำลังเปิดห้องแชต')).toBeInTheDocument();
    // 'hidden md:flex' hides below md only — the md:flex lift means the
    // desktop centre panel stays visible (real visibility is e2e-verified)
    expect(centerPanel().classList.contains('md:flex')).toBe(true);
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(useChatStore.getState().selectedConversationId).toBeNull();
    expect(screen.queryByTestId('chat-window')).not.toBeInTheDocument();
  });
});

describe('ChatPage room link — success', () => {
  beforeEach(() => {
    stubViewport(false); // phone
    apiGetMock.mockReset();
    apiPostMock.mockReset();
    listState.conversations = [];
    listState.isLoading = false;
    useChatStore.setState({
      selectedConversationId: null,
      showMobileChat: false,
      isCustomerPanelOpen: false,
      searchQuery: '',
    });
  });

  it('selects the linked room from the hook, opens the chat view on phone and marks it read once', async () => {
    apiGetMock.mockResolvedValue({ data: room77() });
    apiPostMock.mockResolvedValue({ data: room77({ unread_count: 0 }) });

    renderPage('/chat?botId=1&conversationId=77');

    const chatWindow = await screen.findByTestId('chat-window');
    expect(chatWindow.getAttribute('data-conversation-id')).toBe('77');
    expect(useChatStore.getState().selectedConversationId).toBe(77);
    // phone: selectConversation opens the chat view
    expect(useChatStore.getState().showMobileChat).toBe(true);

    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1));
    expect(apiPostMock).toHaveBeenCalledWith('/bots/1/conversations/77/mark-as-read');
    // the parameter stays in the URL while the room is open
    expect(search()).toBe('?botId=1&conversationId=77');
  });

  it('uses the list copy when the list holds the linked room', async () => {
    listState.conversations = [room9(), room77({ unread_count: 3 })];
    apiGetMock.mockReturnValue(new Promise(() => {})); // hook never answers

    renderPage('/chat?botId=1&conversationId=77');

    const chatWindow = await screen.findByTestId('chat-window');
    expect(chatWindow.getAttribute('data-conversation-id')).toBe('77');
    // unread 3 = the list copy, not the (pending) hook copy
    expect(chatWindow.getAttribute('data-unread')).toBe('3');
    expect(useChatStore.getState().selectedConversationId).toBe(77);
    expect(apiPostMock).toHaveBeenCalledWith('/bots/1/conversations/77/mark-as-read');
  });

  it('does not re-select or re-mark the room on later list refreshes', async () => {
    apiGetMock.mockResolvedValue({ data: room77() });
    apiPostMock.mockResolvedValue({ data: room77({ unread_count: 0 }) });

    const view = renderPage('/chat?botId=1&conversationId=77');
    await screen.findByTestId('chat-window');
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1));

    // a list refresh with fresh object identities must not re-apply the link
    act(() => {
      listState.conversations = [room9(), room77({ unread_count: 2 })];
      view.rerender(
        <QueryClientProvider client={view.queryClient}>
          <MemoryRouter initialEntries={['/chat?botId=1&conversationId=77']}>
            <ChatPage />
            <LocationProbe />
          </MemoryRouter>
        </QueryClientProvider>
      );
    });

    await new Promise((resolve) => setTimeout(resolve, 25));
    expect(apiPostMock).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('chat-window').getAttribute('data-conversation-id')).toBe('77');
  });

  it('opens a second, different link value opened in the same tab', async () => {
    apiGetMock.mockImplementation((url: string) => {
      const id = url.includes('/conversations/77') ? 77 : 88;
      return Promise.resolve({ data: room77({ id, unread_count: 0 }) });
    });

    renderPage('/chat?botId=1&conversationId=77', {
      navTo: '/chat?botId=1&conversationId=88',
    });
    await waitFor(() =>
      expect(screen.getByTestId('chat-window').getAttribute('data-conversation-id')).toBe('77')
    );

    fireEvent.click(screen.getByText('mock-nav'));

    await waitFor(() =>
      expect(screen.getByTestId('chat-window').getAttribute('data-conversation-id')).toBe('88')
    );
    expect(useChatStore.getState().selectedConversationId).toBe(88);
  });

  it('reopens the room after a reload (fresh mount with the same URL)', async () => {
    apiGetMock.mockResolvedValueOnce({ data: room77({ unread_count: 1 }) });
    apiPostMock.mockResolvedValue({ data: room77({ unread_count: 0 }) });
    // after the "reload" the room is already read
    apiGetMock.mockResolvedValue({ data: room77({ unread_count: 0 }) });

    const first = renderPage('/chat?botId=1&conversationId=77');
    await screen.findByTestId('chat-window');
    first.unmount();
    apiPostMock.mockClear();

    const second = renderPage('/chat?botId=1&conversationId=77');
    const chatWindow = await second.findByTestId('chat-window');
    expect(chatWindow.getAttribute('data-conversation-id')).toBe('77');
    // already read server-side -> no second mark-as-read
    await new Promise((resolve) => setTimeout(resolve, 25));
    expect(apiPostMock).not.toHaveBeenCalled();
  });

  // Review 1, item 2d: opening a link for unread_count 3 marks read EXACTLY
  // once and performs no other write (no handover, no send, no edit).
  it('marks the linked room read exactly once for unread_count 3 and fires no other mutation', async () => {
    apiGetMock.mockResolvedValue({ data: room77({ unread_count: 3 }) });
    apiPostMock.mockResolvedValue({ data: room77({ unread_count: 0 }) });

    renderPage('/chat?botId=1&conversationId=77');

    await screen.findByTestId('chat-window');
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1));
    expect(apiPostMock).toHaveBeenCalledWith('/bots/1/conversations/77/mark-as-read');
    // settle: opening a link writes nothing else through the POST transport
    await new Promise((resolve) => setTimeout(resolve, 25));
    expect(apiPostMock).toHaveBeenCalledTimes(1);
  });

  // Review 1, item 2e: the link applies ONCE — selectConversation is called
  // exactly once per link value; rerenders and list refreshes never re-select.
  it('calls selectConversation exactly once for the link; rerenders never re-select', async () => {
    apiGetMock.mockResolvedValue({ data: room77() });
    apiPostMock.mockResolvedValue({ data: room77({ unread_count: 0 }) });
    const originalSelect = useChatStore.getState().selectConversation;
    const selectSpy = vi.fn(originalSelect);
    useChatStore.setState({ selectConversation: selectSpy });

    try {
      const view = renderPage('/chat?botId=1&conversationId=77');
      await screen.findByTestId('chat-window');
      expect(selectSpy).toHaveBeenCalledTimes(1);
      expect(selectSpy).toHaveBeenCalledWith(77);

      // a later list refresh with fresh object identities must not re-select
      act(() => {
        listState.conversations = [room9(), room77({ unread_count: 2 })];
        view.rerender(
          <QueryClientProvider client={view.queryClient}>
            <MemoryRouter initialEntries={['/chat?botId=1&conversationId=77']}>
              <ChatPage />
              <LocationProbe />
            </MemoryRouter>
          </QueryClientProvider>
        );
      });
      await new Promise((resolve) => setTimeout(resolve, 25));
      expect(selectSpy).toHaveBeenCalledTimes(1);
      expect(screen.getByTestId('chat-window').getAttribute('data-conversation-id')).toBe('77');
    } finally {
      useChatStore.setState({ selectConversation: originalSelect });
    }
  });
});

describe('ChatPage room link — leaving the room', () => {
  beforeEach(() => {
    stubViewport(false); // phone
    apiGetMock.mockReset();
    apiPostMock.mockReset();
    listState.conversations = [];
    listState.isLoading = false;
    useChatStore.setState({
      selectedConversationId: null,
      showMobileChat: false,
      isCustomerPanelOpen: false,
      searchQuery: '',
    });
  });

  it('removes the parameter when the user picks another room in the list', async () => {
    apiGetMock.mockResolvedValue({ data: room77() });
    apiPostMock.mockResolvedValue({ data: room77({ unread_count: 0 }) });
    listState.conversations = [room9(), room77({ unread_count: 0 })];

    renderPage('/chat?botId=1&conversationId=77');
    await screen.findByTestId('chat-window');

    fireEvent.click(screen.getByRole('button', { name: /E2E ลูกค้าเก้า/ }));

    await waitFor(() => expect(search()).toBe('?botId=1'));
    expect(useChatStore.getState().selectedConversationId).toBe(9);
    expect(screen.getByTestId('chat-window').getAttribute('data-conversation-id')).toBe('9');
  });

  it('removes the parameter when the user taps back on phone (the room stays the stored selection)', async () => {
    apiGetMock.mockResolvedValue({ data: room77() });
    apiPostMock.mockResolvedValue({ data: room77({ unread_count: 0 }) });

    renderPage('/chat?botId=1&conversationId=77');
    const chatWindow = await screen.findByTestId('chat-window');
    expect(chatWindow.getAttribute('data-conversation-id')).toBe('77');

    fireEvent.click(screen.getByText('mock-back'));

    await waitFor(() => expect(search()).toBe('?botId=1'));
    // normal page back: the room stays selected but the phone list shows again
    expect(useChatStore.getState().selectedConversationId).toBe(77);
    expect(useChatStore.getState().showMobileChat).toBe(false);
    expect(centerPanel().classList.contains('hidden')).toBe(true);
  });

  it('removes the parameter when the user picks another bot', async () => {
    apiGetMock.mockResolvedValue({ data: room77() });
    apiPostMock.mockResolvedValue({ data: room77({ unread_count: 0 }) });

    renderPage('/chat?botId=1&conversationId=77');
    await screen.findByTestId('chat-window');

    fireEvent.click(screen.getByText('mock-bot-2'));

    await waitFor(() => expect(search()).toBe('?botId=2'));
    expect(useChatStore.getState().selectedConversationId).toBeNull();
    expect(useChatStore.getState().showMobileChat).toBe(false);
  });
});

describe('invalid_or_cross_bot_room_link_shows_error_not_another_room (rule 5)', () => {
  beforeEach(() => {
    stubViewport(false); // phone
    apiGetMock.mockReset();
    apiPostMock.mockReset();
    listState.conversations = [];
    listState.isLoading = false;
    useChatStore.setState({
      selectedConversationId: null,
      showMobileChat: false,
      isCustomerPanelOpen: false,
      searchQuery: '',
    });
  });

  it.each([
    'abc',
    '0',
    '-5',
    '1.5',
    '12abc',
    '1e3',
    '9007199254740993',
    '', // empty value is still a present parameter
  ])(
    'bad conversationId %j: exact panel, zero room requests, no ChatWindow',
    async (bad) => {
      renderPage(`/chat?botId=1&conversationId=${bad}`);

      expect(await screen.findByText('เปิดห้องแชตนี้ไม่ได้')).toBeInTheDocument();
      expect(screen.getByText('ห้องนี้อาจถูกลบ หรือบัญชีนี้ไม่มีสิทธิ์ดู')).toBeInTheDocument();
      expect(screen.getByRole('button', { name: 'กลับไปที่รายการแชต' })).toBeInTheDocument();
      expect(screen.queryByTestId('chat-window')).not.toBeInTheDocument();
      expect(screen.queryByTestId('info-panel')).not.toBeInTheDocument();
      // settle: a bad link never calls the room endpoint
      await new Promise((resolve) => setTimeout(resolve, 25));
      expect(apiGetMock).not.toHaveBeenCalled();
    }
  );

  it('treats a link without a usable botId the same way', async () => {
    renderPage('/chat?conversationId=77');

    expect(await screen.findByText('เปิดห้องแชตนี้ไม่ได้')).toBeInTheDocument();
    await new Promise((resolve) => setTimeout(resolve, 25));
    expect(apiGetMock).not.toHaveBeenCalled();
    // the bot auto-redirect must not wipe the parameter behind the error panel
    expect(search()).toBe('?conversationId=77');
  });

  it('cross-bot room link answers 404: exact panel, no ChatWindow, button restores the page', async () => {
    // room 9 belongs to bot 1; the link carries botId=2 -> the endpoint 404s
    // (ValidatesConversationOwnership) and no room may render instead
    apiGetMock.mockRejectedValue({ message: 'Not found.', status: 404 });
    listState.conversations = [room9()];
    useChatStore.setState({ selectedConversationId: 9, showMobileChat: false });

    renderPage('/chat?botId=2&conversationId=404');

    expect(await screen.findByText('เปิดห้องแชตนี้ไม่ได้')).toBeInTheDocument();
    expect(screen.getByText('ห้องนี้อาจถูกลบ หรือบัญชีนี้ไม่มีสิทธิ์ดู')).toBeInTheDocument();
    expect(screen.queryByTestId('chat-window')).not.toBeInTheDocument();
    expect(screen.queryByTestId('info-panel')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'กลับไปที่รายการแชต' }));

    await waitFor(() => expect(search()).toBe('?botId=2'));
    // normal page back: stored selection renders again
    expect(await screen.findByTestId('chat-window')).toHaveAttribute('data-conversation-id', '9');
  });

  it('403 no-access link: same panel, no ChatWindow', async () => {
    apiGetMock.mockRejectedValue({ message: 'Forbidden.', status: 403 });

    renderPage('/chat?botId=1&conversationId=55');

    expect(await screen.findByText('เปิดห้องแชตนี้ไม่ได้')).toBeInTheDocument();
    expect(screen.getByText('ห้องนี้อาจถูกลบ หรือบัญชีนี้ไม่มีสิทธิ์ดู')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'กลับไปที่รายการแชต' })).toBeInTheDocument();
    expect(screen.queryByTestId('chat-window')).not.toBeInTheDocument();
    expect(screen.queryByTestId('info-panel')).not.toBeInTheDocument();
  });
});

describe('ChatPage room link — load failure', () => {
  beforeEach(() => {
    stubViewport(false); // phone
    apiGetMock.mockReset();
    apiPostMock.mockReset();
    listState.conversations = [];
    listState.isLoading = false;
    useChatStore.setState({
      selectedConversationId: null,
      showMobileChat: false,
      isCustomerPanelOpen: false,
      searchQuery: '',
    });
  });

  it('shows the retry panel on a 5xx and asks again from its button', async () => {
    apiGetMock.mockRejectedValueOnce({ message: 'Server Error', status: 500 });
    apiGetMock.mockResolvedValue({ data: room77({ unread_count: 0 }) });

    renderPage('/chat?botId=1&conversationId=77');

    expect(await screen.findByText('โหลดห้องแชตไม่สำเร็จ')).toBeInTheDocument();
    expect(screen.getByText('ลองอีกครั้ง หรือกลับไปเลือกห้องจากรายการ')).toBeInTheDocument();
    expect(apiGetMock).toHaveBeenCalledTimes(1);

    fireEvent.click(screen.getByRole('button', { name: 'ลองอีกครั้ง' }));

    expect(await screen.findByTestId('chat-window')).toHaveAttribute('data-conversation-id', '77');
    expect(apiGetMock).toHaveBeenCalledTimes(2);
  });

  it('shows the retry panel on a network failure', async () => {
    apiGetMock.mockRejectedValue(new Error('Network Error'));

    renderPage('/chat?botId=1&conversationId=77');

    expect(await screen.findByText('โหลดห้องแชตไม่สำเร็จ')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'ลองอีกครั้ง' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'กลับไปที่รายการแชต' })).toBeInTheDocument();
  });

  it('returns to the normal page from the retry panel back button', async () => {
    apiGetMock.mockRejectedValue({ message: 'Server Error', status: 503 });

    renderPage('/chat?botId=1&conversationId=77');

    expect(await screen.findByText('โหลดห้องแชตไม่สำเร็จ')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'กลับไปที่รายการแชต' }));

    await waitFor(() => expect(search()).toBe('?botId=1'));
    expect(screen.queryByText('โหลดห้องแชตไม่สำเร็จ')).not.toBeInTheDocument();
  });
});

describe('ChatPage room link — error panel layout', () => {
  beforeEach(() => {
    stubViewport(false); // phone
    apiGetMock.mockReset();
    apiPostMock.mockReset();
    listState.conversations = [];
    listState.isLoading = false;
    useChatStore.setState({
      selectedConversationId: null,
      showMobileChat: false,
      isCustomerPanelOpen: false,
      searchQuery: '',
    });
  });

  it('on phone the error panel replaces the list and survives the back-to-list effect', async () => {
    listState.conversations = [room9()];
    useChatStore.setState({ selectedConversationId: 9, showMobileChat: true });
    apiGetMock.mockRejectedValue({ message: 'Not found.', status: 404 });

    renderPage('/chat?botId=1&conversationId=404');

    expect(await screen.findByText('เปิดห้องแชตนี้ไม่ได้')).toBeInTheDocument();
    expect(listPanel().classList.contains('hidden')).toBe(true);
    expect(centerPanel().classList.contains('hidden')).toBe(false);
    // the "back to the list" effect must not hide the panel
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(screen.getByText('เปิดห้องแชตนี้ไม่ได้')).toBeInTheDocument();
    expect(listPanel().classList.contains('hidden')).toBe(true);
  });

  it('on desktop the list stays visible beside the error panel and no room renders', async () => {
    stubViewport(true); // desktop
    listState.conversations = [room9()];
    apiGetMock.mockRejectedValue({ message: 'Not found.', status: 404 });

    renderPage('/chat?botId=1&conversationId=404');

    expect(await screen.findByText('เปิดห้องแชตนี้ไม่ได้')).toBeInTheDocument();
    // 'hidden md:flex' hides below md only — the md:flex lift means the list
    // stays visible beside the error panel on desktop (e2e-verified)
    expect(listPanel().classList.contains('md:flex')).toBe(true);
    expect(screen.queryByTestId('chat-window')).not.toBeInTheDocument();
    expect(screen.queryByTestId('info-panel')).not.toBeInTheDocument();
  });
});

// Review 1, item 1 (acceptance `deep_link_opens_room_outside_first_page`):
// list = rooms 1..30, chat-store seeded with the stored selection 5, link to
// room 77 (outside the first page). ChatWindow / CustomerInfoPanel must never
// mount with room 1 (auto-select) or the stored selection — not even for the
// one commit between `linkState === 'ready'` and the post-commit apply.
describe('ChatPage room link — deep_link_opens_room_outside_first_page', () => {
  beforeEach(() => {
    stubViewport(false); // phone by default
    apiGetMock.mockReset();
    apiPostMock.mockReset();
    listState.conversations = [];
    listState.isLoading = false;
    useChatStore.setState({
      selectedConversationId: null,
      showMobileChat: false,
      isCustomerPanelOpen: false,
      searchQuery: '',
    });
    mountProbe.chatWindow.length = 0;
    mountProbe.infoPanel.length = 0;
  });

  const firstThirtyRooms = () =>
    Array.from({ length: 30 }, (_, i) => makeRoom(i + 1, `E2E ลูกค้า ${i + 1}`));

  it('deep_link_opens_room_outside_first_page (desktop: stored room 5 and room 1 never mount)', async () => {
    stubViewport(true); // desktop
    listState.conversations = firstThirtyRooms();
    useChatStore.setState({ selectedConversationId: 5, showMobileChat: false });
    apiGetMock.mockResolvedValue({ data: room77({ unread_count: 1 }) });
    apiPostMock.mockResolvedValue({ data: room77({ unread_count: 0 }) });

    renderPage('/chat?botId=1&conversationId=77');

    const chatWindow = await screen.findByTestId('chat-window');
    expect(chatWindow.getAttribute('data-conversation-id')).toBe('77');
    // the ONLY rooms ever mounted are the linked one — room 1 (auto-select)
    // and the stored selection 5 must not mount even for one commit
    expect(mountProbe.chatWindow).toEqual(['77']);
    expect(mountProbe.infoPanel).toEqual(['77']);
    expect(useChatStore.getState().selectedConversationId).toBe(77);
  });

  it('deep_link_opens_room_outside_first_page (phone: stored room never mounts, chat view opens)', async () => {
    listState.conversations = firstThirtyRooms();
    useChatStore.setState({ selectedConversationId: 5, showMobileChat: true });
    apiGetMock.mockResolvedValue({ data: room77({ unread_count: 1 }) });
    apiPostMock.mockResolvedValue({ data: room77({ unread_count: 0 }) });

    renderPage('/chat?botId=1&conversationId=77');

    const chatWindow = await screen.findByTestId('chat-window');
    expect(chatWindow.getAttribute('data-conversation-id')).toBe('77');
    expect(mountProbe.chatWindow).toEqual(['77']);
    expect(useChatStore.getState().selectedConversationId).toBe(77);
    // phone: the link opens the chat view and marks the room read once
    expect(useChatStore.getState().showMobileChat).toBe(true);
    await waitFor(() => expect(apiPostMock).toHaveBeenCalledTimes(1));
    expect(apiPostMock).toHaveBeenCalledWith('/bots/1/conversations/77/mark-as-read');
  });
});
