---
id: ux-007-feedback-states
title: Success & Error Feedback
impact: HIGH
impactDescription: "Silent success/failure leaves users uncertain if action worked"
category: ux
tags: [feedback, toast, success, error, states]
relatedRules: [ux-001-loading-states, form-001-error-messages]
platforms: [All]
---

## Why This Matters

After clicking a button, users need confirmation that something happened. Without feedback, they'll click again, wonder if it's broken, or assume failure.

## The Problem

```tsx
// Bad: Silent success
async function handleDelete() {
  await api.delete(id);
  // User sees... nothing
}

// Bad: Alert (blocks UI, poor UX)
async function handleDelete() {
  await api.delete(id);
  alert('Deleted!'); // Jarring, requires dismissal
}
```

## Solution

### Toast Notifications

```tsx
import { toast } from 'sonner';

async function handleDelete() {
  try {
    await api.delete(id);
    toast.success('Item deleted');
  } catch (error) {
    toast.error('Failed to delete item');
  }
}
```

### Inline Feedback

```tsx
function SaveButton() {
  const [status, setStatus] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle');

  const handleSave = async () => {
    setStatus('saving');
    try {
      await api.save(data);
      setStatus('saved');
      setTimeout(() => setStatus('idle'), 2000);
    } catch {
      setStatus('error');
    }
  };

  return (
    <button onClick={handleSave} disabled={status === 'saving'}>
      {status === 'idle' && 'Save'}
      {status === 'saving' && 'Saving...'}
      {status === 'saved' && '✓ Saved'}
      {status === 'error' && 'Failed - Retry'}
    </button>
  );
}
```

### Visual State Changes

```tsx
// Good: Visual feedback on card after action
function BotCard({ bot }) {
  const { mutate, isSuccess } = useDeleteBot();

  return (
    <Card className={cn(
      "transition-opacity duration-300",
      isSuccess && "opacity-50" // Fading indicates deletion
    )}>
      <CardContent>
        <button onClick={() => mutate(bot.id)}>
          Delete
        </button>
      </CardContent>
    </Card>
  );
}
```

## Quick Reference

| Action Type | Feedback Type | Duration |
|-------------|---------------|----------|
| Form submit | Toast | 3-5 seconds |
| Delete | Toast + visual | 3-5 seconds |
| Settings save | Inline "Saved" | 2 seconds |
| Copy to clipboard | Toast | 2 seconds |
| Error | Toast (persistent) | User dismisses |

## Toast Patterns (Sonner)

```tsx
// Success
toast.success('Settings saved');

// Error
toast.error('Failed to save', {
  description: 'Please try again',
  action: { label: 'Retry', onClick: handleRetry },
});

// Promise-based
toast.promise(saveData(), {
  loading: 'Saving...',
  success: 'Saved!',
  error: 'Failed to save',
});

// Custom duration
toast.success('Copied!', { duration: 2000 });
```

## Testing

- [ ] Every async action has success feedback
- [ ] Errors show clear message, not silent fail
- [ ] Toasts auto-dismiss (except critical errors)
- [ ] Visual state changes match action result

## Project-Specific Notes

**BotFacebook Context:**
- Use Sonner via `<Toaster>` in App layout
- Import `toast` from `sonner`
- Use `toast.promise()` for mutations
- Keep success messages brief (2-3 words)
