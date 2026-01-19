# Ui Ux Pro Max Rules Reference

> Auto-generated from rule files. Do not edit directly.
> Generated: 2026-01-19 13:50

## Table of Contents

**Total Rules: 26**

- [UX Anti-patterns](#ux) - 8 rules (5 HIGH)
- [Accessibility](#a11y) - 6 rules (5 CRITICAL)
- [Responsive Design](#responsive) - 4 rules (1 CRITICAL)
- [Style Anti-patterns](#style) - 4 rules (2 HIGH)
- [Form Design](#form) - 4 rules (2 HIGH)

## Impact Levels

| Level | Description |
|-------|-------------|
| **CRITICAL** | Prompt injection, security vulnerabilities |
| **HIGH** | AI response quality, consistency |
| **MEDIUM** | Optimization, performance |
| **LOW** | Style, minor improvements |

## UX Anti-patterns
<a name="ux"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [ux-001-loading-states](rules/ux-001-loading-states.md) | **HIGH** | Always Show Loading States |
| [ux-003-z-index](rules/ux-003-z-index.md) | **HIGH** | Z-Index Scale System |
| [ux-005-hover-vs-tap](rules/ux-005-hover-vs-tap.md) | **HIGH** | Hover Effects Don't Work on Touch |
| [ux-007-feedback-states](rules/ux-007-feedback-states.md) | **HIGH** | Success & Error Feedback |
| [ux-008-content-jump](rules/ux-008-content-jump.md) | **HIGH** | Prevent Layout Shift (CLS) |
| [ux-002-animation-timing](rules/ux-002-animation-timing.md) | MEDIUM | Animation Duration 150-300ms |
| [ux-004-animation-performance](rules/ux-004-animation-performance.md) | MEDIUM | Animate Only Transform & Opacity |
| [ux-006-disabled-states](rules/ux-006-disabled-states.md) | MEDIUM | Clear Disabled State Visual |

**ux-001-loading-states**: When users trigger an action and see no response, they assume the app is broken.

**ux-003-z-index**: Without a z-index system, you end up with `z-[9999]` everywhere, elements hiding behind each other, and debugging nightmares.

**ux-005-hover-vs-tap**: 50%+ of web traffic is mobile.

**ux-007-feedback-states**: After clicking a button, users need confirmation that something happened.

**ux-008-content-jump**: When content loads and pushes other content around, users click the wrong thing, lose their reading position, and have a terrible experience.

**ux-002-animation-timing**: Animations that are too slow (>500ms) make your UI feel sluggish and unresponsive.

**ux-004-animation-performance**: Animating CSS properties like `width`, `height`, `top`, `left`, `margin` triggers browser repaints and reflows, causing janky 15fps animations.

**ux-006-disabled-states**: Users need to immediately recognize which elements are interactive and which are not.

## Accessibility
<a name="a11y"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [a11y-001-color-contrast](rules/a11y-001-color-contrast.md) | **CRITICAL** | Minimum 4.5:1 Color Contrast |
| [a11y-002-focus-states](rules/a11y-002-focus-states.md) | **CRITICAL** | Visible Focus Indicators |
| [a11y-003-alt-text](rules/a11y-003-alt-text.md) | **CRITICAL** | Images Need Alt Text |
| [a11y-004-keyboard-nav](rules/a11y-004-keyboard-nav.md) | **CRITICAL** | Logical Keyboard Navigation |
| [a11y-005-aria-labels](rules/a11y-005-aria-labels.md) | **CRITICAL** | Icon Buttons Need Labels |
| [a11y-006-reduced-motion](rules/a11y-006-reduced-motion.md) | **HIGH** | Respect prefers-reduced-motion |

**a11y-001-color-contrast**: Low contrast text is unreadable in bright environments and for users with vision impairments (~300 million people worldwide).

**a11y-002-focus-states**: Millions of users navigate with keyboard only (motor impairments, power users, screen readers).

**a11y-003-alt-text**: Screen readers read alt text to describe images.

**a11y-004-keyboard-nav**: Users who can't use a mouse rely on Tab, Enter, and arrow keys to navigate.

**a11y-005-aria-labels**: Icon-only buttons are common in modern UI, but screen readers just say "button" if there's no text content or label.

**a11y-006-reduced-motion**: ~35% of adults over 40 experience vestibular dysfunction.

## Responsive Design
<a name="responsive"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [responsive-001-touch-targets](rules/responsive-001-touch-targets.md) | **CRITICAL** | Minimum 44x44px Touch Targets |
| [responsive-002-viewport-units](rules/responsive-002-viewport-units.md) | **HIGH** | Use dvh Instead of vh on Mobile |
| [responsive-003-no-horizontal-scroll](rules/responsive-003-no-horizontal-scroll.md) | **HIGH** | No Horizontal Scroll on Mobile |
| [responsive-004-readable-fonts](rules/responsive-004-readable-fonts.md) | **HIGH** | Minimum 16px Body Text on Mobile |

**responsive-001-touch-targets**: Human fingertips are ~10mm wide.

**responsive-002-viewport-units**: On mobile browsers, `100vh` includes the space behind the address bar and bottom navigation.

**responsive-003-no-horizontal-scroll**: Horizontal scrolling on mobile is unexpected, awkward, and indicates broken responsive design.

**responsive-004-readable-fonts**: Text smaller than 16px is hard to read on mobile screens held at arm's length.

## Style Anti-patterns
<a name="style"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [style-001-no-emoji-icons](rules/style-001-no-emoji-icons.md) | **HIGH** | Use SVG Icons, Not Emojis |
| [style-004-light-dark-mode](rules/style-004-light-dark-mode.md) | **HIGH** | Light/Dark Mode Contrast Issues |
| [style-002-cursor-pointer](rules/style-002-cursor-pointer.md) | MEDIUM | Clickable Elements Need cursor-pointer |
| [style-003-hover-states](rules/style-003-hover-states.md) | MEDIUM | Stable Hover States (No Layout Shift) |

**style-001-no-emoji-icons**: Emojis render differently on every device/OS, look unprofessional in UI, can't be styled with CSS, and may not be accessible.

**style-004-light-dark-mode**: Designs often look great in dark mode but fail in light mode.

**style-002-cursor-pointer**: Users expect the cursor to change to a pointer finger when hovering over clickable elements.

**style-003-hover-states**: When hover effects change element size or position, surrounding content shifts.

## Form Design
<a name="form"></a>

| Rule | Impact | Title |
|------|--------|-------|
| [form-001-error-messages](rules/form-001-error-messages.md) | **HIGH** | Clear Error Messages Near Input |
| [form-002-label-visibility](rules/form-002-label-visibility.md) | **HIGH** | Always Show Visible Labels |
| [form-003-inline-validation](rules/form-003-inline-validation.md) | MEDIUM | Validate on Blur, Not Just Submit |
| [form-004-mobile-keyboards](rules/form-004-mobile-keyboards.md) | MEDIUM | Show Correct Mobile Keyboard |

**form-001-error-messages**: Users need to understand exactly what went wrong and where.

**form-002-label-visibility**: When a placeholder is the only label, users forget what field they're filling in once they start typing.

**form-003-inline-validation**: When validation only happens on submit, users fill out entire forms only to discover errors at the end.

**form-004-mobile-keyboards**: Mobile users spend significant time entering data.

## Quick Reference by Tag

- **accessibility**: form-002-label-visibility, a11y-001-color-contrast, a11y-006-reduced-motion
- **alt**: a11y-003-alt-text
- **animation**: style-003-hover-states, a11y-006-reduced-motion, ux-002-animation-timing, ux-004-animation-performance
- **aria-label**: a11y-005-aria-labels
- **async**: ux-001-loading-states
- **blur**: form-003-inline-validation
- **buttons**: style-002-cursor-pointer, responsive-001-touch-targets, a11y-005-aria-labels, ux-006-disabled-states
- **cls**: ux-008-content-jump
- **contrast**: style-004-light-dark-mode, a11y-001-color-contrast
- **cursor**: style-002-cursor-pointer
- **dark-mode**: style-004-light-dark-mode
- **disabled**: ux-006-disabled-states
- **dvh**: responsive-002-viewport-units
- **emoji**: style-001-no-emoji-icons
- **error**: ux-007-feedback-states
- **errors**: form-001-error-messages
- **feedback**: style-002-cursor-pointer, form-001-error-messages, form-003-inline-validation, ux-001-loading-states, ux-007-feedback-states, ux-006-disabled-states
- **focus**: a11y-002-focus-states, a11y-004-keyboard-nav
- **font-size**: responsive-004-readable-fonts
- **forms**: form-001-error-messages, form-002-label-visibility, form-003-inline-validation, form-004-mobile-keyboards
- **gpu**: ux-004-animation-performance
- **height**: responsive-002-viewport-units
- **hover**: style-003-hover-states, ux-005-hover-vs-tap
- **icons**: style-001-no-emoji-icons, a11y-005-aria-labels
- **images**: a11y-003-alt-text, ux-008-content-jump
- **inputmode**: form-004-mobile-keyboards
- **interaction**: style-002-cursor-pointer, style-003-hover-states, ux-005-hover-vs-tap
- **keyboard**: form-004-mobile-keyboards, a11y-002-focus-states, a11y-004-keyboard-nav
- **labels**: form-002-label-visibility
- **layers**: ux-003-z-index
- **layout**: style-003-hover-states, responsive-003-no-horizontal-scroll
- **layout-shift**: ux-008-content-jump
- **light-mode**: style-004-light-dark-mode
- **loading**: ux-001-loading-states, ux-008-content-jump
- **mobile**: responsive-001-touch-targets, responsive-002-viewport-units, responsive-003-no-horizontal-scroll, responsive-004-readable-fonts, form-004-mobile-keyboards, ux-005-hover-vs-tap
- **modal**: ux-003-z-index
- **motion**: a11y-006-reduced-motion
- **navigation**: a11y-002-focus-states, a11y-004-keyboard-nav
- **overflow**: responsive-003-no-horizontal-scroll
- **performance**: ux-002-animation-timing, ux-004-animation-performance
- **placeholder**: form-002-label-visibility
- **professional**: style-001-no-emoji-icons
- **readability**: responsive-004-readable-fonts
- **screen-reader**: a11y-003-alt-text, a11y-005-aria-labels
- **scroll**: responsive-003-no-horizontal-scroll
- **skeleton**: ux-001-loading-states
- **stacking**: ux-003-z-index
- **states**: ux-007-feedback-states, ux-006-disabled-states
- **success**: ux-007-feedback-states
- **svg**: style-001-no-emoji-icons
- **tabindex**: a11y-004-keyboard-nav
- **tap**: responsive-001-touch-targets
- **theming**: style-004-light-dark-mode
- **timing**: ux-002-animation-timing
- **toast**: ux-007-feedback-states
- **touch**: responsive-001-touch-targets, ux-005-hover-vs-tap
- **transform**: ux-004-animation-performance
- **transition**: ux-002-animation-timing
- **typography**: responsive-004-readable-fonts
- **validation**: form-001-error-messages, form-003-inline-validation
- **vestibular**: a11y-006-reduced-motion
- **viewport**: responsive-002-viewport-units
- **vision**: a11y-001-color-contrast
- **wcag**: a11y-001-color-contrast, a11y-002-focus-states, a11y-003-alt-text
- **z-index**: ux-003-z-index
