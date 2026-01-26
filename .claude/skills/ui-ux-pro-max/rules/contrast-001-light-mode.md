# contrast-001: Light Mode Contrast

**Impact:** CRITICAL
**Category:** Light/Dark Mode

## Rule

Ensure proper contrast in light mode. Glass/transparent elements need higher opacity.

## Do

- Glass card light mode: Use `bg-white/80` or higher opacity
- Text contrast: Use `#0F172A` (slate-900) for body text
- Muted text: Use `#475569` (slate-600) minimum
- Border visibility: Use `border-gray-200` in light mode

## Don't

- Use `bg-white/10` for glass cards (too transparent)
- Use `#94A3B8` (slate-400) for body text
- Use gray-400 or lighter for muted text
- Use `border-white/10` (invisible in light mode)

## WCAG Requirements

- Normal text: 4.5:1 minimum contrast ratio
- Large text (18px+): 3:1 minimum contrast ratio

## Examples

```tsx
// Good - Light mode glass card
<div className="bg-white/80 backdrop-blur-sm border border-gray-200">
  <p className="text-slate-900">Main text</p>
  <span className="text-slate-600">Muted text</span>
</div>

// Bad
<div className="bg-white/10 border-white/10">
  <p className="text-slate-400">Low contrast text</p>
</div>
```
