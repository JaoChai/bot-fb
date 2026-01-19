---
id: gotcha-005-form-submit-button
title: Form Submit Button Type Attribute
impact: MEDIUM
impactDescription: "Ensures forms submit correctly when pressing Enter or clicking submit button"
category: gotcha
tags: [forms, button, submit, html, accessibility]
relatedRules: [a11y-002-keyboard-navigation]
---

## Why This Matters

HTML buttons inside forms default to `type="submit"`, but this default can be affected by component libraries or button wrappers. If a button doesn't have `type="submit"`, pressing Enter in form fields won't submit the form, and clicking the button might not work as expected.

This is a subtle bug because the button appears to work (it's clickable) but the form submission doesn't trigger.

## Bad Example

```tsx
// Problem 1: Button type gets lost in wrapper
// components/ui/button.tsx
const Button = ({ children, ...props }) => (
  <button className="btn" {...props}>{children}</button>
);

// Usage - type is not explicitly set
function LoginForm() {
  return (
    <form onSubmit={handleSubmit}>
      <input name="email" type="email" />
      <input name="password" type="password" />
      <Button>Login</Button> {/* No type="submit"! */}
    </form>
  );
}

// Problem 2: Using div instead of button
function SearchForm() {
  return (
    <form onSubmit={handleSearch}>
      <input name="query" />
      <div onClick={handleSearch} className="btn">
        Search {/* Not a real button, no keyboard support */}
      </div>
    </form>
  );
}
```

**Why it's wrong:**
- If Button component doesn't preserve type, form won't submit on Enter
- `<div>` elements don't participate in form submission
- Keyboard users can't submit using Enter key
- Screen readers don't announce it as a submit button

## Good Example

```tsx
// Solution 1: Always set type="submit" explicitly
function LoginForm() {
  return (
    <form onSubmit={handleSubmit}>
      <input name="email" type="email" />
      <input name="password" type="password" />
      <Button type="submit">Login</Button>
    </form>
  );
}

// Solution 2: Default to submit in form context
// components/ui/button.tsx
interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'default' | 'outline';
}

const Button = ({ type = 'button', children, ...props }: ButtonProps) => (
  <button type={type} className="btn" {...props}>
    {children}
  </button>
);

// Usage with explicit type
<Button type="submit">Submit</Button>
<Button type="button" onClick={handleClick}>Cancel</Button>

// Solution 3: Use proper form elements
function SearchForm() {
  return (
    <form onSubmit={handleSearch}>
      <input name="query" />
      <button type="submit" className="btn">
        Search
      </button>
    </form>
  );
}

// Solution 4: React 19 form actions
function CommentForm() {
  const [state, formAction, isPending] = useActionState(
    async (prevState, formData) => {
      return await submitComment(formData);
    },
    null
  );

  return (
    <form action={formAction}>
      <textarea name="comment" />
      <button type="submit" disabled={isPending}>
        {isPending ? 'Posting...' : 'Post Comment'}
      </button>
    </form>
  );
}
```

**Why it's better:**
- `type="submit"` explicitly declares button's role
- Form submission works with Enter key
- Screen readers announce it correctly
- Consistent behavior across browsers

## Project-Specific Notes

**Our Button Component:**
```tsx
// src/components/ui/button.tsx
// Check the default type behavior
import { Button } from '@/components/ui/button';

// Always be explicit in forms
<form onSubmit={onSubmit}>
  <Button type="submit">Save</Button>
  <Button type="button" variant="outline" onClick={onCancel}>
    Cancel
  </Button>
</form>
```

**Button Types Reference:**
| Type | Behavior |
|------|----------|
| `submit` | Submits the form |
| `button` | No default behavior (for onClick handlers) |
| `reset` | Resets form fields to initial values |

**Testing Form Submission:**
1. Fill in form fields
2. Press Enter - form should submit
3. Click submit button - form should submit
4. Tab to button and press Space/Enter - form should submit

## References

- [MDN Button Types](https://developer.mozilla.org/en-US/docs/Web/HTML/Element/button#type)
- [WCAG Form Submission](https://www.w3.org/WAI/tutorials/forms/instructions/)
- Related rule: a11y-002-keyboard-navigation
