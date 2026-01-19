---
id: a11y-002-keyboard-navigation
title: Keyboard Navigation Support
impact: HIGH
impactDescription: "Enables users who can't use a mouse to navigate and interact"
category: a11y
tags: [accessibility, keyboard, focus, tabindex]
relatedRules: [a11y-001-semantic-html, gotcha-005-form-submit-button]
---

## Why This Matters

Many users navigate with keyboards: those with motor impairments, power users, and screen reader users. All interactive elements must be reachable and operable with keyboard alone.

Tab moves focus, Enter/Space activates, and arrow keys navigate within components.

## Bad Example

```tsx
// Problem 1: Click-only interaction
function Dropdown({ items }) {
  const [open, setOpen] = useState(false);

  return (
    <div>
      <div onClick={() => setOpen(!open)} className="cursor-pointer">
        Select Option
      </div>
      {open && (
        <div className="absolute mt-1">
          {items.map((item) => (
            <div key={item.id} onClick={() => selectItem(item)}>
              {item.label}
            </div>
          ))}
        </div>
      )}
    </div>
  );
  // Can't tab to it, can't use arrow keys
}

// Problem 2: Removing focus outline
button {
  outline: none; /* Never do this without replacement! */
}

// Problem 3: Focus trap that can't be escaped
function Modal({ children }) {
  return (
    <div className="fixed inset-0">
      {children}
      {/* No way to close with Escape, no focus management */}
    </div>
  );
}

// Problem 4: Positive tabindex creating confusing order
function Form() {
  return (
    <form>
      <input tabIndex={2} /> {/* Second */}
      <input tabIndex={1} /> {/* First */}
      <input tabIndex={3} /> {/* Third */}
    </form>
  );
}
```

**Why it's wrong:**
- Divs don't receive focus without tabindex
- Removing outline makes focus invisible
- Trapped users can't escape modals
- Positive tabindex creates unpredictable order

## Good Example

```tsx
// Solution 1: Use native interactive elements
function Dropdown({ items, value, onChange }) {
  return (
    <select
      value={value}
      onChange={(e) => onChange(e.target.value)}
      className="rounded border p-2"
    >
      {items.map((item) => (
        <option key={item.id} value={item.id}>
          {item.label}
        </option>
      ))}
    </select>
  );
}

// Solution 2: Custom components with Radix (keyboard built-in)
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';

function CustomDropdown({ items, onSelect }) {
  return (
    <DropdownMenu.Root>
      <DropdownMenu.Trigger asChild>
        <button className="rounded border px-4 py-2">
          Options
        </button>
      </DropdownMenu.Trigger>

      <DropdownMenu.Content className="rounded border bg-white shadow-lg">
        {items.map((item) => (
          <DropdownMenu.Item
            key={item.id}
            onSelect={() => onSelect(item)}
            className="cursor-pointer px-4 py-2 hover:bg-gray-100 focus:bg-gray-100"
          >
            {item.label}
          </DropdownMenu.Item>
        ))}
      </DropdownMenu.Content>
    </DropdownMenu.Root>
  );
  // Arrow keys navigate, Enter selects, Escape closes
}

// Solution 3: Visible focus styles
// globals.css
:focus-visible {
  outline: 2px solid var(--ring);
  outline-offset: 2px;
}

// Or in Tailwind
<button className="focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2">
  Click me
</button>

// Solution 4: Proper modal with focus trap and escape
import * as Dialog from '@radix-ui/react-dialog';

function Modal({ open, onClose, children }) {
  return (
    <Dialog.Root open={open} onOpenChange={onClose}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 bg-black/50" />
        <Dialog.Content
          className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2"
          onEscapeKeyDown={() => onClose(false)}
        >
          <Dialog.Title>Modal Title</Dialog.Title>
          {children}
          <Dialog.Close asChild>
            <button aria-label="Close">×</button>
          </Dialog.Close>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
  // Focus trapped inside, Escape closes, focus returns on close
}

// Solution 5: Skip link for main content
function Layout({ children }) {
  return (
    <>
      <a
        href="#main-content"
        className="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-primary focus:px-4 focus:py-2 focus:text-white"
      >
        Skip to main content
      </a>
      <header>{/* Nav */}</header>
      <main id="main-content" tabIndex={-1}>
        {children}
      </main>
    </>
  );
}

// Solution 6: Custom keyboard handling when needed
function ListBox({ items, selectedId, onSelect }) {
  const [focusedIndex, setFocusedIndex] = useState(0);

  const handleKeyDown = (e: React.KeyboardEvent) => {
    switch (e.key) {
      case 'ArrowDown':
        e.preventDefault();
        setFocusedIndex((i) => Math.min(i + 1, items.length - 1));
        break;
      case 'ArrowUp':
        e.preventDefault();
        setFocusedIndex((i) => Math.max(i - 1, 0));
        break;
      case 'Enter':
      case ' ':
        e.preventDefault();
        onSelect(items[focusedIndex].id);
        break;
    }
  };

  return (
    <ul
      role="listbox"
      tabIndex={0}
      onKeyDown={handleKeyDown}
      className="focus:outline-none focus:ring-2"
    >
      {items.map((item, index) => (
        <li
          key={item.id}
          role="option"
          aria-selected={item.id === selectedId}
          className={cn(
            index === focusedIndex && 'bg-muted',
            item.id === selectedId && 'font-semibold'
          )}
        >
          {item.label}
        </li>
      ))}
    </ul>
  );
}
```

**Why it's better:**
- Native elements have built-in keyboard support
- Radix components handle complex keyboard patterns
- Focus styles clearly visible
- Modals trap focus and close on Escape
- Skip links help keyboard users

## Project-Specific Notes

**Keyboard Patterns:**

| Component | Keys |
|-----------|------|
| Button | Enter, Space |
| Link | Enter |
| Dropdown | Arrow Down/Up, Enter, Escape |
| Modal | Tab (trapped), Escape (close) |
| Tabs | Arrow Left/Right |
| Listbox | Arrow Up/Down, Enter |

**Testing Keyboard Navigation:**
1. Unplug mouse
2. Tab through entire page
3. Verify focus is always visible
4. Verify all actions work with keyboard

**Radix Components (keyboard-ready):**
- Dialog, AlertDialog
- DropdownMenu, ContextMenu
- Select, Combobox
- Tabs, Accordion

## References

- [WCAG 2.1.1 Keyboard](https://www.w3.org/WAI/WCAG21/Understanding/keyboard.html)
- [Radix Primitives](https://www.radix-ui.com/primitives)
