---
id: react-001-re-renders
title: Unnecessary React Re-renders
impact: HIGH
impactDescription: "Components re-rendering too often causing performance issues"
category: react
tags: [react, re-render, performance, optimization]
relatedRules: [react-002-memoization, react-003-virtualization]
---

## Symptom

- UI feels sluggish
- High CPU usage during interactions
- React DevTools shows many renders
- Components re-render on unrelated state changes

## Root Cause

1. Object/array as dependency creates new reference
2. Inline functions in JSX
3. Context value changes triggering all consumers
4. Parent re-render triggers child re-render
5. State updates too high in component tree

## Diagnosis

### Quick Check

```tsx
// Add render counter
function Component() {
  const renderCount = useRef(0);
  renderCount.current++;
  console.log('Render count:', renderCount.current);

  return <div>...</div>;
}
```

### Detailed Analysis

```bash
# React DevTools Profiler
# 1. Open React DevTools
# 2. Go to Profiler tab
# 3. Click Record
# 4. Interact with app
# 5. Stop and analyze
```

## Measurement

```
Before: 10+ re-renders per interaction
Target: 1-2 re-renders per interaction
```

## Solution

### Fix Steps

1. **Stabilize object references**
```tsx
// Bad: New object every render
function Parent() {
  return <Child options={{ theme: 'dark' }} />;
}

// Good: Memoize object
function Parent() {
  const options = useMemo(() => ({ theme: 'dark' }), []);
  return <Child options={options} />;
}

// Good: Move outside if static
const options = { theme: 'dark' };
function Parent() {
  return <Child options={options} />;
}
```

2. **Stabilize callback references**
```tsx
// Bad: New function every render
function Parent() {
  return <Button onClick={() => handleClick(id)} />;
}

// Good: useCallback
function Parent() {
  const handleClickMemo = useCallback(() => {
    handleClick(id);
  }, [id]);

  return <Button onClick={handleClickMemo} />;
}
```

3. **Split context by update frequency**
```tsx
// Bad: One context with everything
const AppContext = createContext({ user, theme, notifications });

// Good: Split by update frequency
const UserContext = createContext(user);          // Rarely changes
const ThemeContext = createContext(theme);        // Rarely changes
const NotificationsContext = createContext([]); // Changes often
```

4. **Use composition to avoid prop drilling**
```tsx
// Bad: Passing props through many levels
function App() {
  const [user, setUser] = useState();
  return <Layout user={user}><Page user={user} /></Layout>;
}

// Good: Composition pattern
function App() {
  const [user, setUser] = useState();
  return (
    <Layout>
      <Page user={user} />
    </Layout>
  );
}
```

5. **Move state down**
```tsx
// Bad: State too high
function Dashboard() {
  const [filter, setFilter] = useState('');
  return (
    <>
      <Header />  {/* Re-renders when filter changes */}
      <Sidebar /> {/* Re-renders when filter changes */}
      <FilteredList filter={filter} />
    </>
  );
}

// Good: State closer to usage
function Dashboard() {
  return (
    <>
      <Header />
      <Sidebar />
      <FilteredListWithState />  {/* Contains its own state */}
    </>
  );
}
```

6. **Use children pattern**
```tsx
// Bad: Content re-renders with parent
function Modal({ isOpen }) {
  return isOpen ? (
    <div className="modal">
      <ExpensiveComponent />  {/* Re-renders when isOpen changes */}
    </div>
  ) : null;
}

// Good: Children don't re-render
function Modal({ isOpen, children }) {
  return isOpen ? (
    <div className="modal">{children}</div>
  ) : null;
}

// Usage
<Modal isOpen={isOpen}>
  <ExpensiveComponent />  {/* Doesn't re-render on isOpen change */}
</Modal>
```

### Re-render Prevention Checklist

| Issue | Solution |
|-------|----------|
| Inline object | useMemo or extract |
| Inline function | useCallback |
| Context update | Split contexts |
| High state | Move state down |
| Prop drilling | Composition pattern |
| Parent re-render | React.memo child |

## Verification

```tsx
// React DevTools
// Highlight updates when components render: ✓

// Profiler
// Look for components rendering > 1 time per interaction

// Console logging
useEffect(() => {
  console.log('Component rendered');
});
```

## Prevention

- Use React DevTools Profiler
- Enable "Highlight updates"
- Review inline objects/functions
- Consider composition patterns
- Test with large data sets

## Project-Specific Notes

**BotFacebook Context:**
- Common issues: Chat message list, Bot selector
- Use Zustand selectors to prevent re-renders
- Memoize expensive components
- Profile before optimizing
