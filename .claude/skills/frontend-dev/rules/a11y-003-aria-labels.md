---
id: a11y-003-aria-labels
title: ARIA Labels and Roles
impact: MEDIUM
impactDescription: "Provides context for screen reader users when visual labels are insufficient"
category: a11y
tags: [accessibility, aria, labels, screen-reader]
relatedRules: [a11y-001-semantic-html]
---

## Why This Matters

ARIA (Accessible Rich Internet Applications) supplements HTML when native semantics aren't enough. Labels describe elements, roles define behavior, and states indicate current condition.

The first rule of ARIA: don't use ARIA if native HTML works. ARIA is a last resort.

## Bad Example

```tsx
// Problem 1: Icon button with no label
function DeleteButton({ onDelete }) {
  return (
    <button onClick={onDelete}>
      <TrashIcon />
      {/* Screen reader says: "button" - what button?! */}
    </button>
  );
}

// Problem 2: Misusing role
function Card({ children }) {
  return (
    <div role="button" onClick={handleClick}>
      {children}
      {/* Has role but no keyboard support */}
    </div>
  );
}

// Problem 3: ARIA label on everything
function Form() {
  return (
    <form>
      <label htmlFor="email">Email</label>
      <input id="email" aria-label="Email input" />
      {/* Redundant! Label already provides accessible name */}
    </form>
  );
}

// Problem 4: Meaningless label
function SearchForm() {
  return (
    <input type="search" aria-label="input" />
    // "input" tells user nothing
  );
}

// Problem 5: Using aria-hidden incorrectly
function ImportantInfo() {
  return (
    <div aria-hidden="true">
      Important notice that screen readers should read!
    </div>
  );
}
```

**Why it's wrong:**
- Icon buttons need accessible names
- Role without keyboard support breaks expectations
- Redundant labels waste time
- Generic labels don't help
- aria-hidden removes from accessibility tree

## Good Example

```tsx
// Solution 1: Icon buttons with accessible names
function DeleteButton({ onDelete, itemName }) {
  return (
    <button
      onClick={onDelete}
      aria-label={`Delete ${itemName}`}
    >
      <TrashIcon aria-hidden="true" />
    </button>
  );
}

// Or with visible text for better UX
function DeleteButton({ onDelete }) {
  return (
    <button onClick={onDelete}>
      <TrashIcon aria-hidden="true" />
      <span>Delete</span>
    </button>
  );
}

// Or with screen-reader-only text
function DeleteButton({ onDelete, itemName }) {
  return (
    <button onClick={onDelete}>
      <TrashIcon aria-hidden="true" />
      <span className="sr-only">Delete {itemName}</span>
    </button>
  );
}

// Solution 2: Proper labeling without ARIA
function Form() {
  return (
    <form>
      {/* htmlFor + id creates association - no ARIA needed */}
      <label htmlFor="email">Email</label>
      <input id="email" type="email" />

      {/* Placeholder is NOT a label replacement */}
      <label htmlFor="password">Password</label>
      <input
        id="password"
        type="password"
        placeholder="Enter password"
      />
    </form>
  );
}

// Solution 3: aria-labelledby for complex labels
function PriceSection() {
  return (
    <section aria-labelledby="price-heading">
      <h2 id="price-heading">Pricing Plans</h2>
      <div aria-describedby="price-note">
        <PriceCards />
      </div>
      <p id="price-note" className="text-sm text-muted-foreground">
        All prices in USD. Cancel anytime.
      </p>
    </section>
  );
}

// Solution 4: Live regions for dynamic content
function NotificationArea() {
  const [message, setMessage] = useState('');

  return (
    <div
      role="status"
      aria-live="polite"
      aria-atomic="true"
      className="sr-only"
    >
      {message}
    </div>
  );
}

// For urgent notifications
function ErrorAlert({ error }) {
  return (
    <div role="alert" className="bg-destructive p-4 text-white">
      {error}
    </div>
  );
}

// Solution 5: Describing current state
function AccordionItem({ title, content, isOpen }) {
  return (
    <div>
      <button
        aria-expanded={isOpen}
        aria-controls={`content-${title}`}
        onClick={toggle}
      >
        {title}
      </button>
      <div
        id={`content-${title}`}
        hidden={!isOpen}
      >
        {content}
      </div>
    </div>
  );
}

// Solution 6: Progress indicators
function LoadingButton({ loading, children }) {
  return (
    <button disabled={loading} aria-busy={loading}>
      {loading ? (
        <>
          <Spinner aria-hidden="true" />
          <span className="sr-only">Loading, please wait</span>
        </>
      ) : (
        children
      )}
    </button>
  );
}

// Solution 7: Decorative vs meaningful images
function Card({ title, thumbnail }) {
  return (
    <article>
      {/* Decorative - hide from AT */}
      <img src={thumbnail} alt="" aria-hidden="true" />

      {/* OR meaningful - describe it */}
      <img
        src={thumbnail}
        alt={`Preview of ${title}`}
      />

      <h3>{title}</h3>
    </article>
  );
}
```

**Why it's better:**
- Icon buttons have clear purpose
- Native labels preferred over ARIA
- Live regions announce changes
- State attributes communicate current condition
- Decorative content properly hidden

## Project-Specific Notes

**BotFacebook ARIA Usage:**

| Pattern | Implementation |
|---------|----------------|
| Icon buttons | `aria-label="Action name"` |
| Loading states | `aria-busy="true"` + sr-only text |
| Expandable sections | `aria-expanded` + `aria-controls` |
| Toast notifications | `role="status"` or `role="alert"` |
| Decorative icons | `aria-hidden="true"` |

**sr-only Utility:**
```css
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
```

**ARIA Rules:**
1. Use native HTML first
2. Don't change native semantics
3. All ARIA controls must be keyboard accessible
4. Don't hide focusable elements
5. Interactive elements must have accessible names

## References

- [WAI-ARIA Authoring Practices](https://www.w3.org/WAI/ARIA/apg/)
- [Using ARIA](https://www.w3.org/TR/using-aria/)
