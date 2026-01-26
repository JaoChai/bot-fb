---
id: js-003-immutable-array-methods
title: Use Immutable Array Methods (toSorted, toReversed, toSpliced)
impact: LOW-MEDIUM
impactDescription: "Prevents mutation bugs - cleaner code, safer state updates"
category: js
tags: [javascript, arrays, immutability, es2023]
relatedRules: [perf-006-functional-setstate]
---

## Why This Matters

Traditional array methods like `.sort()`, `.reverse()`, and `.splice()` mutate the original array. This causes bugs in React where state should be immutable. ES2023 introduced immutable versions that return new arrays.

## Bad Example

```tsx
// Problem: Mutating methods change original array
function SortedList({ items }: Props) {
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('asc');

  // DANGER: This mutates the original items prop!
  const sorted = items.sort((a, b) =>
    sortOrder === 'asc' ? a.name.localeCompare(b.name) : b.name.localeCompare(a.name)
  );
  // items is now mutated - parent component affected!

  return <List items={sorted} />;
}

// Problem: Mutation in state update
function TodoList() {
  const [todos, setTodos] = useState<Todo[]>([]);

  const removeTodo = (index: number) => {
    todos.splice(index, 1);  // Mutates original!
    setTodos(todos);  // React might not re-render (same reference)
  };
}
```

**Why it's wrong:**
- Mutates props/state directly
- Can cause bugs in parent components
- React might skip re-renders (same reference)
- Hard to debug - mutation is side effect

## Good Example

```tsx
// Solution: Use immutable methods (ES2023)
function SortedList({ items }: Props) {
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('asc');

  // toSorted() returns new array, original untouched
  const sorted = items.toSorted((a, b) =>
    sortOrder === 'asc' ? a.name.localeCompare(b.name) : b.name.localeCompare(a.name)
  );

  return <List items={sorted} />;
}

// Solution: Immutable state updates
function TodoList() {
  const [todos, setTodos] = useState<Todo[]>([]);

  const removeTodo = (index: number) => {
    setTodos(prev => prev.toSpliced(index, 1));  // New array returned
  };

  const reverseTodos = () => {
    setTodos(prev => prev.toReversed());  // New array returned
  };
}
```

**Why it's better:**
- Original array never mutated
- Safe for React state updates
- Clear intent - obviously creating new array
- No accidental side effects

## ES2023 Immutable Methods

```tsx
// toSorted() - immutable sort
const sorted = arr.toSorted((a, b) => a - b);
// Old way: [...arr].sort((a, b) => a - b)

// toReversed() - immutable reverse
const reversed = arr.toReversed();
// Old way: [...arr].reverse()

// toSpliced() - immutable splice
const removed = arr.toSpliced(1, 2);          // Remove 2 items at index 1
const inserted = arr.toSpliced(1, 0, 'new');  // Insert at index 1
// Old way: const copy = [...arr]; copy.splice(1, 2); return copy;

// with() - immutable index assignment
const updated = arr.with(2, 'newValue');
// Old way: const copy = [...arr]; copy[2] = 'newValue'; return copy;
```

## Polyfill for Older Environments

```tsx
// If targeting older browsers, use spread + mutating method
const sorted = [...items].sort(compareFn);
const reversed = [...items].reverse();

// Or add polyfill
// npm install core-js
import 'core-js/actual/array/to-sorted';
import 'core-js/actual/array/to-reversed';
import 'core-js/actual/array/to-spliced';
```

## Common Patterns

```tsx
// Move item in array
function moveItem<T>(arr: T[], from: number, to: number): T[] {
  const item = arr[from];
  return arr.toSpliced(from, 1).toSpliced(to, 0, item);
}

// Update item at index
function updateAt<T>(arr: T[], index: number, update: Partial<T>): T[] {
  return arr.with(index, { ...arr[index], ...update });
}

// Sort by multiple fields
const sorted = items.toSorted((a, b) =>
  a.category.localeCompare(b.category) || a.name.localeCompare(b.name)
);
```

## Browser Support

```tsx
// toSorted, toReversed, toSpliced, with:
// Chrome 110+, Firefox 115+, Safari 16+
// Node.js 20+

// For older support, use spread operator
const safeSorted = [...arr].sort(fn);
```

## Project-Specific Notes

Common in BotFacebook:
- Sorting conversation list by date/unread
- Reordering knowledge base items
- Removing messages from array
- Updating bot order in list

## References

- [MDN: Array.prototype.toSorted()](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Array/toSorted)
- [TC39 Change Array by Copy](https://github.com/tc39/proposal-change-array-by-copy)
