---
id: style-002-cva-variants
title: CVA for Component Variants
impact: MEDIUM
impactDescription: "Creates type-safe, consistent component variants with Tailwind"
category: style
tags: [cva, variants, tailwind, components, styling]
relatedRules: [style-001-cn-utility]
---

## Why This Matters

Class Variance Authority (CVA) provides a structured way to define component variants. It generates TypeScript types from variant definitions, ensuring type-safe props and consistent styling patterns.

CVA replaces messy conditional class logic with a declarative configuration.

## Bad Example

```tsx
// Problem 1: Sprawling conditionals
function Button({ variant, size, disabled }) {
  let classes = 'rounded font-medium';

  if (variant === 'primary') {
    classes += ' bg-blue-500 text-white hover:bg-blue-600';
  } else if (variant === 'secondary') {
    classes += ' bg-gray-200 text-gray-800 hover:bg-gray-300';
  } else if (variant === 'destructive') {
    classes += ' bg-red-500 text-white hover:bg-red-600';
  }

  if (size === 'sm') {
    classes += ' px-2 py-1 text-sm';
  } else if (size === 'lg') {
    classes += ' px-6 py-3 text-lg';
  } else {
    classes += ' px-4 py-2';
  }

  if (disabled) {
    classes += ' opacity-50 cursor-not-allowed';
  }

  return <button className={classes} disabled={disabled} />;
}

// Problem 2: No TypeScript support for variants
interface ButtonProps {
  variant?: string; // 'primary' | 'secondary' | ???
  size?: string;    // No autocomplete
}

// Problem 3: Inconsistent variant patterns
// File 1: variant="primary"
// File 2: variant="main"
// File 3: color="primary"
```

**Why it's wrong:**
- Hard to read and maintain
- Easy to miss variants
- No TypeScript autocomplete
- Variant names can drift across codebase
- Difficult to add new variants

## Good Example

```tsx
// Solution: CVA for structured variants
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

// Define variants with CVA
const buttonVariants = cva(
  // Base styles (always applied)
  'inline-flex items-center justify-center rounded-md font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50',
  {
    variants: {
      variant: {
        default: 'bg-primary text-primary-foreground hover:bg-primary/90',
        destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
        outline: 'border border-input bg-background hover:bg-accent hover:text-accent-foreground',
        secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
        ghost: 'hover:bg-accent hover:text-accent-foreground',
        link: 'text-primary underline-offset-4 hover:underline',
      },
      size: {
        default: 'h-10 px-4 py-2',
        sm: 'h-9 rounded-md px-3',
        lg: 'h-11 rounded-md px-8',
        icon: 'h-10 w-10',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  }
);

// TypeScript types auto-generated
type ButtonProps = React.ButtonHTMLAttributes<HTMLButtonElement> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean;
  };

// Component uses the variants
function Button({
  className,
  variant,
  size,
  asChild = false,
  ...props
}: ButtonProps) {
  const Comp = asChild ? Slot : 'button';

  return (
    <Comp
      className={cn(buttonVariants({ variant, size }), className)}
      {...props}
    />
  );
}

// Usage with full type safety
<Button variant="destructive" size="lg">Delete</Button>
<Button variant="outline">Cancel</Button>
<Button size="icon"><Icon /></Button>

// Compound variants for complex states
const alertVariants = cva(
  'relative w-full rounded-lg border p-4',
  {
    variants: {
      variant: {
        default: 'bg-background text-foreground',
        destructive: 'border-destructive/50 text-destructive',
        success: 'border-green-500/50 text-green-700',
      },
      size: {
        default: 'p-4',
        sm: 'p-2 text-sm',
      },
    },
    compoundVariants: [
      {
        variant: 'destructive',
        size: 'sm',
        className: 'border-2', // Extra emphasis for small destructive
      },
    ],
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  }
);

// Export variants for external use
export { Button, buttonVariants };

// Other components can reference the variants
import { buttonVariants } from '@/components/ui/button';

function LinkButton({ href, children }) {
  return (
    <Link href={href} className={buttonVariants({ variant: 'link' })}>
      {children}
    </Link>
  );
}
```

**Why it's better:**
- Declarative variant configuration
- TypeScript autocomplete for variant props
- Consistent naming enforced
- Easy to add new variants
- `compoundVariants` for complex combinations
- Variants exportable for reuse

## Project-Specific Notes

**BotFacebook CVA Components:**
- `src/components/ui/button.tsx`
- `src/components/ui/badge.tsx`
- `src/components/ui/alert.tsx`
- `src/components/ui/input.tsx`

**CVA Pattern:**
```tsx
// 1. Define variants
const componentVariants = cva('base-classes', {
  variants: { /* ... */ },
  defaultVariants: { /* ... */ },
});

// 2. Extract types
type ComponentProps = VariantProps<typeof componentVariants>;

// 3. Use in component
className={cn(componentVariants({ variant, size }), className)}
```

**Compound Variants Use Cases:**
- Small + destructive = extra border
- Large + primary = different shadow
- Disabled + any variant = reduced opacity

## References

- [CVA Documentation](https://cva.style/docs)
- [shadcn/ui Components](https://ui.shadcn.com/docs/components)
