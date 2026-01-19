---
id: state-003-avoid-prop-drilling
title: Avoiding Prop Drilling
impact: MEDIUM
impactDescription: "Simplifies component trees by using appropriate state management"
category: state
tags: [props, context, zustand, component-design]
relatedRules: [state-002-store-selectors]
---

## Why This Matters

Prop drilling passes data through multiple component layers that don't use it. This creates tight coupling, makes refactoring difficult, and clutters component signatures.

However, not all prop passing is bad - the solution depends on how data is used.

## Bad Example

```tsx
// Problem: Props passed through 4+ levels
function App() {
  const [user, setUser] = useState(null);
  const [theme, setTheme] = useState('light');

  return (
    <Layout user={user} theme={theme}>
      <Dashboard user={user} theme={theme} />
    </Layout>
  );
}

function Layout({ user, theme, children }) {
  return (
    <div className={theme}>
      <Header user={user} /> {/* Just passing through */}
      {children}
    </div>
  );
}

function Dashboard({ user, theme }) {
  return (
    <div>
      <Sidebar user={user} theme={theme} />
      <Content user={user} theme={theme} />
    </div>
  );
}

function Sidebar({ user, theme }) {
  return (
    <nav>
      <UserMenu user={user} /> {/* Finally used here */}
      <ThemeToggle theme={theme} />
    </nav>
  );
}

// Problem: Adding new data requires touching all intermediate components
// Need to add `permissions`? Touch App, Layout, Dashboard, Sidebar...

// Problem: Using context for everything
const AppContext = createContext({
  user: null,
  theme: 'light',
  bots: [],
  settings: {},
  // Everything in one context!
});

function App() {
  return (
    <AppContext.Provider value={{ user, theme, bots, settings }}>
      {/* Every component re-renders when ANY value changes */}
    </AppContext.Provider>
  );
}
```

**Why it's wrong:**
- Intermediate components get props they don't use
- Adding new data requires modifying many files
- Component signatures become bloated
- Single context causes excessive re-renders

## Good Example

```tsx
// Solution 1: Zustand for global state
// stores/auth.ts
export const useAuthStore = create<AuthState>()((set) => ({
  user: null,
  setUser: (user) => set({ user }),
}));

// Any component can access directly
function UserMenu() {
  const user = useAuthStore((s) => s.user);
  return <span>{user?.name}</span>;
}

function Header() {
  return (
    <header>
      <Logo />
      <UserMenu /> {/* No props needed */}
    </header>
  );
}

// Solution 2: React Query for server state
function BotDetail({ botId }: { botId: string }) {
  // Data fetched where needed, not passed down
  const { data: bot } = useBot(botId);

  return (
    <div>
      <BotHeader bot={bot} /> {/* Props at usage level */}
      <BotContent botId={botId} /> {/* Child fetches what it needs */}
    </div>
  );
}

function BotContent({ botId }: { botId: string }) {
  const { data: conversations } = useConversations(botId);
  return <ConversationList items={conversations} />;
}

// Solution 3: Composition for layout data
function Layout({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex">
      <Sidebar />
      <main>{children}</main>
    </div>
  );
}

// Sidebar gets its own data
function Sidebar() {
  const user = useUser();
  const { data: bots } = useBots();

  return (
    <nav>
      <UserProfile user={user} />
      <BotList bots={bots} />
    </nav>
  );
}

// Solution 4: Context for subtree-scoped state
interface BotEditorContextValue {
  bot: Bot;
  updateField: (field: string, value: any) => void;
  save: () => Promise<void>;
  isDirty: boolean;
}

const BotEditorContext = createContext<BotEditorContextValue | null>(null);

function useBotEditor() {
  const context = useContext(BotEditorContext);
  if (!context) throw new Error('useBotEditor must be inside BotEditorProvider');
  return context;
}

// Provider wraps only the editor subtree
function BotEditorPage({ botId }: { botId: string }) {
  const [bot, setBot] = useState<Bot | null>(null);
  const [isDirty, setIsDirty] = useState(false);

  const value = useMemo(() => ({
    bot,
    updateField: (field, value) => {
      setBot(prev => ({ ...prev!, [field]: value }));
      setIsDirty(true);
    },
    save: async () => {
      await updateBot(botId, bot!);
      setIsDirty(false);
    },
    isDirty,
  }), [bot, isDirty, botId]);

  return (
    <BotEditorContext.Provider value={value}>
      <EditorHeader />
      <EditorForm />
      <EditorActions />
    </BotEditorContext.Provider>
  );
}

// Deeply nested component accesses context
function EditorActions() {
  const { save, isDirty } = useBotEditor();

  return (
    <button onClick={save} disabled={!isDirty}>
      Save Changes
    </button>
  );
}

// Solution 5: Props for closely related components
// Not everything needs global state!
function ConversationCard({ conversation }: { conversation: Conversation }) {
  // Props are fine for direct parent-child
  return (
    <div>
      <ConversationHeader title={conversation.title} />
      <ConversationPreview message={conversation.lastMessage} />
    </div>
  );
}
```

**Why it's better:**
- Components get data where they need it
- Adding new data doesn't require changing intermediate components
- Clear ownership of state
- Context scoped to relevant subtrees
- Props still used for closely related components

## Project-Specific Notes

**BotFacebook State Strategy:**

| Data Type | Solution | Example |
|-----------|----------|---------|
| Auth/user | Zustand | `useAuthStore` |
| Theme/UI | Zustand | `useUIStore` |
| Server data | React Query | `useBots()`, `useConversations()` |
| Form state | Local + Context | `BotEditorContext` |
| Component props | Props | `<BotCard bot={bot} />` |

**Decision Guide:**
```
Is this data used by many unrelated components?
├── Yes → Zustand store
└── No → Is this server data?
    ├── Yes → React Query
    └── No → Is this form/editor state?
        ├── Yes → Context (scoped)
        └── No → Props or local state
```

## References

- [Zustand vs Context](https://docs.pmnd.rs/zustand/getting-started/comparison#react-context)
- [Component Composition](https://react.dev/learn/passing-data-deeply-with-context#before-you-use-context)
