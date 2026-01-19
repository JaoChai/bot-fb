---
id: form-002-label-visibility
title: Always Show Visible Labels
impact: HIGH
impactDescription: "Placeholder-only inputs confuse users and fail accessibility"
category: form
tags: [labels, placeholder, accessibility, forms]
relatedRules: [a11y-003-alt-text, form-001-error-messages]
platforms: [All]
---

## Why This Matters

When a placeholder is the only label, users forget what field they're filling in once they start typing. This fails WCAG accessibility, hurts usability, and increases errors.

## The Problem

```html
<!-- Bad: Placeholder as only label -->
<input placeholder="Email" />
<!-- Once user types, they forget what field this is -->

<!-- Bad: Label hidden, only appears on focus -->
<input placeholder="Email" class="[label appears on focus only]" />

<!-- Bad: Label disappears on input -->
<input placeholder="Email address" />
<!-- "john@..." - Wait, is this email or username? -->
```

## Solution

### Always Visible Label

```html
<!-- Good: Persistent label above input -->
<div class="space-y-1">
  <label for="email" class="text-sm font-medium">
    Email address
  </label>
  <input
    id="email"
    type="email"
    placeholder="name@example.com"
  />
</div>
```

### Label + Placeholder Together

```html
<!-- Good: Label describes, placeholder shows format -->
<div class="space-y-1">
  <label for="phone" class="text-sm font-medium">
    Phone number
    <span class="text-muted-foreground font-normal">(optional)</span>
  </label>
  <input
    id="phone"
    type="tel"
    placeholder="081-234-5678"
  />
</div>
```

### Required Field Indication

```html
<!-- Good: Clear required indication -->
<div class="space-y-1">
  <label for="password" class="text-sm font-medium">
    Password
    <span class="text-destructive">*</span>
  </label>
  <input id="password" type="password" required />
</div>

<!-- Or with text -->
<label>
  Password <span class="text-muted-foreground">(required)</span>
</label>
```

## Quick Reference

| Pattern | Usage |
|---------|-------|
| Label above | Standard for most inputs |
| Label + placeholder | Show format example |
| Label inline (left) | Horizontal forms, tables |
| Floating label | Use with caution, less accessible |

## React Component Pattern

```tsx
interface FormFieldProps {
  label: string;
  id: string;
  required?: boolean;
  placeholder?: string;
  error?: string;
}

function FormField({ label, id, required, placeholder, error }: FormFieldProps) {
  return (
    <div className="space-y-1">
      <label htmlFor={id} className="text-sm font-medium">
        {label}
        {required && <span className="text-destructive ml-1">*</span>}
      </label>
      <input
        id={id}
        placeholder={placeholder}
        required={required}
        aria-invalid={!!error}
        className={cn(
          'w-full rounded border px-3 py-2',
          error && 'border-destructive'
        )}
      />
      {error && (
        <p className="text-sm text-destructive">{error}</p>
      )}
    </div>
  );
}
```

## Accessibility Requirements

```html
<!-- Label must be associated with input -->
<label for="email">Email</label>
<input id="email" />

<!-- Or wrap input in label -->
<label>
  Email
  <input type="email" />
</label>
```

## Testing

- [ ] Every input has a visible label
- [ ] Label remains visible while typing
- [ ] Required fields are clearly marked
- [ ] Labels are properly associated (for/id)

## When Placeholder-Only is OK

```
✓ Search fields (with search icon)
✓ Single-field forms (context is clear)
✗ Multi-field forms
✗ Registration/signup forms
✗ Settings/profile forms
```

## Project-Specific Notes

**BotFacebook Context:**
- Use shadcn/ui Label and Input components
- `<Label htmlFor={id}>` for association
- Mark required fields with asterisk
- Placeholder shows format hints only
