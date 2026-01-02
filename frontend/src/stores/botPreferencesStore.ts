import { create } from 'zustand';
import { persist, createJSONStorage } from 'zustand/middleware';

interface BotPreferencesState {
  lastUsedBotId: number | null;
}

interface BotPreferencesActions {
  setLastUsedBotId: (botId: number | null) => void;
}

type BotPreferencesStore = BotPreferencesState & BotPreferencesActions;

export const useBotPreferencesStore = create<BotPreferencesStore>()(
  persist(
    (set) => ({
      lastUsedBotId: null,

      setLastUsedBotId: (botId) => set({ lastUsedBotId: botId }),
    }),
    {
      name: 'bot-preferences',
      storage: createJSONStorage(() => localStorage),
    }
  )
);
