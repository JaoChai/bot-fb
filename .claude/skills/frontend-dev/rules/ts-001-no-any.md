---
id: ts-001-no-any
title: Avoid any Type
impact: HIGH
impactDescription: "Maintains type safety and catches errors at compile time"
category: ts
tags: [typescript, types, any, type-safety]
relatedRules: [ts-003-api-response-types]
---

## Why This Matters

`any` disables TypeScript's type checking, defeating the purpose of using TypeScript. Bugs that would be caught at compile time slip through to runtime. Code becomes harder to refactor because the compiler can't help.

Every `any` is a potential runtime error waiting to happen.

## Bad Example

```tsx
// Problem 1: Function parameters typed as any
function processData(data: any) {
  return data.items.map((item: any) => item.name.toUpperCase());
  // No errors if data.items doesn't exist
  // No errors if item.name is undefined
}

// Problem 2: any in generic positions
const [state, setState] = useState<any>(null);
// setState can be called with anything

// Problem 3: any in event handlers
function Form() {
  const handleSubmit = (e: any) => {
    e.preventDefault(); // Might work
    e.stopPropogation(); // Typo not caught!
    const data = e.target.value; // Wrong property
  };

  return <form onSubmit={handleSubmit} />;
}

// Problem 4: any for API responses
async function fetchUsers(): Promise<any> {
  const response = await api.get('/users');
  return response.data;
}

const users = await fetchUsers();
users.forEach(u => console.log(u.naem)); // Typo not caught!

// Problem 5: Type assertion to any
const value = someComplexType as any;
value.doesntExist.really(); // No error
```

**Why it's wrong:**
- Type errors become runtime errors
- No autocomplete or IntelliSense
- Refactoring is dangerous
- Typos in property names not caught
- Spreads through codebase (any + string = any)

## Good Example

```tsx
// Solution 1: Define proper interfaces
interface DataPayload {
  items: Item[];
  meta: {
    total: number;
    page: number;
  };
}

interface Item {
  id: string;
  name: string;
  status: 'active' | 'inactive';
}

function processData(data: DataPayload) {
  return data.items.map((item) => item.name.toUpperCase());
  // TypeScript knows item.name is string
  // Error if you access item.naem
}

// Solution 2: Use unknown for truly unknown data
function parseJSON(json: string): unknown {
  return JSON.parse(json);
}

const data = parseJSON(input);
// Must narrow type before use
if (isUserArray(data)) {
  data.forEach(user => console.log(user.name));
}

// Type guard
function isUserArray(data: unknown): data is User[] {
  return Array.isArray(data) &&
    data.every(item =>
      typeof item === 'object' &&
      item !== null &&
      'name' in item
    );
}

// Solution 3: Proper event types
function Form() {
  const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    // e.stopPropogation(); // Error! Typo caught
    e.stopPropagation(); // Correct

    const form = e.currentTarget;
    const data = new FormData(form);
  };

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value; // TypeScript knows this is string
  };

  return (
    <form onSubmit={handleSubmit}>
      <input onChange={handleChange} />
    </form>
  );
}

// Solution 4: Typed API responses
interface User {
  id: string;
  name: string;
  email: string;
}

async function fetchUsers(): Promise<User[]> {
  const response = await api.get<ApiResponse<User[]>>('/users');
  return response.data.data;
}

const users = await fetchUsers();
users.forEach(u => console.log(u.name)); // TypeScript knows u is User

// Solution 5: Generic constraints instead of any
function getValue<T extends Record<string, unknown>>(
  obj: T,
  key: keyof T
): T[keyof T] {
  return obj[key];
}

// Solution 6: Temporary unknown with assertion
function handleExternalData(data: unknown) {
  // Validate and assert
  if (!isValidResponse(data)) {
    throw new Error('Invalid data');
  }
  // Now TypeScript knows the type
  const response = data as ValidResponse;
}

// Solution 7: When you truly need flexibility - generics
function createStore<T>(initial: T) {
  let state = initial;
  return {
    get: () => state,
    set: (value: T) => { state = value; },
  };
}

const numberStore = createStore(0);
numberStore.set('string'); // Error! Expected number
```

**Why it's better:**
- Errors caught at compile time
- Full autocomplete support
- Safe refactoring
- Self-documenting code
- `unknown` is safer than `any`

## Project-Specific Notes

**Common Event Types:**

| Event | Type |
|-------|------|
| Form submit | `React.FormEvent<HTMLFormElement>` |
| Input change | `React.ChangeEvent<HTMLInputElement>` |
| Button click | `React.MouseEvent<HTMLButtonElement>` |
| Key press | `React.KeyboardEvent<HTMLElement>` |

**BotFacebook Type Locations:**
- `frontend/src/types/` - Shared type definitions
- `frontend/src/types/api.ts` - API response types
- `frontend/src/types/models.ts` - Domain models

**ESLint Rule:**
```json
{
  "@typescript-eslint/no-explicit-any": "error"
}
```

**When any Might Be Acceptable:**
- Third-party library without types (temporary)
- Migrating JavaScript to TypeScript (temporary)
- Complex generic inference issues (document why)

## References

- [TypeScript any vs unknown](https://www.typescriptlang.org/docs/handbook/2/types-from-types.html)
- [React TypeScript Cheatsheet](https://react-typescript-cheatsheet.netlify.app/)
