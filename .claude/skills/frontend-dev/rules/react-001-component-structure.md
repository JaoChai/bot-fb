---
id: react-001-component-structure
title: Component Structure and Props
impact: HIGH
impactDescription: "Ensures consistent, maintainable component architecture"
category: react
tags: [components, props, typescript, patterns]
relatedRules: [ts-001-no-any, style-001-cn-utility]
---

## Why This Matters

Consistent component structure makes code easier to read, maintain, and debug. A well-structured component clearly defines its interface (props), handles edge cases, and follows React best practices.

In BotFacebook, we use TypeScript interfaces for props and the `cn()` utility for className merging.

## Bad Example

```tsx
// Problem: Poor structure, missing types, inconsistent patterns
function BotCard(props) {
  return (
    <div className="card" style={{ padding: props.compact ? '8px' : '16px' }}>
      <h3>{props.bot.name}</h3>
      {props.showStatus && <span>{props.bot.status}</span>}
      <div className={"actions " + props.className}>
        {props.children}
      </div>
    </div>
  );
}

// Usage
<BotCard bot={bot} showStatus className="my-class" compact>
  <button>Edit</button>
</BotCard>
```

**Why it's wrong:**
- No TypeScript interface for props
- Inline styles mixed with className
- String concatenation for classes (conflicts not handled)
- Unclear which props are required vs optional
- No default values documented

## Good Example

```tsx
// Solution: Clear structure with TypeScript and cn()
import { cn } from '@/lib/utils';

interface BotCardProps {
  bot: Bot;
  showStatus?: boolean;
  compact?: boolean;
  className?: string;
  children?: React.ReactNode;
}

export function BotCard({
  bot,
  showStatus = false,
  compact = false,
  className,
  children,
}: BotCardProps) {
  return (
    <div
      className={cn(
        'rounded-lg border bg-card text-card-foreground shadow-sm',
        compact ? 'p-2' : 'p-4',
        className
      )}
    >
      <h3 className="font-semibold">{bot.name}</h3>
      {showStatus && (
        <span className={cn(
          'inline-flex items-center rounded-full px-2 py-1 text-xs',
          bot.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
        )}>
          {bot.status}
        </span>
      )}
      {children && (
        <div className="mt-4 flex gap-2">
          {children}
        </div>
      )}
    </div>
  );
}
```

**Why it's better:**
- Clear TypeScript interface documents all props
- Default values in destructuring
- `cn()` handles class merging and conflicts
- Conditional rendering with explicit checks
- Semantic class names from Tailwind

## Project-Specific Notes

**Standard Props Pattern:**
```tsx
interface ComponentProps {
  // Required props first
  data: DataType;
  onAction: () => void;

  // Optional props with defaults
  variant?: 'default' | 'compact';
  disabled?: boolean;

  // Standard optional props last
  className?: string;
  children?: React.ReactNode;
}
```

**File Location:**
- Reusable UI: `src/components/ui/`
- Feature components: `src/components/[feature]/`
- Pages: `src/pages/`

## References

- [React TypeScript Cheatsheet](https://react-typescript-cheatsheet.netlify.app/)
- Related rule: style-001-cn-utility
