---
id: style-001-no-emoji-icons
title: Use SVG Icons, Not Emojis
impact: HIGH
impactDescription: "Emojis as icons look unprofessional and render inconsistently"
category: style
tags: [icons, emoji, svg, professional]
relatedRules: [a11y-005-aria-labels]
platforms: [All]
---

## Why This Matters

Emojis render differently on every device/OS, look unprofessional in UI, can't be styled with CSS, and may not be accessible. Professional UIs use consistent SVG icon sets.

## The Problem

```html
<!-- Bad: Emojis as UI icons -->
<button>🔍 Search</button>
<nav>
  <a>🏠 Home</a>
  <a>⚙️ Settings</a>
  <a>👤 Profile</a>
</nav>

<!-- Bad: Emoji in feature cards -->
<div class="card">
  <span class="text-4xl">🚀</span>
  <h3>Fast Performance</h3>
</div>
```

## Solution

### Use Icon Libraries

```tsx
// Good: Heroicons
import { MagnifyingGlassIcon, HomeIcon, Cog6ToothIcon } from '@heroicons/react/24/outline';

<button>
  <MagnifyingGlassIcon className="w-5 h-5" />
  Search
</button>

// Good: Lucide React
import { Search, Home, Settings } from 'lucide-react';

<nav>
  <a><Home className="w-5 h-5" /> Home</a>
  <a><Settings className="w-5 h-5" /> Settings</a>
</nav>
```

### Icon Sizing Convention

```html
<!-- Consistent icon sizes -->
<SearchIcon class="w-4 h-4" /> <!-- 16px - inline/small -->
<SearchIcon class="w-5 h-5" /> <!-- 20px - buttons/nav -->
<SearchIcon class="w-6 h-6" /> <!-- 24px - standalone -->
<SearchIcon class="w-8 h-8" /> <!-- 32px - large/hero -->
```

### Brand Logos

```tsx
// Good: Use Simple Icons for brand logos
// https://simpleicons.org/
import { SiGithub, SiGoogle, SiFacebook } from '@icons-pack/react-simple-icons';

// Or inline SVG from official brand assets
<svg viewBox="0 0 24 24" className="w-6 h-6">
  <path d="..." />
</svg>
```

## Quick Reference

| Need | Solution |
|------|----------|
| UI icons | Heroicons or Lucide |
| Brand logos | Simple Icons |
| Custom icons | Design in Figma, export SVG |
| Loading | Animated SVG spinner |

## Icon Libraries

| Library | Style | Package |
|---------|-------|---------|
| Heroicons | Outline/Solid | `@heroicons/react` |
| Lucide | Consistent stroke | `lucide-react` |
| Simple Icons | Brand logos | `@icons-pack/react-simple-icons` |
| Radix Icons | UI primitives | `@radix-ui/react-icons` |

## Styling Icons

```html
<!-- Good: CSS-stylable SVG -->
<SearchIcon className="
  w-5 h-5
  text-gray-500
  group-hover:text-primary
  transition-colors
" />

<!-- Icons inherit text color -->
<button className="text-blue-500">
  <SearchIcon className="w-5 h-5" /> <!-- Also blue -->
  Search
</button>
```

## Testing

- [ ] No emojis used as UI icons
- [ ] All icons from consistent icon set
- [ ] Icons scale properly at different sizes
- [ ] Icons have consistent stroke width

## When Emojis Are OK

```
✓ Chat messages (user content)
✓ Marketing copy emphasis
✓ Informal contexts
✗ Navigation
✗ Buttons
✗ Feature cards
✗ Status indicators
```

## Project-Specific Notes

**BotFacebook Context:**
- Use `lucide-react` for UI icons
- Use `@icons-pack/react-simple-icons` for platform logos
- Import from `@heroicons/react/24/outline` or `/solid`
- Standard size: `w-5 h-5` for buttons, `w-4 h-4` for inline
