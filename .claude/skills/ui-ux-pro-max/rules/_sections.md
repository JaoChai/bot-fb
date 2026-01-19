# Decision Trees for UI/UX

## 1. Accessibility Check Tree

```
User can't interact with element?
├── Can't see it?
│   ├── Color contrast issue → a11y-001-color-contrast
│   ├── Element too small → responsive-001-touch-targets
│   └── Hidden behind other element → ux-003-z-index
├── Can't click/tap it?
│   ├── No cursor pointer → style-002-cursor-pointer
│   ├── Touch target too small → responsive-001-touch-targets
│   └── Disabled without indication → ux-006-disabled-states
├── Keyboard can't reach it?
│   ├── No focus styles → a11y-002-focus-states
│   ├── Tab order broken → a11y-004-keyboard-nav
│   └── Focus trapped → a11y-004-keyboard-nav
└── Screen reader can't read it?
    ├── Missing alt text → a11y-003-alt-text
    ├── Missing aria-label → a11y-005-aria-labels
    └── No semantic HTML → a11y-006-semantic-html
```

## 2. Mobile UX Check Tree

```
Page broken on mobile?
├── Layout issues?
│   ├── Horizontal scroll → responsive-003-no-horizontal-scroll
│   ├── Content cut off → responsive-002-viewport-units
│   └── Text too small → responsive-004-readable-fonts
├── Touch issues?
│   ├── Buttons too small → responsive-001-touch-targets
│   ├── Elements too close → responsive-001-touch-targets
│   └── Tap doesn't work → ux-005-hover-vs-tap
├── Performance issues?
│   ├── Slow loading → (search ux-guidelines.csv "performance")
│   └── Janky scroll → ux-004-animation-performance
└── Form issues?
    ├── Wrong keyboard → form-004-mobile-keyboards
    └── Can't see input → form-002-label-visibility
```

## 3. Style Anti-pattern Check Tree

```
UI looks unprofessional?
├── Icons wrong?
│   ├── Using emojis → style-001-no-emoji-icons
│   ├── Inconsistent sizes → style-001-no-emoji-icons
│   └── Wrong brand logo → style-001-no-emoji-icons
├── Interaction wrong?
│   ├── No hover feedback → style-002-cursor-pointer
│   ├── Layout shift on hover → style-003-hover-states
│   └── Transitions too slow → ux-002-animation-timing
├── Colors wrong?
│   ├── Low contrast → a11y-001-color-contrast
│   ├── Glass too transparent → style-004-light-dark-mode
│   └── Borders invisible → style-004-light-dark-mode
└── Layout wrong?
    ├── Content behind navbar → ux-003-z-index
    ├── Inconsistent spacing → (search ux-guidelines.csv "layout")
    └── No max-width → responsive-004-readable-fonts
```

## 4. Form UX Check Tree

```
Form causing frustration?
├── Validation issues?
│   ├── Only validates on submit → form-003-inline-validation
│   ├── No error messages → form-001-error-messages
│   └── Unclear what's wrong → form-001-error-messages
├── Label issues?
│   ├── No labels visible → form-002-label-visibility
│   ├── Placeholder as label → form-002-label-visibility
│   └── Required not marked → form-002-label-visibility
├── Feedback issues?
│   ├── No loading state → ux-001-loading-states
│   ├── No success message → ux-007-feedback-states
│   └── Silent errors → form-001-error-messages
└── Mobile issues?
    ├── Wrong keyboard type → form-004-mobile-keyboards
    └── Can't see what typing → form-002-label-visibility
```

## 5. Search Database Guidelines

For issues not covered by rules, search CSV:

```bash
# Animation issues
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "animation" --domain ux

# Performance issues
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "performance" --domain ux

# Layout issues
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "layout" --domain ux

# Touch issues
python3 .claude/skills/ui-ux-pro-max/scripts/search.py "touch" --domain ux
```

## Quick Reference Tables

### Touch Target Sizes

| Element | Minimum Size | Minimum Spacing |
|---------|-------------|-----------------|
| Button | 44x44px | 8px |
| Icon button | 44x44px | 8px |
| Checkbox | 44x44px | 8px |
| Link | 44px height | 8px |

### Animation Timing

| Type | Duration | Easing |
|------|----------|--------|
| Micro-interaction | 150-200ms | ease-out |
| Page transition | 200-300ms | ease-in-out |
| Modal open | 200ms | ease-out |
| Hover state | 150ms | ease |

### Color Contrast Requirements

| Text Size | Min Ratio | Level |
|-----------|-----------|-------|
| Normal (<18px) | 4.5:1 | AA |
| Large (>=18px bold) | 3:1 | AA |
| Enhanced | 7:1 | AAA |

### Z-Index Scale

| Layer | z-index | Use For |
|-------|---------|---------|
| Base | 0 | Normal content |
| Dropdown | 10 | Menus, tooltips |
| Sticky | 20 | Sticky headers |
| Fixed | 30 | Fixed nav |
| Modal backdrop | 40 | Overlay |
| Modal | 50 | Modal content |
| Toast | 60 | Notifications |
