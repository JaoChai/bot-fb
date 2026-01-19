---
id: a11y-005-aria-labels
title: Icon Buttons Need Labels
impact: CRITICAL
impactDescription: "Screen readers say nothing for icon-only buttons without labels"
category: a11y
tags: [aria-label, icons, buttons, screen-reader]
relatedRules: [a11y-003-alt-text]
platforms: [All]
---

## Why This Matters

Icon-only buttons are common in modern UI, but screen readers just say "button" if there's no text content or label. Users have no idea what clicking will do.

## The Problem

```html
<!-- Bad: No accessible name -->
<button>
  <svg><!-- trash icon --></svg>
</button>
<!-- Screen reader: "button" -->

<!-- Bad: Using title (not reliable) -->
<button title="Delete">
  <TrashIcon />
</button>
<!-- Screen reader: May or may not read "Delete" -->
```

## Solution

### Option 1: aria-label

```html
<!-- Good: Clear action label -->
<button aria-label="Delete item">
  <TrashIcon aria-hidden="true" />
</button>
<!-- Screen reader: "Delete item, button" -->
```

### Option 2: Visually Hidden Text

```html
<!-- Good: Hidden text for screen readers -->
<button>
  <TrashIcon aria-hidden="true" />
  <span class="sr-only">Delete item</span>
</button>
<!-- Screen reader: "Delete item, button" -->
```

### Option 3: Visible Text (Best)

```html
<!-- Best: Everyone sees the label -->
<button>
  <TrashIcon aria-hidden="true" />
  <span>Delete</span>
</button>
```

## Quick Reference

| Element | Solution |
|---------|----------|
| Icon button | `aria-label="Action"` |
| Icon link | `aria-label="Where it goes"` |
| Icon in button with text | `aria-hidden="true"` on icon |
| Close button | `aria-label="Close"` |
| Menu button | `aria-label="Open menu"` |

## React Pattern

```tsx
// Good: Reusable icon button
interface IconButtonProps {
  icon: React.ReactNode;
  label: string; // Required!
  onClick: () => void;
}

function IconButton({ icon, label, onClick }: IconButtonProps) {
  return (
    <button
      onClick={onClick}
      aria-label={label}
      className="p-2 hover:bg-gray-100 rounded"
    >
      <span aria-hidden="true">{icon}</span>
    </button>
  );
}

// Usage
<IconButton
  icon={<TrashIcon />}
  label="Delete conversation"
  onClick={handleDelete}
/>
```

## Common Icon Button Labels

| Icon | aria-label |
|------|------------|
| X / Close | "Close" or "Close dialog" |
| Menu (hamburger) | "Open menu" |
| Search | "Search" |
| Settings (gear) | "Settings" |
| Edit (pencil) | "Edit" or "Edit [item]" |
| Delete (trash) | "Delete" or "Delete [item]" |
| More (dots) | "More options" |
| Copy | "Copy to clipboard" |

## Testing

- [ ] Install screen reader (VoiceOver, NVDA)
- [ ] Navigate to each icon button
- [ ] Verify it announces the action
- [ ] Labels are descriptive, not just "button"

## Tailwind Classes

```
sr-only  - Visually hidden, screen reader visible
```

```css
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border-width: 0;
}
```

## Project-Specific Notes

**BotFacebook Context:**
- All icon buttons must have `aria-label`
- Use `sr-only` class for hidden text
- Add `aria-hidden="true"` to decorative icons
- shadcn/ui Dialog has built-in close button label
