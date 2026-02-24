# React 19 Patterns (SPA - Vite + React Router)

> **Note**: This project is a Single Page Application (SPA) using Vite + React Router.
> Server Components and 'use client' directives are NOT applicable here.
> All components are client components by default.

## use() Hook (React 19)

```tsx
import { use } from 'react';

function UserProfile({ userPromise }) {
  const user = use(userPromise); // Suspends until resolved
  return <h1>{user.name}</h1>;
}
```

## Form Actions (React 19)

```tsx
'use client';

import { useActionState } from 'react';

function LoginForm() {
  const [state, formAction, isPending] = useActionState(
    async (prevState, formData) => {
      const email = formData.get('email');
      const result = await loginAction(email);
      return result;
    },
    null
  );

  return (
    <form action={formAction}>
      <input name="email" type="email" />
      <button disabled={isPending}>
        {isPending ? 'Loading...' : 'Login'}
      </button>
      {state?.error && <p>{state.error}</p>}
    </form>
  );
}
```

## useOptimistic (React 19)

```tsx
'use client';

import { useOptimistic } from 'react';

function MessageList({ messages, sendMessage }) {
  const [optimisticMessages, addOptimistic] = useOptimistic(
    messages,
    (state, newMessage) => [...state, { ...newMessage, sending: true }]
  );

  async function handleSubmit(formData) {
    const text = formData.get('text');
    addOptimistic({ text, id: Date.now() });
    await sendMessage(text);
  }

  return (
    <>
      {optimisticMessages.map(m => (
        <Message key={m.id} message={m} />
      ))}
      <form action={handleSubmit}>
        <input name="text" />
        <button>Send</button>
      </form>
    </>
  );
}
```

## Component Patterns

### Compound Components

```tsx
const Accordion = ({ children }) => {
  const [openIndex, setOpenIndex] = useState(null);
  return (
    <AccordionContext.Provider value={{ openIndex, setOpenIndex }}>
      {children}
    </AccordionContext.Provider>
  );
};

Accordion.Item = ({ index, children }) => {
  const { openIndex, setOpenIndex } = useContext(AccordionContext);
  const isOpen = openIndex === index;
  return (
    <div>
      <button onClick={() => setOpenIndex(isOpen ? null : index)}>
        Toggle
      </button>
      {isOpen && children}
    </div>
  );
};
```

### Render Props

```tsx
function DataFetcher({ url, children }) {
  const { data, isLoading } = useQuery({ queryKey: [url], queryFn: () => fetch(url) });
  return children({ data, isLoading });
}

// Usage
<DataFetcher url="/api/users">
  {({ data, isLoading }) => isLoading ? <Spinner /> : <UserList users={data} />}
</DataFetcher>
```

### Higher-Order Components

```tsx
function withAuth(Component) {
  return function AuthenticatedComponent(props) {
    const { user, isLoading } = useAuth();

    if (isLoading) return <Spinner />;
    if (!user) return <Navigate to="/login" />;

    return <Component {...props} user={user} />;
  };
}
```

## Performance Patterns

### Memoization

```tsx
// Memoize expensive computations
const sortedItems = useMemo(
  () => items.sort((a, b) => a.name.localeCompare(b.name)),
  [items]
);

// Memoize callbacks
const handleClick = useCallback(
  (id) => dispatch({ type: 'SELECT', id }),
  [dispatch]
);

// Memoize components
const MemoizedList = memo(function List({ items }) {
  return items.map(item => <Item key={item.id} {...item} />);
});
```

### Code Splitting

```tsx
const Dashboard = lazy(() => import('./pages/Dashboard'));
const Settings = lazy(() => import('./pages/Settings'));

function App() {
  return (
    <Suspense fallback={<PageLoader />}>
      <Routes>
        <Route path="/dashboard" element={<Dashboard />} />
        <Route path="/settings" element={<Settings />} />
      </Routes>
    </Suspense>
  );
}
```

## Error Handling

### Error Boundary

```tsx
class ErrorBoundary extends Component {
  state = { hasError: false, error: null };

  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }

  componentDidCatch(error, errorInfo) {
    Sentry.captureException(error, { extra: errorInfo });
  }

  render() {
    if (this.state.hasError) {
      return <ErrorFallback error={this.state.error} />;
    }
    return this.props.children;
  }
}
```

## TypeScript Patterns

### Generic Components

```tsx
interface ListProps<T> {
  items: T[];
  renderItem: (item: T) => ReactNode;
  keyExtractor: (item: T) => string;
}

function List<T>({ items, renderItem, keyExtractor }: ListProps<T>) {
  return (
    <ul>
      {items.map(item => (
        <li key={keyExtractor(item)}>{renderItem(item)}</li>
      ))}
    </ul>
  );
}
```

### Discriminated Unions

```tsx
type State =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'success'; data: User }
  | { status: 'error'; error: Error };

function UserCard({ state }: { state: State }) {
  switch (state.status) {
    case 'idle': return <IdleState />;
    case 'loading': return <Spinner />;
    case 'success': return <UserInfo user={state.data} />;
    case 'error': return <ErrorMessage error={state.error} />;
  }
}
```
