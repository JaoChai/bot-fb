---
id: a11y-004-color-contrast
title: Color Contrast Requirements
impact: MEDIUM
impactDescription: "Ensures text is readable for users with visual impairments"
category: a11y
tags: [accessibility, color, contrast, visual]
relatedRules: []
---

## Why This Matters

Low contrast text is hard to read for users with visual impairments, color blindness, or in poor lighting conditions. WCAG requires minimum contrast ratios for text to be accessible.

Approximately 8% of men and 0.5% of women have some form of color blindness.

## Bad Example

```tsx
// Problem 1: Light gray on white
function Subtitle({ text }) {
  return (
    <p className="text-gray-400"> {/* ~3:1 contrast on white */}
      {text}
    </p>
  );
}

// Problem 2: Color as only indicator
function FormField({ value, error }) {
  return (
    <div>
      <input
        className={error ? 'border-red-500' : 'border-gray-300'}
        // Color blind users can't tell the difference!
      />
    </div>
  );
}

// Problem 3: Placeholder text too light
<input placeholder="Enter email" className="placeholder:text-gray-300" />

// Problem 4: Disabled state too faint
<button disabled className="text-gray-200 bg-gray-100">
  Submit
</button>

// Problem 5: Status colors without text
function StatusBadge({ status }) {
  const colors = {
    active: 'bg-green-500',
    pending: 'bg-yellow-500',
    error: 'bg-red-500',
  };

  return (
    <span className={`rounded-full w-3 h-3 ${colors[status]}`} />
    // Just a colored dot - no text!
  );
}
```

**Why it's wrong:**
- Gray-400 on white is below 4.5:1 ratio
- Color-only indicators fail for color blind users
- Light placeholders are illegible
- Disabled elements should still be somewhat readable
- Status colors need text or icon backup

## Good Example

```tsx
// Solution 1: Use accessible color tokens
function Subtitle({ text }) {
  return (
    <p className="text-muted-foreground"> {/* Designed for contrast */}
      {text}
    </p>
  );
}

// Our design tokens ensure contrast:
// text-foreground: high contrast primary text
// text-muted-foreground: meets 4.5:1 minimum
// text-primary: brand color with good contrast

// Solution 2: Color + additional indicator
function FormField({ value, error, errorMessage }) {
  return (
    <div>
      <input
        className={cn(
          'border rounded px-3 py-2',
          error && 'border-red-500'
        )}
        aria-invalid={error ? 'true' : undefined}
        aria-describedby={error ? 'error-message' : undefined}
      />
      {error && (
        <p id="error-message" className="text-red-600 mt-1 flex items-center gap-1">
          <AlertCircle className="h-4 w-4" /> {/* Icon indicator */}
          {errorMessage}
        </p>
      )}
    </div>
  );
}

// Solution 3: Accessible placeholder contrast
<input
  placeholder="Enter email"
  className="placeholder:text-muted-foreground" // Meets 4.5:1
/>

// Solution 4: Readable disabled state
<button
  disabled
  className="bg-muted text-muted-foreground cursor-not-allowed opacity-70"
>
  Submit
</button>
// Still readable, clearly disabled

// Solution 5: Status with text and/or icons
function StatusBadge({ status }) {
  const config = {
    active: {
      className: 'bg-green-100 text-green-800',
      icon: CheckCircle,
      label: 'Active',
    },
    pending: {
      className: 'bg-yellow-100 text-yellow-800',
      icon: Clock,
      label: 'Pending',
    },
    error: {
      className: 'bg-red-100 text-red-800',
      icon: XCircle,
      label: 'Error',
    },
  };

  const { className, icon: Icon, label } = config[status];

  return (
    <span className={cn('rounded-full px-2 py-1 text-xs flex items-center gap-1', className)}>
      <Icon className="h-3 w-3" />
      {label}
    </span>
  );
}

// Solution 6: Check contrast in design system
// tailwind.config.ts
const colors = {
  // WCAG AA compliant pairs:
  foreground: 'hsl(0 0% 3.9%)',     // ~21:1 on white
  muted: {
    DEFAULT: 'hsl(0 0% 96.1%)',
    foreground: 'hsl(0 0% 45.1%)',  // ~4.6:1 on white ✓
  },
  primary: {
    DEFAULT: 'hsl(222.2 47.4% 11.2%)', // ~16:1 on white
    foreground: 'hsl(210 40% 98%)',    // ~16:1 on primary
  },
};

// Solution 7: Dark mode contrast
function Card({ children }) {
  return (
    <div className="bg-card text-card-foreground">
      {/* Design tokens handle light/dark automatically */}
      {children}
    </div>
  );
}
```

**Why it's better:**
- Design tokens ensure contrast compliance
- Multiple indicators (color + icon + text)
- Placeholders are readable
- Disabled states are still visible
- Status badges have text labels

## Project-Specific Notes

**WCAG Contrast Requirements:**

| Content | Minimum Ratio | Enhanced (AAA) |
|---------|--------------|----------------|
| Normal text (<18pt) | 4.5:1 | 7:1 |
| Large text (≥18pt or 14pt bold) | 3:1 | 4.5:1 |
| UI components, graphics | 3:1 | - |

**Testing Tools:**
- [WebAIM Contrast Checker](https://webaim.org/resources/contrastchecker/)
- Chrome DevTools: Inspect → Accessibility → Contrast
- axe DevTools browser extension

**BotFacebook Color System:**
```css
/* Light mode */
--foreground: 0 0% 3.9%;      /* Main text: 21:1 */
--muted-foreground: 0 0% 45%; /* Secondary: 4.6:1 */

/* Dark mode */
--foreground: 0 0% 98%;       /* Main text on dark */
--muted-foreground: 0 0% 64%; /* Secondary on dark */
```

**Don't Rely on Color Alone:**
- ✅ Error: Red border + icon + text message
- ✅ Success: Green + checkmark + "Saved!"
- ✅ Status: Colored badge + text label
- ❌ Error: Just red border
- ❌ Required: Just red asterisk

## References

- [WCAG 1.4.3 Contrast](https://www.w3.org/WAI/WCAG21/Understanding/contrast-minimum.html)
- [WCAG 1.4.1 Use of Color](https://www.w3.org/WAI/WCAG21/Understanding/use-of-color.html)
