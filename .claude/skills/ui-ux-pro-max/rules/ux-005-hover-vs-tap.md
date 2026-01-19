---
id: ux-005-hover-vs-tap
title: Hover Effects Don't Work on Touch
impact: HIGH
impactDescription: "Content hidden behind hover is inaccessible on mobile"
category: ux
tags: [touch, hover, mobile, interaction]
relatedRules: [responsive-001-touch-targets, style-002-cursor-pointer]
platforms: [All]
---

## Why This Matters

50%+ of web traffic is mobile. Hover states don't exist on touchscreens. If important actions or information require hovering, mobile users can't access them.

## The Problem

```tsx
// Bad: Action only visible on hover
function Card() {
  return (
    <div className="group">
      <h3>Product</h3>
      {/* Mobile users will NEVER see this */}
      <button className="opacity-0 group-hover:opacity-100">
        Delete
      </button>
    </div>
  );
}
```

## Solution

### Option 1: Always Visible Actions

```tsx
// Good: Actions always visible
function Card() {
  return (
    <div>
      <h3>Product</h3>
      <button className="opacity-70 hover:opacity-100">
        Delete
      </button>
    </div>
  );
}
```

### Option 2: Tap to Reveal on Mobile

```tsx
// Good: Different interaction per device
function Card() {
  const [showActions, setShowActions] = useState(false);

  return (
    <div
      className="group"
      onClick={() => setShowActions(!showActions)} // Mobile tap
    >
      <h3>Product</h3>
      <button className={cn(
        "transition-opacity",
        // Desktop: hover
        "md:opacity-0 md:group-hover:opacity-100",
        // Mobile: tap toggle
        showActions ? "opacity-100" : "opacity-0 md:opacity-0"
      )}>
        Delete
      </button>
    </div>
  );
}
```

### Option 3: Use Dropdown Menu

```tsx
// Good: Works on both desktop and mobile
function Card() {
  return (
    <div>
      <h3>Product</h3>
      <DropdownMenu>
        <DropdownMenuTrigger>
          <MoreHorizontal className="h-5 w-5" />
        </DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuItem>Edit</DropdownMenuItem>
          <DropdownMenuItem className="text-destructive">
            Delete
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}
```

## Quick Reference

| Pattern | Desktop | Mobile | Recommendation |
|---------|---------|--------|----------------|
| Hover-only actions | Works | Broken | Avoid |
| Always visible | Works | Works | Best for important actions |
| Dropdown menu | Works | Works | Best for secondary actions |
| Long press | N/A | Works | Rarely expected |

## Tailwind Classes

```
group-hover:*      - Only works on desktop
md:group-hover:*   - Hover only on md+ screens
@media (hover: hover) - CSS hover capability query
```

## Testing

- [ ] Test on real mobile device (not just DevTools)
- [ ] All important actions accessible without hover
- [ ] Touch and click both work
- [ ] No "hover to see" tooltips for critical info

## Project-Specific Notes

**BotFacebook Context:**
- Use `<DropdownMenu>` from shadcn/ui for action menus
- Bot cards show edit/delete on hover but have menu button always visible
- Conversation list shows actions on hover + swipe on mobile
