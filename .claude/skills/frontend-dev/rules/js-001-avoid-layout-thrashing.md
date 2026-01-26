---
id: js-001-avoid-layout-thrashing
title: Avoid Layout Thrashing (Interleaved DOM Reads/Writes)
impact: MEDIUM
impactDescription: "Prevents forced reflows - can improve animation smoothness 10x"
category: js
tags: [dom, performance, layout, reflow]
relatedRules: [perf-001-memoization]
---

## Why This Matters

Layout thrashing occurs when you interleave DOM reads and writes. Each read after a write forces the browser to synchronously recalculate layout. This blocks the main thread and causes janky animations.

## Bad Example

```tsx
// Problem: Read-write-read-write pattern
function resizeElements(elements: HTMLElement[]) {
  elements.forEach(el => {
    const width = el.offsetWidth;      // READ → forces layout
    el.style.width = `${width * 2}px`; // WRITE → invalidates layout
    // Next iteration: READ forces layout again!
  });
  // N elements = N forced layouts = very slow
}

// Problem: Multiple reads and writes interleaved
function updateLayout() {
  const height1 = div1.offsetHeight;  // READ
  div1.style.height = '100px';        // WRITE
  const height2 = div2.offsetHeight;  // READ → FORCED LAYOUT
  div2.style.height = '100px';        // WRITE
  const height3 = div3.offsetHeight;  // READ → FORCED LAYOUT
  div3.style.height = '100px';        // WRITE
}
```

**Why it's wrong:**
- Each read after write forces synchronous layout
- Browser can't batch operations
- Main thread blocked = janky UI

## Good Example

```tsx
// Solution: Batch reads, then batch writes
function resizeElements(elements: HTMLElement[]) {
  // Phase 1: Read all values first
  const widths = elements.map(el => el.offsetWidth);

  // Phase 2: Write all values
  elements.forEach((el, i) => {
    el.style.width = `${widths[i] * 2}px`;
  });
  // Only 1 layout calculation needed!
}

// Solution: Batch reads and writes
function updateLayout() {
  // Batch reads
  const height1 = div1.offsetHeight;
  const height2 = div2.offsetHeight;
  const height3 = div3.offsetHeight;

  // Batch writes
  div1.style.height = '100px';
  div2.style.height = '100px';
  div3.style.height = '100px';
  // Single layout recalculation
}
```

**Why it's better:**
- Single layout calculation
- Browser can batch DOM updates
- Smooth 60fps animations possible

## Use requestAnimationFrame for Animations

```tsx
// Schedule writes for next frame
function animateElements(elements: HTMLElement[]) {
  // Read phase
  const measurements = elements.map(el => el.getBoundingClientRect());

  // Write phase in next frame
  requestAnimationFrame(() => {
    elements.forEach((el, i) => {
      el.style.transform = `translateX(${measurements[i].width}px)`;
    });
  });
}
```

## React-Specific: useLayoutEffect for Measurements

```tsx
// When you need to measure and update DOM
function Tooltip({ targetRef }: Props) {
  const [position, setPosition] = useState({ x: 0, y: 0 });

  useLayoutEffect(() => {
    // Read
    const rect = targetRef.current?.getBoundingClientRect();

    // Calculate
    if (rect) {
      // Write (via state, which batches)
      setPosition({ x: rect.left, y: rect.bottom });
    }
  }, [targetRef]);

  return <div style={{ left: position.x, top: position.y }}>...</div>;
}
```

## Properties That Trigger Layout

```tsx
// These reads force layout if DOM was modified:
element.offsetWidth / offsetHeight / offsetTop / offsetLeft
element.clientWidth / clientHeight
element.scrollWidth / scrollHeight
element.getBoundingClientRect()
window.getComputedStyle(element)
element.innerText  // Yes, this too!

// Safe reads (don't force layout):
element.className
element.id
element.getAttribute('data-x')
element.style.width  // Returns set value, not computed
```

## Project-Specific Notes

Watch for layout thrashing in:
- Chat scroll position management
- Conversation list animations
- Modal positioning
- Tooltip/popover placement

## References

- [What forces layout/reflow](https://gist.github.com/paulirish/5d52fb081b3570c81e3a)
- [Avoid large, complex layouts and layout thrashing](https://web.dev/avoid-large-complex-layouts-and-layout-thrashing/)
