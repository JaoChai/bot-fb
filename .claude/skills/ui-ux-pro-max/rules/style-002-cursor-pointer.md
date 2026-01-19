---
id: style-002-cursor-pointer
title: Clickable Elements Need cursor-pointer
impact: MEDIUM
impactDescription: "Missing pointer cursor makes interactive elements feel broken"
category: style
tags: [cursor, interaction, feedback, buttons]
relatedRules: [style-003-hover-states, ux-006-disabled-states]
platforms: [Web]
---

## Why This Matters

Users expect the cursor to change to a pointer finger when hovering over clickable elements. Without this, interactive elements feel broken or unresponsive, reducing trust in your UI.

## The Problem

```html
<!-- Bad: Clickable card without pointer -->
<div onClick={handleClick} class="bg-white p-4 rounded">
  Click me (but cursor stays arrow)
</div>

<!-- Bad: Custom button without pointer -->
<span role="button" onClick={doSomething}>
  No cursor change
</span>
```

## Solution

```html
<!-- Good: Add cursor-pointer to clickable elements -->
<div
  onClick={handleClick}
  class="bg-white p-4 rounded cursor-pointer hover:bg-gray-50"
>
  Click me
</div>

<!-- Good: Card with proper interaction states -->
<div
  onClick={handleClick}
  class="
    cursor-pointer
    transition-colors duration-150
    hover:bg-gray-50
    active:bg-gray-100
  "
>
  Interactive card
</div>

<!-- Good: Use button element (has pointer by default) -->
<button type="button" class="bg-white p-4 rounded">
  Already has cursor-pointer
</button>
```

## Quick Reference

| Element | Has Pointer? | Action |
|---------|-------------|--------|
| `<button>` | Yes | None needed |
| `<a href>` | Yes | None needed |
| `<div onClick>` | No | Add `cursor-pointer` |
| `<span role="button">` | No | Add `cursor-pointer` |
| `<label for>` | No | Add `cursor-pointer` |
| Disabled elements | No | Use `cursor-not-allowed` |

## Tailwind Classes

```
cursor-pointer     - Pointing hand (clickable)
cursor-default     - Arrow (non-interactive)
cursor-not-allowed - Circle with line (disabled)
cursor-wait        - Hourglass (loading)
cursor-grab        - Open hand (draggable)
cursor-grabbing    - Closed hand (dragging)
cursor-text        - I-beam (text selection)
```

## Component Pattern

```tsx
// Good: Clickable card component
interface CardProps {
  onClick?: () => void;
  children: React.ReactNode;
}

function Card({ onClick, children }: CardProps) {
  const isClickable = !!onClick;

  return (
    <div
      onClick={onClick}
      role={isClickable ? 'button' : undefined}
      tabIndex={isClickable ? 0 : undefined}
      className={cn(
        'bg-white p-4 rounded',
        isClickable && 'cursor-pointer hover:bg-gray-50'
      )}
    >
      {children}
    </div>
  );
}
```

## Labels for Inputs

```html
<!-- Good: Labels should have pointer cursor -->
<label class="cursor-pointer flex items-center gap-2">
  <input type="checkbox" />
  <span>Check this option</span>
</label>
```

## Testing

- [ ] Hover over all interactive elements
- [ ] Cursor changes to pointer
- [ ] Disabled elements show not-allowed cursor
- [ ] Labels for inputs show pointer

## Project-Specific Notes

**BotFacebook Context:**
- Bot cards need `cursor-pointer`
- Conversation list items need `cursor-pointer`
- shadcn/ui Button has pointer by default
- Add to custom clickable divs and labels
