# React Refactoring Guide

React 19 + TypeScript + Tailwind v4 refactoring patterns.

## Extract Component

### Before (Large Component)
```tsx
function ChatPage() {
  // 200+ lines with multiple responsibilities
  return (
    <div>
      {/* Header section - 50 lines */}
      <header>...</header>

      {/* Message list - 80 lines */}
      <div>
        {messages.map(m => (
          // Complex message rendering
        ))}
      </div>

      {/* Input section - 70 lines */}
      <form>...</form>
    </div>
  );
}
```

### After (Composed Components)
```tsx
// ChatPage.tsx
function ChatPage() {
  return (
    <div>
      <ChatHeader />
      <MessageList messages={messages} />
      <ChatInput onSend={handleSend} />
    </div>
  );
}

// components/ChatHeader.tsx
function ChatHeader() {
  return <header>...</header>;
}

// components/MessageList.tsx
function MessageList({ messages }: { messages: Message[] }) {
  return (
    <div>
      {messages.map(m => (
        <MessageItem key={m.id} message={m} />
      ))}
    </div>
  );
}

// components/ChatInput.tsx
function ChatInput({ onSend }: { onSend: (text: string) => void }) {
  return <form>...</form>;
}
```

## Extract Custom Hook

### Before (Duplicate Logic)
```tsx
// In ComponentA
function ComponentA() {
  const [bots, setBots] = useState<Bot[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<Error | null>(null);

  useEffect(() => {
    api.getBots()
      .then(setBots)
      .catch(setError)
      .finally(() => setLoading(false));
  }, []);
  // ...
}

// In ComponentB - same logic duplicated
function ComponentB() {
  const [bots, setBots] = useState<Bot[]>([]);
  // ... same code
}
```

### After (Custom Hook)
```tsx
// hooks/useBots.ts
function useBots() {
  return useQuery({
    queryKey: ['bots'],
    queryFn: () => api.getBots(),
  });
}

// Components
function ComponentA() {
  const { data: bots, isLoading, error } = useBots();
  // ...
}

function ComponentB() {
  const { data: bots, isLoading, error } = useBots();
  // ...
}
```

## Replace Prop Drilling with Context

### Before (Prop Drilling)
```tsx
function App() {
  const [user, setUser] = useState<User | null>(null);

  return (
    <Layout user={user}>
      <Sidebar user={user}>
        <UserMenu user={user} setUser={setUser} />
      </Sidebar>
      <Main user={user}>
        <Header user={user} />
        <Content user={user} />
      </Main>
    </Layout>
  );
}
```

### After (Context)
```tsx
// contexts/UserContext.tsx
const UserContext = createContext<UserContextType | null>(null);

export function UserProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);

  return (
    <UserContext.Provider value={{ user, setUser }}>
      {children}
    </UserContext.Provider>
  );
}

export function useUser() {
  const context = useContext(UserContext);
  if (!context) throw new Error('useUser must be within UserProvider');
  return context;
}

// App.tsx
function App() {
  return (
    <UserProvider>
      <Layout>
        <Sidebar>
          <UserMenu />
        </Sidebar>
        <Main>
          <Header />
          <Content />
        </Main>
      </Layout>
    </UserProvider>
  );
}

// Any component
function Header() {
  const { user } = useUser();
  return <header>{user?.name}</header>;
}
```

## Simplify Complex State with Reducer

### Before (Multiple useState)
```tsx
function ChatForm() {
  const [message, setMessage] = useState('');
  const [attachments, setAttachments] = useState<File[]>([]);
  const [isUploading, setIsUploading] = useState(false);
  const [isSending, setIsSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showPreview, setShowPreview] = useState(false);

  const handleSubmit = async () => {
    setError(null);
    setIsSending(true);
    try {
      // ... complex state updates
    } catch (e) {
      setError(e.message);
    } finally {
      setIsSending(false);
    }
  };
}
```

### After (useReducer)
```tsx
type State = {
  message: string;
  attachments: File[];
  status: 'idle' | 'uploading' | 'sending';
  error: string | null;
  showPreview: boolean;
};

type Action =
  | { type: 'SET_MESSAGE'; payload: string }
  | { type: 'ADD_ATTACHMENT'; payload: File }
  | { type: 'START_SEND' }
  | { type: 'SEND_SUCCESS' }
  | { type: 'SEND_ERROR'; payload: string }
  | { type: 'RESET' };

function reducer(state: State, action: Action): State {
  switch (action.type) {
    case 'SET_MESSAGE':
      return { ...state, message: action.payload };
    case 'START_SEND':
      return { ...state, status: 'sending', error: null };
    case 'SEND_SUCCESS':
      return { ...state, status: 'idle', message: '', attachments: [] };
    case 'SEND_ERROR':
      return { ...state, status: 'idle', error: action.payload };
    default:
      return state;
  }
}

function ChatForm() {
  const [state, dispatch] = useReducer(reducer, initialState);

  const handleSubmit = async () => {
    dispatch({ type: 'START_SEND' });
    try {
      await sendMessage(state);
      dispatch({ type: 'SEND_SUCCESS' });
    } catch (e) {
      dispatch({ type: 'SEND_ERROR', payload: e.message });
    }
  };
}
```

## Extract Render Logic

### Before (Complex JSX)
```tsx
function BotList({ bots }: { bots: Bot[] }) {
  return (
    <ul>
      {bots.map(bot => (
        <li key={bot.id}>
          <div className="flex items-center gap-2">
            <span className={`
              w-2 h-2 rounded-full
              ${bot.status === 'active' ? 'bg-green-500' :
                bot.status === 'inactive' ? 'bg-gray-500' :
                bot.status === 'error' ? 'bg-red-500' : 'bg-yellow-500'}
            `} />
            <span>{bot.name}</span>
            {bot.isNew && <Badge>New</Badge>}
            {bot.hasUpdates && <Badge variant="warning">Updates</Badge>}
          </div>
        </li>
      ))}
    </ul>
  );
}
```

### After (Extracted Components/Functions)
```tsx
// Utility function
function getStatusColor(status: Bot['status']): string {
  const colors = {
    active: 'bg-green-500',
    inactive: 'bg-gray-500',
    error: 'bg-red-500',
    pending: 'bg-yellow-500',
  };
  return colors[status] ?? 'bg-gray-500';
}

// Small component
function StatusIndicator({ status }: { status: Bot['status'] }) {
  return (
    <span className={`w-2 h-2 rounded-full ${getStatusColor(status)}`} />
  );
}

// Clean main component
function BotList({ bots }: { bots: Bot[] }) {
  return (
    <ul>
      {bots.map(bot => (
        <BotListItem key={bot.id} bot={bot} />
      ))}
    </ul>
  );
}

function BotListItem({ bot }: { bot: Bot }) {
  return (
    <li className="flex items-center gap-2">
      <StatusIndicator status={bot.status} />
      <span>{bot.name}</span>
      {bot.isNew && <Badge>New</Badge>}
      {bot.hasUpdates && <Badge variant="warning">Updates</Badge>}
    </li>
  );
}
```

## Replace Class Component with Function

### Before (Class Component)
```tsx
class Timer extends Component<Props, State> {
  state = { seconds: 0 };
  interval: NodeJS.Timeout | null = null;

  componentDidMount() {
    this.interval = setInterval(() => {
      this.setState(s => ({ seconds: s.seconds + 1 }));
    }, 1000);
  }

  componentWillUnmount() {
    if (this.interval) clearInterval(this.interval);
  }

  render() {
    return <div>Seconds: {this.state.seconds}</div>;
  }
}
```

### After (Function Component)
```tsx
function Timer() {
  const [seconds, setSeconds] = useState(0);

  useEffect(() => {
    const interval = setInterval(() => {
      setSeconds(s => s + 1);
    }, 1000);

    return () => clearInterval(interval);
  }, []);

  return <div>Seconds: {seconds}</div>;
}
```

## Migrate to React Query

### Before (Manual Fetching)
```tsx
function BotSettings({ botId }: { botId: string }) {
  const [settings, setSettings] = useState<Settings | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    api.getSettings(botId)
      .then(setSettings)
      .finally(() => setLoading(false));
  }, [botId]);

  const handleSave = async (data: Settings) => {
    setSaving(true);
    await api.updateSettings(botId, data);
    setSettings(data);
    setSaving(false);
  };
}
```

### After (React Query)
```tsx
function BotSettings({ botId }: { botId: string }) {
  const { data: settings, isLoading } = useQuery({
    queryKey: ['bot-settings', botId],
    queryFn: () => api.getSettings(botId),
  });

  const { mutate: updateSettings, isPending: saving } = useMutation({
    mutationFn: (data: Settings) => api.updateSettings(botId, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bot-settings', botId] });
    },
  });
}
```

## Checklist Before Refactoring

- [ ] TypeScript errors resolved
- [ ] Tests passing
- [ ] Understand component responsibilities
- [ ] Identify reusable patterns
- [ ] Plan component boundaries
- [ ] Consider state management
