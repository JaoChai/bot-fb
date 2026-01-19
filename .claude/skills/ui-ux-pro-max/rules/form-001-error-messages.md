---
id: form-001-error-messages
title: Clear Error Messages Near Input
impact: HIGH
impactDescription: "Vague or distant errors leave users confused about what's wrong"
category: form
tags: [validation, errors, feedback, forms]
relatedRules: [form-003-inline-validation, ux-007-feedback-states]
platforms: [All]
---

## Why This Matters

Users need to understand exactly what went wrong and where. Error messages at the top of a form with no field indication force users to hunt for the problem. Vague messages like "Invalid input" provide no guidance.

## The Problem

```html
<!-- Bad: Error at top, no field indication -->
<form>
  <div class="text-red-500">There was an error. Please try again.</div>
  <input name="email" /> <!-- Which field has the error? -->
  <input name="password" />
</form>

<!-- Bad: Vague error message -->
<input class="border-red-500" />
<span class="text-red-500">Invalid</span> <!-- Invalid how? -->
```

## Solution

### Error Adjacent to Field

```html
<!-- Good: Error directly under field -->
<div class="space-y-1">
  <label for="email">Email</label>
  <input
    id="email"
    type="email"
    class="border-red-500"
    aria-describedby="email-error"
    aria-invalid="true"
  />
  <p id="email-error" class="text-sm text-red-500">
    Please enter a valid email address (e.g., name@example.com)
  </p>
</div>
```

### Specific Error Messages

```tsx
// Good: Specific, actionable messages
const errorMessages = {
  email: {
    required: 'Email address is required',
    invalid: 'Please enter a valid email (e.g., name@example.com)',
    taken: 'This email is already registered. Try logging in instead.',
  },
  password: {
    required: 'Password is required',
    minLength: 'Password must be at least 8 characters',
    weak: 'Add a number or symbol to make your password stronger',
  },
};
```

### React Hook Form Pattern

```tsx
import { useForm } from 'react-hook-form';

function SignupForm() {
  const { register, handleSubmit, formState: { errors } } = useForm();

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <div className="space-y-1">
        <Label htmlFor="email">Email</Label>
        <Input
          id="email"
          {...register('email', {
            required: 'Email is required',
            pattern: {
              value: /^[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}$/i,
              message: 'Please enter a valid email address',
            },
          })}
          className={errors.email && 'border-destructive'}
          aria-invalid={!!errors.email}
        />
        {errors.email && (
          <p className="text-sm text-destructive">
            {errors.email.message}
          </p>
        )}
      </div>
    </form>
  );
}
```

## Quick Reference

| Bad Message | Good Message |
|-------------|--------------|
| "Invalid" | "Please enter a valid email (e.g., name@example.com)" |
| "Error" | "Password must be at least 8 characters" |
| "Required" | "Please enter your name" |
| "Too short" | "Username must be at least 3 characters" |
| "Already exists" | "This email is already registered. Log in instead?" |

## Accessibility Requirements

```html
<!-- Required aria attributes -->
<input
  aria-invalid="true"          <!-- Indicates error state -->
  aria-describedby="field-error" <!-- Links to error message -->
/>
<p
  id="field-error"
  role="alert"                   <!-- Announces to screen readers -->
  class="text-sm text-red-500"
>
  Error message here
</p>
```

## Testing

- [ ] Each field shows its own error message
- [ ] Error appears directly below the field
- [ ] Message explains what's wrong and how to fix it
- [ ] Screen reader announces errors (`role="alert"`)

## Project-Specific Notes

**BotFacebook Context:**
- Use React Hook Form for form validation
- shadcn/ui Form component handles error display
- Error messages in Thai when applicable
- Use `text-destructive` for error styling
