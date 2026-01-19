---
id: frontend-003-prop-drilling
title: Avoid Prop Drilling
impact: MEDIUM
impactDescription: "Passing props through many levels makes refactoring painful"
category: frontend
tags: [react, props, context, zustand, state-management]
relatedRules: [frontend-002-custom-hooks]
---

## Why This Matters

Prop drilling (passing props through 3+ levels) creates tight coupling between components. Changes require updating every intermediate component.

## Bad Example

```tsx
// Props passed through 4 levels
function App() {
  const [user, setUser] = useState(null);
  return <Layout user={user} setUser={setUser} />;
}

function Layout({ user, setUser }) {
  return <Sidebar user={user} setUser={setUser} />;
}

function Sidebar({ user, setUser }) {
  return <UserMenu user={user} setUser={setUser} />;
}

function UserMenu({ user, setUser }) {
  return <button onClick={() => setUser(null)}>Logout</button>;
}
```

**Why it's wrong:**
- Intermediate components don't use props
- Adding new prop requires updating all
- Tight coupling
- Hard to refactor

## Good Example

```tsx
// Option 1: Zustand store (recommended for BotFacebook)
const useAuthStore = create<AuthStore>((set) => ({
  user: null,
  setUser: (user) => set({ user }),
  logout: () => set({ user: null }),
}));

function App() {
  return <Layout />;
}

function Layout() {
  return <Sidebar />;
}

function UserMenu() {
  const { user, logout } = useAuthStore();
  return <button onClick={logout}>Logout ({user?.name})</button>;
}

// Option 2: Context (for narrower scope)
const UserContext = createContext<UserContextValue | null>(null);

function UserProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  return (
    <UserContext.Provider value={{ user, setUser }}>
      {children}
    </UserContext.Provider>
  );
}

function useUser() {
  const context = useContext(UserContext);
  if (!context) throw new Error('useUser must be within UserProvider');
  return context;
}
```

**Why it's better:**
- No intermediate props
- Components decoupled
- Easy to add consumers
- Refactor-friendly

## Review Checklist

- [ ] No props passed through 3+ levels
- [ ] Global state in Zustand stores
- [ ] Context for tree-scoped state
- [ ] React Query for server state
- [ ] Hooks abstract state access

## Detection

```bash
# Look for prop chains
grep -rn "({ .*,.*,.*,.*})" --include="*.tsx" src/components/

# Components just passing props
grep -A 10 "function.*Props" --include="*.tsx" src/ | grep "return.*{.*}"
```

## Project-Specific Notes

**BotFacebook State Architecture:**

```
# Global state (Zustand)
src/stores/
├── useAuthStore.ts       # User, token, login/logout
├── useUIStore.ts         # Sidebar, theme, modals
└── useBotPrefs.ts        # Selected bot preferences

# Server state (React Query)
src/hooks/
├── useBots.ts            # useQuery for bot list
├── useConversations.ts   # useInfiniteQuery for messages
└── mutations/
    └── useCreateBot.ts   # useMutation for creation

# Component composition
function DashboardPage() {
  // No props drilling - components fetch their own state
  return (
    <DashboardLayout>
      <BotSidebar />      {/* Uses useBots() */}
      <MainContent>
        <BotDetail />     {/* Uses useBot(id) */}
      </MainContent>
    </DashboardLayout>
  );
}
```
