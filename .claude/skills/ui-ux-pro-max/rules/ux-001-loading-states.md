---
id: ux-001-loading-states
title: Always Show Loading States
impact: HIGH
impactDescription: "Frozen UI without feedback makes users think app crashed"
category: ux
tags: [loading, feedback, async, skeleton]
relatedRules: [ux-007-feedback-states, form-001-error-messages]
platforms: [All]
---

## Why This Matters

When users trigger an action and see no response, they assume the app is broken. They'll click again, potentially causing duplicate submissions or abandoning entirely.

## The Problem

```tsx
// Bad: No loading indication
function SubmitButton() {
  const handleClick = async () => {
    await api.submit(data); // User sees nothing for 2-3 seconds
  };
  return <button onClick={handleClick}>Submit</button>;
}
```

## Solution

```tsx
// Good: Clear loading state
function SubmitButton() {
  const [isLoading, setIsLoading] = useState(false);

  const handleClick = async () => {
    setIsLoading(true);
    try {
      await api.submit(data);
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <button onClick={handleClick} disabled={isLoading}>
      {isLoading ? (
        <>
          <Spinner className="animate-spin mr-2" />
          Submitting...
        </>
      ) : (
        'Submit'
      )}
    </button>
  );
}
```

### Skeleton Loading for Content

```tsx
// Good: Skeleton placeholder
function UserList() {
  const { data, isLoading } = useUsers();

  if (isLoading) {
    return (
      <div className="space-y-4">
        {[...Array(3)].map((_, i) => (
          <div key={i} className="animate-pulse">
            <div className="h-12 bg-gray-200 rounded" />
          </div>
        ))}
      </div>
    );
  }

  return <ul>{data.map(user => <UserItem user={user} />)}</ul>;
}
```

## Quick Reference

| Operation Duration | Loading Type |
|-------------------|--------------|
| < 300ms | No indicator needed |
| 300ms - 1s | Spinner |
| > 1s | Skeleton or progress |
| Unknown duration | Skeleton + message |

## Tailwind Classes

```
animate-spin      - Spinner rotation
animate-pulse     - Skeleton pulsing
opacity-50        - Disabled appearance
cursor-wait       - Loading cursor
```

## Testing

- [ ] Click button - loading state appears immediately
- [ ] Loading state prevents double-click
- [ ] Content areas show skeleton while loading
- [ ] Long operations show progress or status message

## Project-Specific Notes

**BotFacebook Context:**
- Use `<Skeleton>` from shadcn/ui for content loading
- Use `isLoading` state from React Query mutations
- Disable buttons during submission with `disabled={isPending}`
