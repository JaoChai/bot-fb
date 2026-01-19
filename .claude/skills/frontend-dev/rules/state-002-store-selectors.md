---
id: state-002-store-selectors
title: Zustand Store Selectors for Performance
impact: HIGH
impactDescription: "Prevents unnecessary re-renders by selecting only needed state"
category: state
tags: [zustand, selectors, performance, re-renders]
relatedRules: [perf-001-memoization, gotcha-002-infinite-rerenders]
---

## Why This Matters

Zustand triggers re-renders when state changes. Without selectors, components re-render when ANY part of the store changes. Selectors let components subscribe to specific slices, re-rendering only when that slice changes.

This is crucial for stores with many properties or frequent updates.

## Bad Example

```tsx
// Problem 1: Subscribing to entire store
function BotStatus({ botId }) {
  const store = useAuthStore(); // Subscribes to everything!
  const user = store.user;

  // Re-renders when ANYTHING in auth store changes:
  // - token changes? Re-render
  // - permissions change? Re-render
  // - lastLogin updates? Re-render

  return <span>{user?.name}</span>;
}

// Problem 2: Creating new objects in selector
function UserProfile() {
  // This creates a NEW object every time!
  const { user, permissions } = useAuthStore((state) => ({
    user: state.user,
    permissions: state.permissions,
  }));
  // Always re-renders because {} !== {}

  return <Profile user={user} permissions={permissions} />;
}

// Problem 3: Using store in callback without selector
function BotList() {
  const store = useBotStore();

  const handleClick = (botId) => {
    store.selectBot(botId); // Causes component to subscribe to entire store
  };

  return <List onItemClick={handleClick} />;
}
```

**Why it's wrong:**
- Entire store subscription causes unnecessary re-renders
- Inline object selectors create new references each render
- Performance degrades as store grows
- Components couple to unrelated state

## Good Example

```tsx
// Solution 1: Select specific values
function BotStatus() {
  // Only re-renders when user.name changes
  const userName = useAuthStore((state) => state.user?.name);

  return <span>{userName}</span>;
}

// Solution 2: Multiple selectors for multiple values
function UserProfile() {
  const user = useAuthStore((state) => state.user);
  const permissions = useAuthStore((state) => state.permissions);
  // Each selector creates separate subscription
  // Only re-renders when user OR permissions change

  return <Profile user={user} permissions={permissions} />;
}

// Solution 3: useShallow for object selection (zustand v4+)
import { useShallow } from 'zustand/react/shallow';

function UserDashboard() {
  const { user, token, logout } = useAuthStore(
    useShallow((state) => ({
      user: state.user,
      token: state.token,
      logout: state.logout,
    }))
  );
  // useShallow does shallow comparison, prevents unnecessary re-renders

  return (
    <div>
      <h1>{user?.name}</h1>
      <button onClick={logout}>Logout</button>
    </div>
  );
}

// Solution 4: Pre-defined selectors in store file
// stores/auth.ts
export const useAuthStore = create<AuthStore>()(/* ... */);

// Export typed selectors
export const useUser = () => useAuthStore((state) => state.user);
export const useToken = () => useAuthStore((state) => state.token);
export const useIsAuthenticated = () =>
  useAuthStore((state) => !!state.token);
export const useAuthActions = () =>
  useAuthStore(
    useShallow((state) => ({
      login: state.login,
      logout: state.logout,
    }))
  );

// Usage - clean and optimized
function Header() {
  const user = useUser();
  const isAuthenticated = useIsAuthenticated();
  const { logout } = useAuthActions();

  return (
    <header>
      {isAuthenticated && <span>{user?.name}</span>}
      <button onClick={logout}>Logout</button>
    </header>
  );
}

// Solution 5: Actions only (no re-render subscription)
function BotList() {
  // Get actions without subscribing to state
  const selectBot = useBotStore((state) => state.selectBot);

  const handleClick = useCallback((botId: string) => {
    selectBot(botId);
  }, [selectBot]); // selectBot is stable

  return <List onItemClick={handleClick} />;
}

// Solution 6: Derived selectors with useMemo
function BotStats() {
  const bots = useBotStore((state) => state.bots);

  // Compute derived state in component
  const { active, inactive } = useMemo(() => ({
    active: bots.filter(b => b.active).length,
    inactive: bots.filter(b => !b.active).length,
  }), [bots]);

  return (
    <div>
      <span>Active: {active}</span>
      <span>Inactive: {inactive}</span>
    </div>
  );
}
```

**Why it's better:**
- Specific selectors minimize re-renders
- `useShallow` handles object selection efficiently
- Pre-defined selectors enforce consistent patterns
- Action-only selection doesn't cause subscription
- Derived state computed outside store

## Project-Specific Notes

**BotFacebook Selector Patterns:**
```tsx
// stores/auth.ts exports:
export const useUser = () => useAuthStore((s) => s.user);
export const useToken = () => useAuthStore((s) => s.token);
export const useIsAuthenticated = () => useAuthStore((s) => !!s.token);

// stores/ui.ts exports:
export const useTheme = () => useUIStore((s) => s.theme);
export const useSidebarOpen = () => useUIStore((s) => s.sidebarOpen);
export const useUIActions = () => useUIStore(useShallow((s) => ({
  toggleSidebar: s.toggleSidebar,
  setTheme: s.setTheme,
})));
```

**Performance Debugging:**
```tsx
// Add logging to detect unnecessary re-renders
useEffect(() => {
  console.count('Component render');
});

// Or use React DevTools Profiler
```

## References

- [Zustand Auto Generating Selectors](https://docs.pmnd.rs/zustand/guides/auto-generating-selectors)
- [React Re-renders Guide](https://www.joshwcomeau.com/react/why-react-re-renders/)
