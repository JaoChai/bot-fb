---
id: state-001-zustand-persist
title: Zustand Persist Middleware
impact: MEDIUM
impactDescription: "Ensures client state survives page reloads without conflicts"
category: state
tags: [zustand, persist, localstorage, state-management]
relatedRules: [state-002-store-selectors]
---

## Why This Matters

Zustand's persist middleware saves state to localStorage, allowing it to survive page reloads. However, incorrect configuration can cause state conflicts, hydration mismatches, or storage bloat.

Key concerns: unique storage keys, selective persistence, and handling schema migrations.

## Bad Example

```tsx
// Problem 1: Conflicting storage keys
// authStore.ts
const useAuthStore = create(
  persist(
    (set) => ({
      user: null,
      token: null,
    }),
    { name: 'store' } // Generic name!
  )
);

// uiStore.ts
const useUIStore = create(
  persist(
    (set) => ({
      theme: 'light',
    }),
    { name: 'store' } // Same name - overwrites authStore!
  )
);

// Problem 2: Persisting everything including computed/derived state
const useBotStore = create(
  persist(
    (set, get) => ({
      bots: [],
      selectedBotId: null,
      // This gets persisted but is derived!
      selectedBot: () => get().bots.find(b => b.id === get().selectedBotId),
      activeBots: () => get().bots.filter(b => b.active),
    }),
    { name: 'bot-store' }
  )
);

// Problem 3: No version handling
const useStore = create(
  persist(
    (set) => ({
      settings: { theme: 'light' },
    }),
    { name: 'app-store' }
    // Adding new fields later will conflict with old stored data
  )
);
```

**Why it's wrong:**
- Same storage key means stores overwrite each other
- Persisting functions/derived state causes errors on hydration
- No versioning makes schema changes break existing users
- No control over what gets persisted

## Good Example

```tsx
// Solution 1: Unique, descriptive storage keys
// stores/auth.ts
export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      user: null,
      token: null,
      setUser: (user) => set({ user }),
      setToken: (token) => set({ token }),
      logout: () => set({ user: null, token: null }),
    }),
    {
      name: 'botfb-auth-store', // Unique prefix + purpose
      partialize: (state) => ({
        // Only persist what's needed
        token: state.token,
        // Don't persist user - fetch fresh on reload
      }),
    }
  )
);

// Solution 2: Selective persistence with partialize
export const useUIStore = create<UIState>()(
  persist(
    (set) => ({
      theme: 'system',
      sidebarOpen: true,
      recentlyViewedBots: [],
      // Transient state - not persisted
      isNavigating: false,
      searchQuery: '',
    }),
    {
      name: 'botfb-ui-store',
      partialize: (state) => ({
        theme: state.theme,
        sidebarOpen: state.sidebarOpen,
        recentlyViewedBots: state.recentlyViewedBots.slice(0, 10), // Limit size
        // isNavigating and searchQuery are NOT persisted
      }),
    }
  )
);

// Solution 3: Version migration
interface UserPrefsV1 {
  theme: 'light' | 'dark';
}

interface UserPrefsV2 {
  theme: 'light' | 'dark' | 'system';
  fontSize: 'small' | 'medium' | 'large';
}

export const usePrefsStore = create<UserPrefsV2>()(
  persist(
    (set) => ({
      theme: 'system',
      fontSize: 'medium',
      setTheme: (theme) => set({ theme }),
      setFontSize: (fontSize) => set({ fontSize }),
    }),
    {
      name: 'botfb-prefs-store',
      version: 2, // Increment when schema changes
      migrate: (persistedState, version) => {
        if (version === 1) {
          // Migration from v1 to v2
          const state = persistedState as UserPrefsV1;
          return {
            ...state,
            fontSize: 'medium', // New field with default
          };
        }
        return persistedState as UserPrefsV2;
      },
    }
  )
);

// Solution 4: Custom storage (for sensitive data)
import { createJSONStorage } from 'zustand/middleware';

export const useSessionStore = create<SessionState>()(
  persist(
    (set) => ({
      sessionId: null,
    }),
    {
      name: 'botfb-session',
      storage: createJSONStorage(() => sessionStorage), // Cleared on tab close
    }
  )
);

// Solution 5: Handling hydration
export const useBotPrefsStore = create<BotPrefsState>()(
  persist(
    (set) => ({
      preferences: {},
      isHydrated: false,
      setPreference: (botId, prefs) =>
        set((state) => ({
          preferences: { ...state.preferences, [botId]: prefs },
        })),
    }),
    {
      name: 'botfb-bot-prefs',
      onRehydrateStorage: () => (state) => {
        state?.setHydrated?.(true);
      },
    }
  )
);
```

**Why it's better:**
- Unique keys prevent conflicts
- `partialize` controls what gets saved
- Version + migrate handles schema changes
- Sensitive data can use sessionStorage
- Hydration status available for SSR

## Project-Specific Notes

**BotFacebook Stores:**
```
frontend/src/stores/
├── auth.ts        → botfb-auth-store (token only)
├── ui.ts          → botfb-ui-store (theme, sidebar)
├── preferences.ts → botfb-prefs-store (user settings)
└── connection.ts  → Not persisted (WebSocket state)
```

**What to Persist:**
| Data | Persist? | Reason |
|------|----------|--------|
| Auth token | Yes | Survive refresh |
| Theme preference | Yes | User preference |
| Sidebar state | Yes | User preference |
| Selected bot | No | Could be deleted |
| WebSocket state | No | Transient |
| Form data | No | Re-fetch from server |

## References

- [Zustand Persist Middleware](https://docs.pmnd.rs/zustand/integrations/persisting-store-data)
- [Zustand TypeScript Guide](https://docs.pmnd.rs/zustand/guides/typescript)
