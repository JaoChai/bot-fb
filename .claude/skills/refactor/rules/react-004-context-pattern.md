---
id: react-004-context-pattern
title: Context/Zustand Pattern Refactoring
impact: HIGH
impactDescription: "Replace prop drilling with context or Zustand stores"
category: react
tags: [context, zustand, state, prop-drilling]
relatedRules: [react-002-extract-hook, react-005-state-refactor]
---

## Code Smell

- Props passed through 3+ levels
- Same prop repeated in many components
- Intermediate components don't use props
- Hard to trace data flow
- "Threading" props through component tree

## Root Cause

1. State started at top level
2. No state management decision
3. Fear of "global state"
4. Component hierarchy evolved
5. Quick fixes to "just pass it down"

## When to Apply

**Apply when:**
- Props passed > 3 levels deep
- Many components need same data
- Data changes infrequently (Context)
- Data changes frequently (Zustand)

**Don't apply when:**
- 1-2 level prop passing
- Composition can solve it
- Would add complexity

## Solution

### Before (Prop Drilling)

```tsx
function App() {
  const [user, setUser] = useState<User | null>(null);

  return (
    <Layout user={user}>
      <Sidebar user={user}>
        <Navigation user={user}>
          <UserMenu user={user} setUser={setUser} />
        </Navigation>
      </Sidebar>
      <Main user={user}>
        <Dashboard user={user}>
          <WelcomeMessage user={user} />
          <UserStats user={user} />
        </Dashboard>
      </Main>
    </Layout>
  );
}

// Every component needs to accept and pass user
function Sidebar({ user, children }) {
  // Doesn't use user, just passes it
  return <aside>{children}</aside>;
}

function Navigation({ user, children }) {
  // Doesn't use user, just passes it
  return <nav>{children}</nav>;
}
```

### After (Zustand - Recommended for BotFacebook)

```tsx
// stores/authStore.ts
interface AuthState {
  user: User | null;
  isAuthenticated: boolean;
  login: (user: User) => void;
  logout: () => void;
  updateUser: (updates: Partial<User>) => void;
}

export const useAuthStore = create<AuthState>()(
  persist(
    (set) => ({
      user: null,
      isAuthenticated: false,

      login: (user) => set({
        user,
        isAuthenticated: true,
      }),

      logout: () => set({
        user: null,
        isAuthenticated: false,
      }),

      updateUser: (updates) => set((state) => ({
        user: state.user ? { ...state.user, ...updates } : null,
      })),
    }),
    {
      name: 'auth-storage',
      partialize: (state) => ({ user: state.user }),
    }
  )
);

// Selectors for performance
export const useUser = () => useAuthStore((state) => state.user);
export const useIsAuthenticated = () => useAuthStore((state) => state.isAuthenticated);

// App.tsx - CLEAN
function App() {
  return (
    <Layout>
      <Sidebar>
        <Navigation>
          <UserMenu />
        </Navigation>
      </Sidebar>
      <Main>
        <Dashboard>
          <WelcomeMessage />
          <UserStats />
        </Dashboard>
      </Main>
    </Layout>
  );
}

// Components access state directly
function UserMenu() {
  const user = useUser();
  const logout = useAuthStore((state) => state.logout);

  if (!user) return <LoginButton />;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger>
        <Avatar src={user.avatar} />
      </DropdownMenuTrigger>
      <DropdownMenuContent>
        <DropdownMenuItem onClick={logout}>
          Logout
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

function WelcomeMessage() {
  const user = useUser();
  return <h1>Welcome, {user?.name}!</h1>;
}
```

### Alternative: React Context (for less frequent updates)

```tsx
// contexts/ThemeContext.tsx
interface ThemeContextValue {
  theme: 'light' | 'dark';
  toggleTheme: () => void;
}

const ThemeContext = createContext<ThemeContextValue | null>(null);

export function ThemeProvider({ children }: { children: React.ReactNode }) {
  const [theme, setTheme] = useState<'light' | 'dark'>('light');

  const toggleTheme = useCallback(() => {
    setTheme((t) => (t === 'light' ? 'dark' : 'light'));
  }, []);

  const value = useMemo(
    () => ({ theme, toggleTheme }),
    [theme, toggleTheme]
  );

  return (
    <ThemeContext.Provider value={value}>
      {children}
    </ThemeContext.Provider>
  );
}

export function useTheme() {
  const context = useContext(ThemeContext);
  if (!context) {
    throw new Error('useTheme must be used within ThemeProvider');
  }
  return context;
}
```

### Decision: Context vs Zustand

| Factor | Use Context | Use Zustand |
|--------|-------------|-------------|
| Update frequency | Low | High |
| State complexity | Simple | Complex |
| Performance needs | Basic | Critical |
| Persistence | Not needed | Needed |
| DevTools | Basic | Advanced |
| Learning curve | Easier | Slight |

### Step-by-Step

1. **Choose approach** (Context or Zustand)

2. **Create store/context**
   ```bash
   touch src/stores/authStore.ts
   # or
   touch src/contexts/AuthContext.tsx
   ```

3. **Define state and actions**
   - State shape
   - Update functions
   - Selectors

4. **Remove prop drilling**
   - Remove props from intermediate components
   - Use store/context in leaf components

5. **Test**
   - Verify state updates work
   - Check re-render behavior

## Verification

```bash
# Type check
npm run type-check

# Verify no props remain
grep -rn "user={user}" src/
# Should return nothing for drilled props
```

## Anti-Patterns

- **Global everything**: Not all state needs to be global
- **Context for high-frequency**: Use Zustand instead
- **Missing selectors**: Always use selectors in Zustand
- **Provider hell**: Too many nested contexts

## Project-Specific Notes

**BotFacebook Context:**
- Stores: `src/stores/` (Zustand)
- Existing: useAuthStore, useUIStore, useBotPreferencesStore
- Pattern: Selectors for specific values
- Persist: localStorage with zustand/persist
