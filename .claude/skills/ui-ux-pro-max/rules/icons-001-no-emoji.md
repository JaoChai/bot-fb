# icons-001: No Emoji Icons

**Impact:** CRITICAL
**Category:** Icons & Visual Elements

## Rule

Never use emojis as UI icons. Use SVG icons instead.

## Do

- Use SVG icons (Heroicons, Lucide, Simple Icons)
- Use consistent icon sizing (w-6 h-6)
- Use fixed viewBox (24x24)

## Don't

- Use emojis like 🎨 🚀 ⚙️ as UI icons
- Mix different icon sizes randomly
- Guess or use incorrect logo paths

## Why

Emojis render differently across platforms and devices. SVG icons are consistent, scalable, and professional.

## Examples

```tsx
// Good
import { Settings } from 'lucide-react'
<Settings className="w-6 h-6" />

// Bad
<span>⚙️</span>
```
