# shadcn/ui Integration Guide

This project uses **shadcn/ui** as the primary component library. shadcn/ui is not a traditional npm package - it's a collection of reusable components that you copy into your project.

## Installed Components (26 total)

| Component | File | Use For |
|-----------|------|---------|
| `AlertDialog` | `alert-dialog.tsx` | Confirmation dialogs, destructive actions |
| `Avatar` | `avatar.tsx` | User profile images |
| `Badge` | `badge.tsx` | Status indicators, tags, labels |
| `Button` | `button.tsx` | Primary actions, CTAs |
| `Card` | `card.tsx` | Content containers, list items |
| `Checkbox` | `checkbox.tsx` | Multi-select options |
| `Collapsible` | `collapsible.tsx` | Expandable sections |
| `Dialog` | `dialog.tsx` | Modal windows, forms |
| `DropdownMenu` | `dropdown-menu.tsx` | Context menus, action menus |
| `Input` | `input.tsx` | Text input fields |
| `Label` | `label.tsx` | Form field labels |
| `Progress` | `progress.tsx` | Progress indicators |
| `ScrollArea` | `scroll-area.tsx` | Custom scrollbars |
| `Select` | `select.tsx` | Dropdown selections |
| `Separator` | `separator.tsx` | Visual dividers |
| `Sheet` | `sheet.tsx` | Slide-out panels, mobile menus |
| `Skeleton` | `skeleton.tsx` | Loading placeholders |
| `Slider` | `slider.tsx` | Range inputs |
| `Switch` | `switch.tsx` | Toggle on/off |
| `Tabs` | `tabs.tsx` | Tabbed navigation |
| `Textarea` | `textarea.tsx` | Multi-line text input |
| `Tooltip` | `tooltip.tsx` | Hover information |
| `Sonner` | `sonner.tsx` | Toast notifications |

**Custom components:** `channel-icon.tsx`, `lazy-image.tsx`, `loading-spinner.tsx`

## Adding New Components

```bash
# Add a single component
npx shadcn@latest add accordion

# Add multiple components
npx shadcn@latest add accordion breadcrumb calendar

# Check for updates to existing components
npx shadcn@latest diff
```

## Available Components (Not Yet Installed)

These can be added when needed:

| Component | Use For |
|-----------|---------|
| `Accordion` | Collapsible content sections |
| `Breadcrumb` | Navigation hierarchy |
| `Calendar` | Date picker |
| `Carousel` | Image/content slider |
| `Command` | Command palette (⌘K) |
| `Context Menu` | Right-click menus |
| `Data Table` | Complex tables with sorting/filtering |
| `Date Picker` | Date selection |
| `Drawer` | Bottom sheet (mobile) |
| `Form` | Form validation with react-hook-form |
| `Hover Card` | Hover preview |
| `Menubar` | Application menubar |
| `Navigation Menu` | Site navigation |
| `Pagination` | Page navigation |
| `Popover` | Floating content |
| `Radio Group` | Single-select options |
| `Resizable` | Resizable panels |
| `Table` | Simple tables |
| `Toast` | Notifications (alternative to Sonner) |
| `Toggle` | Toggle button |
| `Toggle Group` | Multiple toggles |

## Component Usage Guidelines

```tsx
// Correct: Import from local ui folder
import { Button } from '@/components/ui/button'
import { Card, CardHeader, CardContent } from '@/components/ui/card'

// Wrong: Don't import from shadcn directly
import { Button } from 'shadcn/ui'
```

## Customizing Components

Components live in `frontend/src/components/ui/` - you own the code:

1. **Modify directly** - Change styles, add variants
2. **Extend with CVA** - Add new variants using `class-variance-authority`
3. **Compose** - Build complex components from primitives

```tsx
// Example: Adding a new button variant
const buttonVariants = cva(
  "...",
  {
    variants: {
      variant: {
        default: "...",
        destructive: "...",
        // Add your custom variant
        gradient: "bg-gradient-to-r from-blue-500 to-purple-500 text-white",
      },
    },
  }
)
```

## Theming with CSS Variables

shadcn/ui uses CSS variables defined in `frontend/src/index.css`:

```css
:root {
  --background: 0 0% 100%;
  --foreground: 222.2 84% 4.9%;
  --primary: 222.2 47.4% 11.2%;
  /* ... */
}

.dark {
  --background: 222.2 84% 4.9%;
  --foreground: 210 40% 98%;
  /* ... */
}
```

## When to Add vs Build Custom

| Scenario | Action |
|----------|--------|
| Need standard component | `npx shadcn@latest add [component]` |
| Need minor customization | Modify existing component |
| Need project-specific logic | Build custom using Radix primitives |
| Need complex feature | Compose multiple shadcn components |

## Resources

- **Official Docs**: https://ui.shadcn.com
- **Component Examples**: https://ui.shadcn.com/examples
- **Themes**: https://ui.shadcn.com/themes
- **Blocks (Page Templates)**: https://ui.shadcn.com/blocks
