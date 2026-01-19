---
id: react-004-use-action-state
title: useActionState for Forms (React 19)
impact: HIGH
impactDescription: "Simplifies form handling with built-in pending state and progressive enhancement"
category: react
tags: [react-19, forms, hooks, actions, pending-state]
relatedRules: [gotcha-005-form-submit-button, a11y-002-keyboard-navigation]
---

## Why This Matters

React 19's `useActionState` provides a cleaner way to handle form submissions with automatic pending state management. It replaces the pattern of manual loading state with useState + async handlers.

The hook returns the current state, a form action, and a pending boolean - everything needed for form UX.

## Bad Example

```tsx
// Problem: Manual loading state and error handling
function LoginForm() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError(null);

    try {
      await loginUser({ email, password });
    } catch (err) {
      setError(err.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <input
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        disabled={loading}
      />
      <input
        type="password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        disabled={loading}
      />
      {error && <p className="text-red-500">{error}</p>}
      <button disabled={loading}>
        {loading ? 'Logging in...' : 'Login'}
      </button>
    </form>
  );
}
```

**Why it's wrong:**
- Lots of boilerplate for loading and error states
- Must prevent default and manage event manually
- Controlled inputs require onChange handlers
- Easy to forget to reset loading state

## Good Example

```tsx
// Solution: useActionState for cleaner form handling
'use client';

import { useActionState } from 'react';

interface FormState {
  error: string | null;
  success: boolean;
}

async function loginAction(
  prevState: FormState,
  formData: FormData
): Promise<FormState> {
  const email = formData.get('email') as string;
  const password = formData.get('password') as string;

  try {
    await loginUser({ email, password });
    return { error: null, success: true };
  } catch (err) {
    return { error: err.message, success: false };
  }
}

function LoginForm() {
  const [state, formAction, isPending] = useActionState(loginAction, {
    error: null,
    success: false,
  });

  return (
    <form action={formAction}>
      <input
        name="email"
        type="email"
        required
        disabled={isPending}
        className="w-full rounded border p-2"
      />
      <input
        name="password"
        type="password"
        required
        disabled={isPending}
        className="w-full rounded border p-2"
      />

      {state.error && (
        <p className="text-sm text-red-500">{state.error}</p>
      )}

      <button
        type="submit"
        disabled={isPending}
        className="w-full rounded bg-primary p-2 text-white"
      >
        {isPending ? 'Logging in...' : 'Login'}
      </button>
    </form>
  );
}

// Works with mutations too
function CreateBotForm() {
  const queryClient = useQueryClient();

  const [state, formAction, isPending] = useActionState(
    async (prevState, formData) => {
      try {
        const name = formData.get('name') as string;
        await api.post('/api/v1/bots', { name });
        queryClient.invalidateQueries({ queryKey: ['bots'] });
        return { error: null, success: true };
      } catch (err) {
        return { error: err.message, success: false };
      }
    },
    { error: null, success: false }
  );

  return (
    <form action={formAction}>
      <input name="name" required />
      <button type="submit" disabled={isPending}>
        {isPending ? 'Creating...' : 'Create Bot'}
      </button>
    </form>
  );
}
```

**Why it's better:**
- No manual event.preventDefault()
- Automatic pending state via `isPending`
- Works with native FormData
- Progressive enhancement (works without JS)
- State persists across submissions for error display

## Project-Specific Notes

**When to use useActionState vs React Hook Form:**

| Scenario | Recommendation |
|----------|----------------|
| Simple forms (1-3 fields) | useActionState |
| Complex validation | React Hook Form |
| Multi-step forms | React Hook Form |
| File uploads | useActionState + FormData |
| Real-time validation | React Hook Form |

**BotFacebook Pattern:**
```tsx
// For API calls, combine with React Query for cache invalidation
const [state, formAction, isPending] = useActionState(
  async (prev, formData) => {
    const result = await createBotMutation.mutateAsync({
      name: formData.get('name'),
    });
    return { success: true, botId: result.id };
  },
  { success: false, botId: null }
);
```

## References

- [React useActionState](https://react.dev/reference/react/useActionState)
- [Form Actions](https://react.dev/reference/react-dom/components/form)
- Related rule: gotcha-005-form-submit-button
