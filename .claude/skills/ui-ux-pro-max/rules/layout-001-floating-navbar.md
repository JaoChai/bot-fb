# layout-001: Floating Navbar Spacing

**Impact:** HIGH
**Category:** Layout & Spacing

## Rule

Floating navbars need proper spacing from viewport edges. Content must account for fixed elements.

## Do

- Floating navbar: Add `top-4 left-4 right-4` spacing
- Account for fixed navbar height in content padding
- Use consistent max-width (`max-w-6xl` or `max-w-7xl`)

## Don't

- Stick navbar to `top-0 left-0 right-0`
- Let content hide behind fixed elements
- Mix different container widths

## Examples

```tsx
// Good - Floating navbar
<nav className="fixed top-4 left-4 right-4 z-50 bg-white/80 backdrop-blur rounded-full">
  ...
</nav>
<main className="pt-24"> {/* Account for navbar */}
  ...
</main>

// Bad
<nav className="fixed top-0 left-0 right-0">
  ...
</nav>
<main> {/* Content hidden behind navbar */}
  ...
</main>
```
