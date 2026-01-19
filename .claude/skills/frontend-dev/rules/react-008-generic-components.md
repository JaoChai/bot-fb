---
id: react-008-generic-components
title: Generic Components with TypeScript
impact: MEDIUM
impactDescription: "Enables type-safe reusable components that work with any data type"
category: react
tags: [typescript, generics, reusability, type-safety]
relatedRules: [ts-001-no-any, ts-002-discriminated-unions]
---

## Why This Matters

Generic components let you build reusable UI that maintains type safety regardless of the data type. Instead of using `any` or creating separate components for each type, generics provide flexibility with full IntelliSense support.

This is essential for list components, select inputs, and data display components.

## Bad Example

```tsx
// Problem 1: Using 'any' loses type safety
interface ListProps {
  items: any[];
  renderItem: (item: any) => ReactNode;
}

function List({ items, renderItem }: ListProps) {
  return <ul>{items.map((item, i) => <li key={i}>{renderItem(item)}</li>)}</ul>;
}

// Usage - no type checking!
<List
  items={users}
  renderItem={(user) => user.nmae} // Typo not caught!
/>

// Problem 2: Separate components for each type
function UserList({ users }: { users: User[] }) { /* ... */ }
function BotList({ bots }: { bots: Bot[] }) { /* ... */ }
function MessageList({ messages }: { messages: Message[] }) { /* ... */ }
// Duplicated logic everywhere!

// Problem 3: Type assertion in render
function SelectInput({ options, onChange }) {
  return (
    <select onChange={(e) => onChange(options[e.target.selectedIndex])}>
      {options.map((opt, i) => (
        <option key={i}>{(opt as any).label}</option> // Unsafe!
      ))}
    </select>
  );
}
```

**Why it's wrong:**
- `any` disables TypeScript's protection
- Typos in property access aren't caught
- Code duplication for similar components
- Type assertions are unsafe and verbose

## Good Example

```tsx
// Solution: Generic component with type parameter
interface ListProps<T> {
  items: T[];
  renderItem: (item: T) => ReactNode;
  keyExtractor: (item: T) => string;
  emptyState?: ReactNode;
}

function List<T>({ items, renderItem, keyExtractor, emptyState }: ListProps<T>) {
  if (items.length === 0 && emptyState) {
    return <>{emptyState}</>;
  }

  return (
    <ul className="space-y-2">
      {items.map((item) => (
        <li key={keyExtractor(item)}>{renderItem(item)}</li>
      ))}
    </ul>
  );
}

// Usage with full type safety
<List<User>
  items={users}
  keyExtractor={(user) => user.id}
  renderItem={(user) => (
    <div>
      {user.name} {/* TypeScript knows this is User */}
      {user.nmae} {/* Error! Property 'nmae' does not exist */}
    </div>
  )}
  emptyState={<p>No users found</p>}
/>

// Type inference works too
<List
  items={bots} // TypeScript infers T = Bot
  keyExtractor={(bot) => bot.id}
  renderItem={(bot) => <BotCard bot={bot} />}
/>

// Generic Select component
interface SelectProps<T> {
  options: T[];
  value: T | null;
  onChange: (value: T) => void;
  getLabel: (option: T) => string;
  getValue: (option: T) => string;
  placeholder?: string;
}

function Select<T>({
  options,
  value,
  onChange,
  getLabel,
  getValue,
  placeholder = 'Select...',
}: SelectProps<T>) {
  return (
    <select
      value={value ? getValue(value) : ''}
      onChange={(e) => {
        const selected = options.find((opt) => getValue(opt) === e.target.value);
        if (selected) onChange(selected);
      }}
      className="rounded border p-2"
    >
      <option value="">{placeholder}</option>
      {options.map((option) => (
        <option key={getValue(option)} value={getValue(option)}>
          {getLabel(option)}
        </option>
      ))}
    </select>
  );
}

// Type-safe usage
<Select<Bot>
  options={bots}
  value={selectedBot}
  onChange={setSelectedBot}
  getLabel={(bot) => bot.name}
  getValue={(bot) => bot.id}
  placeholder="Select a bot"
/>

// Generic Table component
interface Column<T> {
  key: string;
  header: string;
  render: (item: T) => ReactNode;
}

interface TableProps<T> {
  data: T[];
  columns: Column<T>[];
  keyExtractor: (item: T) => string;
}

function Table<T>({ data, columns, keyExtractor }: TableProps<T>) {
  return (
    <table className="w-full">
      <thead>
        <tr>
          {columns.map((col) => (
            <th key={col.key}>{col.header}</th>
          ))}
        </tr>
      </thead>
      <tbody>
        {data.map((item) => (
          <tr key={keyExtractor(item)}>
            {columns.map((col) => (
              <td key={col.key}>{col.render(item)}</td>
            ))}
          </tr>
        ))}
      </tbody>
    </table>
  );
}
```

**Why it's better:**
- Full type safety without `any`
- IntelliSense shows available properties
- Typos are caught at compile time
- One component works with any data type
- Type inference reduces verbosity

## Project-Specific Notes

**BotFacebook Generic Components:**
- `src/components/ui/data-table.tsx` - Generic table with sorting/filtering
- `src/components/ui/combobox.tsx` - Generic searchable select
- `src/components/ui/list.tsx` - Generic list with virtualization

**Pattern for Callback Generics:**
```tsx
// When the component receives callbacks that return the generic type
interface AutocompleteProps<T> {
  query: string;
  onSearch: (query: string) => Promise<T[]>;
  onSelect: (item: T) => void;
  renderItem: (item: T) => ReactNode;
}
```

## References

- [TypeScript Generics](https://www.typescriptlang.org/docs/handbook/2/generics.html)
- [React TypeScript Cheatsheet - Generics](https://react-typescript-cheatsheet.netlify.app/docs/advanced/patterns_by_usecase#generic-components)
