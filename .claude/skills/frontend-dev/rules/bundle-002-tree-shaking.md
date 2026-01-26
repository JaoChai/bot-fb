---
id: bundle-002-tree-shaking
title: Ensure Proper Tree-Shaking
impact: HIGH
impactDescription: "Removes unused code - can reduce bundle by 30-50%"
category: bundle
tags: [bundle, tree-shaking, esm, performance]
relatedRules: [bundle-001-barrel-imports, perf-005-bundle-size]
---

## Why This Matters

Tree-shaking removes unused exports from your bundle. But it only works with ES modules and can be blocked by side effects or CommonJS imports. Understanding these patterns ensures your bundle stays lean.

## Bad Example

```tsx
// Problem 1: CommonJS imports can't be tree-shaken
const { format } = require('date-fns');  // CJS - no tree-shaking

// Problem 2: Side effects block tree-shaking
import './setup';  // Side effect import - always included

// Problem 3: Re-exporting everything
// utils/index.ts
export * from './date';
export * from './string';
export * from './number';
// All utilities included even if one is used
```

**Why it's wrong:**
- CommonJS uses dynamic require, can't be analyzed
- Side effects must be included (bundler can't know they're safe to remove)
- Wildcard re-exports include everything

## Good Example

```tsx
// Solution 1: Use ES module imports
import { format } from 'date-fns';  // ESM - tree-shakeable

// Solution 2: Mark side-effect-free in package.json
// package.json
{
  "sideEffects": false,  // Tell bundler no side effects
  // Or be specific:
  "sideEffects": ["*.css", "./src/setup.ts"]
}

// Solution 3: Named re-exports
// utils/index.ts
export { formatDate, parseDate } from './date';
export { capitalize, truncate } from './string';
// Only imported functions included
```

**Why it's better:**
- ES modules can be statically analyzed
- Bundler knows what's safe to remove
- Named exports are explicit

## Check If Tree-Shaking Works

```tsx
// Test: Import one function, check bundle
import { format } from 'date-fns';

// Build and check:
// If bundle includes format only → tree-shaking works
// If bundle includes all date-fns → tree-shaking broken

// Use bundle analyzer
npm run build -- --report
```

## Dynamic Imports for Optional Features

```tsx
// Heavy optional feature - load only when needed
async function exportToPdf() {
  const { jsPDF } = await import('jspdf');  // Only loaded when called
  const doc = new jsPDF();
  // ...
}

// Conditional heavy component
const MonacoEditor = lazy(() =>
  import('./MonacoEditor')  // Only in bundle if used
);
```

## Avoid Common Tree-Shaking Killers

```tsx
// Bad: Object spread of entire module
import * as utils from './utils';
const result = { ...utils };  // All utils included

// Bad: Dynamic property access
import * as icons from 'lucide-react';
const Icon = icons[iconName];  // All icons included

// Good: Explicit imports
import { Home, Settings } from 'lucide-react';
const iconMap = { home: Home, settings: Settings };
const Icon = iconMap[iconName];
```

## Project-Specific Notes

BotFacebook Vite config should have:
```ts
// vite.config.ts
export default defineConfig({
  build: {
    rollupOptions: {
      output: {
        manualChunks: {
          // Split large dependencies
          'react-vendor': ['react', 'react-dom'],
          'query': ['@tanstack/react-query'],
        },
      },
    },
  },
});
```

## References

- [Vite Tree Shaking](https://vitejs.dev/guide/features.html#tree-shaking)
- [Webpack Side Effects](https://webpack.js.org/guides/tree-shaking/#mark-the-file-as-side-effect-free)
