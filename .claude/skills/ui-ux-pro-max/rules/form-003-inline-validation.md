---
id: form-003-inline-validation
title: Validate on Blur, Not Just Submit
impact: MEDIUM
impactDescription: "Submit-only validation delays feedback and frustrates users"
category: form
tags: [validation, blur, feedback, forms]
relatedRules: [form-001-error-messages, ux-007-feedback-states]
platforms: [All]
---

## Why This Matters

When validation only happens on submit, users fill out entire forms only to discover errors at the end. Inline validation provides immediate feedback, reducing errors and frustration.

## The Problem

```tsx
// Bad: Only validate on submit
function Form() {
  const handleSubmit = () => {
    // User fills 10 fields, then sees all errors at once
    if (!isValidEmail(email)) {
      setErrors({ email: 'Invalid email' });
    }
  };
}
```

## Solution

### Validate on Blur (Leave Field)

```tsx
// Good: Validate when user leaves field
function EmailInput() {
  const [email, setEmail] = useState('');
  const [error, setError] = useState('');
  const [touched, setTouched] = useState(false);

  const validate = (value: string) => {
    if (!value) return 'Email is required';
    if (!isValidEmail(value)) return 'Please enter a valid email';
    return '';
  };

  return (
    <div>
      <input
        type="email"
        value={email}
        onChange={(e) => {
          setEmail(e.target.value);
          // Clear error as user types
          if (error) setError('');
        }}
        onBlur={() => {
          setTouched(true);
          setError(validate(email));
        }}
      />
      {touched && error && (
        <p className="text-sm text-red-500">{error}</p>
      )}
    </div>
  );
}
```

### React Hook Form Pattern

```tsx
import { useForm } from 'react-hook-form';

function SignupForm() {
  const {
    register,
    handleSubmit,
    formState: { errors, touchedFields },
  } = useForm({
    mode: 'onBlur', // Validate on blur
    // mode: 'onChange', // Validate as user types (more aggressive)
  });

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      <input
        {...register('email', {
          required: 'Email is required',
          pattern: {
            value: /^[^\s@]+@[^\s@]+\.[^\s@]+$/,
            message: 'Invalid email format',
          },
        })}
      />
      {errors.email && (
        <p className="text-destructive text-sm">
          {errors.email.message}
        </p>
      )}
    </form>
  );
}
```

## Validation Timing Guide

| Event | When to Use |
|-------|-------------|
| `onBlur` | Most fields - validates when leaving |
| `onChange` | Passwords (strength), character limits |
| `onSubmit` | Final validation before sending |
| Debounced | Async validation (username availability) |

## Best Practices

```tsx
// Good: Clear errors while typing
onChange={(e) => {
  setValue(e.target.value);
  if (error) setError(''); // Clear error as user corrects
}}

// Good: Only show error after interaction
{touched && error && <ErrorMessage>{error}</ErrorMessage>}

// Good: Don't validate empty fields until touched
onBlur={() => {
  setTouched(true);
  if (value || touched) validate();
}}
```

## Async Validation (Username Check)

```tsx
// Good: Debounced async validation
const checkUsername = useDebouncedCallback(async (value: string) => {
  if (value.length >= 3) {
    const available = await api.checkUsername(value);
    if (!available) {
      setError('Username is already taken');
    }
  }
}, 500);

<input
  onChange={(e) => {
    setValue(e.target.value);
    checkUsername(e.target.value);
  }}
/>
```

## Testing

- [ ] Error shows when leaving invalid field
- [ ] Error clears as user corrects input
- [ ] Empty fields don't show error until touched
- [ ] Async validation shows loading state

## Project-Specific Notes

**BotFacebook Context:**
- Use React Hook Form with `mode: 'onBlur'`
- shadcn/ui Form integrates with React Hook Form
- Use `useDebouncedCallback` for async checks
- Zod schemas for validation rules
