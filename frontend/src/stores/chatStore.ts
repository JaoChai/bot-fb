/**
 * T025: Chat Store - UI state only
 * Server state handled by React Query
 */
import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

interface ChatState {
  // Selected conversation
  selectedConversationId: number | null;

  // UI state
  isCustomerPanelOpen: boolean;
  showMobileChat: boolean;

  // Filters
  searchQuery: string;
}

interface ChatActions {
  // Selection
  selectConversation: (id: number | null) => void;

  // UI controls
  setCustomerPanelOpen: (open: boolean) => void;
  toggleCustomerPanel: () => void;
  setShowMobileChat: (show: boolean) => void;

  // Search
  setSearchQuery: (query: string) => void;

  // Reset
  reset: () => void;
}

type ChatStore = ChatState & ChatActions;

const initialState: ChatState = {
  selectedConversationId: null,
  isCustomerPanelOpen: true,
  showMobileChat: false,
  searchQuery: '',
};

export const useChatStore = create<ChatStore>()(
  persist(
    (set) => ({
      ...initialState,

      selectConversation: (id) =>
        set({
          selectedConversationId: id,
          showMobileChat: id !== null, // Auto-show chat on mobile when selecting
        }),

      setCustomerPanelOpen: (isCustomerPanelOpen) => set({ isCustomerPanelOpen }),

      toggleCustomerPanel: () =>
        set((state) => ({ isCustomerPanelOpen: !state.isCustomerPanelOpen })),

      setShowMobileChat: (showMobileChat) => set({ showMobileChat }),

      setSearchQuery: (searchQuery) => set({ searchQuery }),

      reset: () => set(initialState),
    }),
    {
      name: 'chat-store',
      storage: createJSONStorage(() => localStorage),
      // Only persist selectedConversationId
      partialize: (state) => ({
        selectedConversationId: state.selectedConversationId,
      }),
    }
  )
);
