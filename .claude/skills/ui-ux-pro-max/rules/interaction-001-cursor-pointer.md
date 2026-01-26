# interaction-001: Cursor Pointer

**Impact:** HIGH
**Category:** Interaction & Cursor

## Rule

Add `cursor-pointer` to all clickable/hoverable elements.

## Do

- Add `cursor-pointer` to clickable cards
- Provide visual feedback on hover (color, shadow, border)
- Use `transition-colors duration-200` for smooth transitions

## Don't

- Leave default cursor on interactive elements
- Use instant state changes or too slow animations (>500ms)
- Use scale transforms that shift layout

## Examples

```tsx
// Good
<div
  className="cursor-pointer hover:bg-gray-100 transition-colors duration-200"
  onClick={handleClick}
>
  Click me
</div>

// Bad
<div onClick={handleClick}>
  Click me
</div>
```
