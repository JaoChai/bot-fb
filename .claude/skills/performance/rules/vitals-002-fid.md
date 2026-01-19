---
id: vitals-002-fid
title: First Input Delay (FID) / INP
impact: MEDIUM
impactDescription: "Slow response to user interactions"
category: vitals
tags: [core-web-vitals, fid, inp, interactivity]
relatedRules: [frontend-001-bundle-size, react-001-re-renders]
---

## Symptom

- Clicks feel unresponsive
- Buttons delay before action
- Form inputs lag
- Scrolling janky after interaction
- INP (Interaction to Next Paint) > 200ms

## Root Cause

1. Main thread blocked by JavaScript
2. Large JavaScript bundles
3. Heavy computations in render
4. Too many event listeners
5. Synchronous operations blocking UI

## Diagnosis

### Quick Check

```javascript
// Measure FID/INP in browser
new PerformanceObserver((list) => {
  for (const entry of list.getEntries()) {
    console.log('INP:', entry.duration, entry.name);
  }
}).observe({ type: 'event', buffered: true, durationThreshold: 16 });
```

### Detailed Analysis

```bash
# Chrome DevTools
# Performance tab > Record interaction > Check Main Thread
# Look for long tasks (> 50ms)

# Lighthouse
npx lighthouse https://www.botjao.com --only-audits=interactive
```

## Measurement

```
Before: FID > 100ms, INP > 200ms
Target: FID < 100ms (Good), INP < 200ms (Good)
```

## Solution

### Fix Steps

1. **Break up long tasks**
```typescript
// Bad: Long synchronous task
function processLargeArray(items: Item[]) {
  items.forEach(item => heavyProcessing(item));
}

// Good: Yield to main thread
async function processLargeArray(items: Item[]) {
  for (const item of items) {
    heavyProcessing(item);
    // Yield every 10 items
    if (index % 10 === 0) {
      await new Promise(resolve => setTimeout(resolve, 0));
    }
  }
}

// Better: Use scheduler API
async function processWithScheduler(items: Item[]) {
  for (const item of items) {
    await scheduler.yield();  // Yield to browser
    heavyProcessing(item);
  }
}
```

2. **Defer non-critical JavaScript**
```html
<!-- Defer non-critical scripts -->
<script src="/analytics.js" defer></script>
<script src="/chat-widget.js" async></script>
```

3. **Use Web Workers for heavy computation**
```typescript
// worker.ts
self.onmessage = (e) => {
  const result = heavyComputation(e.data);
  self.postMessage(result);
};

// main.ts
const worker = new Worker('/worker.js');
worker.postMessage(data);
worker.onmessage = (e) => {
  setResult(e.data);
};
```

4. **Optimize event handlers**
```typescript
// Bad: Heavy handler on every click
<button onClick={() => {
  expensiveOperation();
  updateState();
}}>

// Good: Debounce heavy operations
const handleClick = useDebouncedCallback(() => {
  expensiveOperation();
  updateState();
}, 100);

<button onClick={handleClick}>
```

5. **Code split event handlers**
```typescript
// Lazy load heavy handlers
const handleExport = async () => {
  const { exportToPDF } = await import('./export');
  exportToPDF(data);
};

<button onClick={handleExport}>Export PDF</button>
```

6. **Use CSS transitions instead of JS**
```css
/* Prefer CSS for animations */
.button {
  transition: transform 0.2s ease;
}
.button:active {
  transform: scale(0.95);
}
```

### INP Optimization Checklist

| Check | Action |
|-------|--------|
| No tasks > 50ms | Break up long tasks |
| Event handlers fast | < 50ms processing |
| Heavy work in worker | Use Web Workers |
| Animations in CSS | Not JavaScript |
| Input debounced | Debounce heavy handlers |

## Verification

```javascript
// Log interaction delays
new PerformanceObserver((list) => {
  list.getEntries().forEach(entry => {
    if (entry.duration > 100) {
      console.warn('Slow interaction:', entry.name, entry.duration);
    }
  });
}).observe({ type: 'event', buffered: true });

// Lighthouse interactivity audit
npx lighthouse https://www.botjao.com --only-audits=total-blocking-time
```

## Prevention

- Profile interactions regularly
- Set performance budgets
- Use requestIdleCallback for non-critical work
- Test on low-end devices
- Monitor real user INP metrics

## Project-Specific Notes

**BotFacebook Context:**
- INP target: < 200ms
- Heavy operations: Message processing, chart rendering
- Use workers for: Embedding generation preview
- Critical interactions: Chat input, button clicks
