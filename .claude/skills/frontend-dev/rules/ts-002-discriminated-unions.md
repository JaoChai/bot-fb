---
id: ts-002-discriminated-unions
title: Discriminated Unions for State
impact: MEDIUM
impactDescription: "Models complex states safely with exhaustive type checking"
category: ts
tags: [typescript, unions, state, pattern]
relatedRules: [ts-001-no-any]
---

## Why This Matters

Discriminated unions use a common property (discriminant) to distinguish between variants. TypeScript can narrow the type based on this property, ensuring you handle all cases and access only valid properties.

This is essential for modeling states like loading/error/success or different message types.

## Bad Example

```tsx
// Problem 1: Optional properties for different states
interface QueryState {
  isLoading: boolean;
  isError: boolean;
  data?: User[];
  error?: Error;
}

function UserList({ state }: { state: QueryState }) {
  if (state.isLoading) {
    return <Spinner />;
  }

  if (state.isError) {
    return <div>{state.error?.message}</div>;
    // Why optional? If isError, error should exist!
  }

  return <List users={state.data} />;
  // data could be undefined even when not loading/error
}

// Problem 2: Union without discriminant
type Message = { text: string } | { imageUrl: string };

function renderMessage(msg: Message) {
  if (msg.text) { // TypeScript error: Property 'text' does not exist
    return <p>{msg.text}</p>;
  }
  // Can't distinguish reliably
}

// Problem 3: Boolean flags for states
interface FormState {
  isSubmitting: boolean;
  isSuccess: boolean;
  isError: boolean;
  error: string | null;
}
// Can isSubmitting and isSuccess both be true? Unclear!
```

**Why it's wrong:**
- Optional properties allow invalid combinations
- No discriminant means manual type checking
- Boolean flags can have impossible states
- TypeScript can't verify exhaustive handling

## Good Example

```tsx
// Solution 1: Discriminated union with status
type QueryState<T> =
  | { status: 'idle' }
  | { status: 'loading' }
  | { status: 'success'; data: T }
  | { status: 'error'; error: Error };

function UserList({ state }: { state: QueryState<User[]> }) {
  switch (state.status) {
    case 'idle':
      return <p>Select a filter to load users</p>;

    case 'loading':
      return <Spinner />;

    case 'success':
      // TypeScript knows state.data exists here
      return <List users={state.data} />;

    case 'error':
      // TypeScript knows state.error exists here
      return <ErrorMessage error={state.error} />;

    default:
      // Exhaustiveness check
      const _exhaustive: never = state;
      return _exhaustive;
  }
}

// Solution 2: Message types with kind discriminant
type Message =
  | { kind: 'text'; text: string; sender: string }
  | { kind: 'image'; imageUrl: string; caption?: string }
  | { kind: 'file'; fileName: string; fileSize: number }
  | { kind: 'system'; action: 'joined' | 'left'; user: string };

function MessageBubble({ message }: { message: Message }) {
  switch (message.kind) {
    case 'text':
      return <p>{message.text}</p>;

    case 'image':
      return (
        <figure>
          <img src={message.imageUrl} alt={message.caption || ''} />
          {message.caption && <figcaption>{message.caption}</figcaption>}
        </figure>
      );

    case 'file':
      return (
        <a href={`/download/${message.fileName}`}>
          {message.fileName} ({message.fileSize} bytes)
        </a>
      );

    case 'system':
      return (
        <p className="text-center text-muted">
          {message.user} {message.action} the chat
        </p>
      );
  }
}

// Solution 3: Form state machine
type FormState =
  | { status: 'editing'; values: FormValues; errors: Record<string, string> }
  | { status: 'submitting'; values: FormValues }
  | { status: 'success'; result: SubmitResult }
  | { status: 'error'; values: FormValues; error: string };

function ContactForm({ state, dispatch }: FormProps) {
  switch (state.status) {
    case 'editing':
      return (
        <form onSubmit={() => dispatch({ type: 'submit' })}>
          <Input
            value={state.values.email}
            error={state.errors.email}
          />
          <Button type="submit">Send</Button>
        </form>
      );

    case 'submitting':
      return <Spinner />;

    case 'success':
      return <SuccessMessage ticketId={state.result.ticketId} />;

    case 'error':
      return (
        <form>
          <Alert variant="error">{state.error}</Alert>
          {/* Can retry with preserved values */}
          <Input value={state.values.email} />
        </form>
      );
  }
}

// Solution 4: API response types
type ApiResponse<T> =
  | { ok: true; data: T }
  | { ok: false; error: { code: string; message: string } };

async function fetchBot(id: string): Promise<ApiResponse<Bot>> {
  try {
    const response = await api.get(`/bots/${id}`);
    return { ok: true, data: response.data.data };
  } catch (e) {
    return {
      ok: false,
      error: { code: 'FETCH_ERROR', message: e.message },
    };
  }
}

// Usage with narrowing
const result = await fetchBot('123');
if (result.ok) {
  console.log(result.data.name); // TypeScript knows data exists
} else {
  console.error(result.error.code); // TypeScript knows error exists
}

// Solution 5: Helper for exhaustive checking
function assertNever(x: never): never {
  throw new Error(`Unexpected value: ${x}`);
}

function processStatus(status: 'active' | 'pending' | 'closed') {
  switch (status) {
    case 'active': return 'green';
    case 'pending': return 'yellow';
    case 'closed': return 'gray';
    default:
      return assertNever(status); // Error if new status added
  }
}
```

**Why it's better:**
- Invalid state combinations are impossible
- TypeScript narrows types automatically
- Exhaustive checking catches missed cases
- Properties are guaranteed present when needed
- Self-documenting state machine

## Project-Specific Notes

**BotFacebook Union Patterns:**

| Type | Discriminant | Variants |
|------|-------------|----------|
| QueryState | `status` | idle, loading, success, error |
| Message | `type` | user, bot, system |
| Notification | `kind` | message, mention, alert |
| ApiResult | `ok` | true (data), false (error) |

**React Query Already Does This:**
```tsx
const { status, data, error } = useQuery(/* ... */);

if (status === 'pending') return <Spinner />;
if (status === 'error') return <Error error={error} />;
return <Display data={data} />;
```

## References

- [TypeScript Narrowing](https://www.typescriptlang.org/docs/handbook/2/narrowing.html)
- [Discriminated Unions](https://www.typescriptlang.org/docs/handbook/2/narrowing.html#discriminated-unions)
