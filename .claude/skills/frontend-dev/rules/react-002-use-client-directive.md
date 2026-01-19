---
id: react-002-use-client-directive
title: 'use client' Directive Placement
impact: MEDIUM
impactDescription: "Ensures correct client/server component boundaries in React 19"
category: react
tags: [react-19, server-components, client-components, directive]
relatedRules: [react-003-use-hook]
---

## Why This Matters

React 19 introduces Server Components as the default. Components that use client-side features (hooks, event handlers, browser APIs) need the `'use client'` directive at the top of the file.

Incorrect placement causes hydration errors or prevents features from working.

## Bad Example

```tsx
// Problem 1: Missing directive for client features
import { useState } from 'react';

export function Counter() {
  const [count, setCount] = useState(0); // Error! useState is client-only
  return (
    <button onClick={() => setCount(c => c + 1)}>
      Count: {count}
    </button>
  );
}

// Problem 2: Directive not at the very top
import { cn } from '@/lib/utils';
'use client'; // Error! Must be first line

export function Toggle() {
  const [on, setOn] = useState(false);
  return <button onClick={() => setOn(!on)}>{on ? 'On' : 'Off'}</button>;
}

// Problem 3: Unnecessary directive on server-capable component
'use client';

interface UserCardProps {
  user: User;
}

export function UserCard({ user }: UserCardProps) {
  // No hooks, no event handlers, no browser APIs
  return (
    <div>
      <h2>{user.name}</h2>
      <p>{user.email}</p>
    </div>
  );
}
```

**Why it's wrong:**
- useState, useEffect, and other hooks require client-side execution
- Directive must be the first line in the file (before imports)
- Adding `'use client'` to pure display components prevents server rendering benefits

## Good Example

```tsx
// Solution 1: Directive at the very top
'use client';

import { useState } from 'react';

export function Counter() {
  const [count, setCount] = useState(0);
  return (
    <button onClick={() => setCount(c => c + 1)}>
      Count: {count}
    </button>
  );
}

// Solution 2: Keep display components as server components
// No directive needed - this can render on the server
interface UserCardProps {
  user: User;
}

export function UserCard({ user }: UserCardProps) {
  return (
    <div className="rounded-lg border p-4">
      <h2 className="font-bold">{user.name}</h2>
      <p className="text-muted-foreground">{user.email}</p>
    </div>
  );
}

// Solution 3: Compose server and client components
// ServerComponent.tsx (no directive)
import { ClientCounter } from './ClientCounter';

export async function Dashboard() {
  const data = await fetchDashboardData(); // Server-side fetch

  return (
    <div>
      <h1>Dashboard</h1>
      <Stats data={data} />
      <ClientCounter /> {/* Client component nested in server component */}
    </div>
  );
}

// ClientCounter.tsx
'use client';

import { useState } from 'react';

export function ClientCounter() {
  const [count, setCount] = useState(0);
  return <button onClick={() => setCount(c => c + 1)}>Count: {count}</button>;
}
```

**Why it's better:**
- Directive at file top is syntactically correct
- Server components can fetch data directly
- Client components handle interactivity
- Mixing allows optimal performance

## Project-Specific Notes

**When to use 'use client':**
- Using hooks (useState, useEffect, useContext, etc.)
- Event handlers (onClick, onSubmit, etc.)
- Browser APIs (window, document, localStorage)
- Third-party libraries that require browser

**BotFacebook Note:**
Since we use Vite (not Next.js), the server/client distinction is less strict. However, following this pattern prepares code for potential SSR and keeps components optimally structured.

**Common Client Components:**
- Form inputs with state
- Interactive widgets
- Components using Zustand stores
- Real-time features with Echo

## References

- [React Server Components](https://react.dev/blog/2023/03/22/react-labs-what-we-have-been-working-on-march-2023#react-server-components)
- [use client Directive](https://react.dev/reference/rsc/use-client)
