---
id: responsive-001-touch-targets
title: Minimum 44x44px Touch Targets
impact: CRITICAL
impactDescription: "Tiny buttons cause misclicks and frustration on mobile"
category: responsive
tags: [touch, mobile, buttons, tap]
relatedRules: [ux-005-hover-vs-tap]
platforms: [Mobile, All]
---

## Why This Matters

Human fingertips are ~10mm wide. Buttons smaller than 44x44px (iOS) or 48x48px (Android) are hard to tap accurately, causing misclicks and frustration. This is also a WCAG 2.5.5 requirement.

## The Problem

```html
<!-- Bad: Way too small -->
<button class="w-6 h-6">
  <XIcon class="w-4 h-4" />
</button>

<!-- Bad: Tight spacing -->
<div class="flex gap-1">
  <button class="w-8 h-8">1</button>
  <button class="w-8 h-8">2</button>
  <button class="w-8 h-8">3</button>
</div>
```

## Solution

```html
<!-- Good: Minimum 44px -->
<button class="min-w-[44px] min-h-[44px] p-2">
  <XIcon class="w-5 h-5" />
</button>

<!-- Good: Adequate spacing -->
<div class="flex gap-2">
  <button class="min-w-[44px] min-h-[44px]">1</button>
  <button class="min-w-[44px] min-h-[44px]">2</button>
  <button class="min-w-[44px] min-h-[44px]">3</button>
</div>

<!-- Good: Larger tap area than visual -->
<button class="relative p-4 -m-2">
  <XIcon class="w-5 h-5" />
</button>
```

### Making Small Icons Tappable

```html
<!-- Visual is small, tap area is large -->
<button class="p-3"> <!-- 12px padding each side -->
  <XIcon class="w-5 h-5" /> <!-- 20px icon -->
</button>
<!-- Total: 20 + 24 = 44px touch target -->
```

## Quick Reference

| Guideline | Minimum Size | Minimum Spacing |
|-----------|-------------|-----------------|
| Apple HIG | 44x44pt | 8pt |
| Material Design | 48x48dp | 8dp |
| WCAG 2.5.5 | 44x44px | Adjacent targets OK |

## Tailwind Classes

```
min-w-[44px]   - Minimum width
min-h-[44px]   - Minimum height
p-3            - 12px padding (icon + padding = 44px)
gap-2          - 8px between items
touch-manipulation - Removes tap delay
```

## Component Pattern

```tsx
// Good: Icon button with proper size
function IconButton({
  icon,
  label,
  onClick
}: {
  icon: React.ReactNode;
  label: string;
  onClick: () => void;
}) {
  return (
    <button
      onClick={onClick}
      aria-label={label}
      className="
        min-w-[44px] min-h-[44px]
        flex items-center justify-center
        hover:bg-gray-100 rounded-lg
        touch-manipulation
      "
    >
      {icon}
    </button>
  );
}
```

## Testing

- [ ] Test on real mobile device
- [ ] Tap each button/link accurately
- [ ] No misclicks on adjacent elements
- [ ] Check with accessibility scanner

## DevTools Testing

```javascript
// Check all buttons in console
document.querySelectorAll('button, a, [role="button"]').forEach(el => {
  const rect = el.getBoundingClientRect();
  if (rect.width < 44 || rect.height < 44) {
    console.warn('Too small:', el, rect.width, rect.height);
  }
});
```

## Project-Specific Notes

**BotFacebook Context:**
- shadcn/ui Button has adequate size by default
- Icon-only buttons: use `p-2` minimum
- Mobile nav: ensure all tap targets are 44px+
- Add `touch-manipulation` for better mobile response
