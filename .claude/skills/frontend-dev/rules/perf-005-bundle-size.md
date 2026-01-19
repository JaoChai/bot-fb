---
id: perf-005-bundle-size
title: Bundle Size Monitoring
impact: HIGH
impactDescription: "Keeps initial load fast by controlling JavaScript bundle size"
category: perf
tags: [bundle, vite, imports, tree-shaking, performance]
relatedRules: [perf-002-code-splitting]
---

## Why This Matters

Every kilobyte of JavaScript must be downloaded, parsed, and executed before the app becomes interactive. Large bundles cause slow initial loads, especially on mobile networks.

Monitoring and optimizing bundle size is essential for good Core Web Vitals.

## Bad Example

```tsx
// Problem 1: Importing entire library
import _ from 'lodash';

function processData(data) {
  return _.sortBy(data, 'name'); // Imports ALL of lodash (~70KB)!
}

// Problem 2: Heavy library for simple task
import moment from 'moment'; // 300KB+ for date formatting!

function formatDate(date) {
  return moment(date).format('MMM DD, YYYY');
}

// Problem 3: Importing everything from a barrel file
import { Button, Input, Dialog, /* 50 more */ } from '@/components/ui';
// Even if only using Button, might bundle everything

// Problem 4: Development dependencies in production
import { faker } from '@faker-js/faker'; // Should only be in tests!

// Problem 5: Large images/assets imported in code
import heroImage from './hero.png'; // 2MB image bundled with JS
```

**Why it's wrong:**
- Full library imports prevent tree-shaking
- Heavy libraries for simple tasks waste bandwidth
- Barrel file imports can prevent dead code elimination
- Dev dependencies bloat production bundle
- Assets in JS increase parse time

## Good Example

```tsx
// Solution 1: Import only what you need
import sortBy from 'lodash/sortBy';

function processData(data) {
  return sortBy(data, 'name'); // Only imports sortBy (~2KB)
}

// Or use lodash-es for tree-shaking
import { sortBy } from 'lodash-es';

// Solution 2: Use lightweight alternatives
import { format } from 'date-fns'; // 6KB for format vs 300KB moment

function formatDate(date: Date) {
  return format(date, 'MMM dd, yyyy');
}

// Or native Intl API (0KB!)
function formatDate(date: Date) {
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(date);
}

// Solution 3: Direct imports instead of barrel files
// Instead of: import { Button } from '@/components/ui';
import { Button } from '@/components/ui/button';

// Solution 4: Dynamic imports for heavy dependencies
async function generateChart(data) {
  const { Chart } = await import('chart.js/auto');
  new Chart(/* ... */);
}

// Solution 5: Conditional imports for dev-only code
if (process.env.NODE_ENV === 'development') {
  import('@faker-js/faker').then(({ faker }) => {
    window.faker = faker;
  });
}

// Solution 6: Optimize asset loading
// Use img tag with src, not import
function Hero() {
  return <img src="/images/hero.webp" alt="Hero" loading="lazy" />;
}

// Solution 7: Analyze what's in your bundle
// vite.config.ts
import { visualizer } from 'rollup-plugin-visualizer';

export default defineConfig({
  plugins: [
    visualizer({
      filename: 'dist/stats.html',
      gzipSize: true,
    }),
  ],
});
```

**Why it's better:**
- Named imports enable tree-shaking
- Lightweight alternatives reduce bundle size
- Direct imports avoid barrel file issues
- Dynamic imports defer loading
- Dev code excluded from production

## Project-Specific Notes

**BotFacebook Bundle Analysis:**
```bash
# Generate bundle report
npm run build -- --report

# Check individual file sizes
ls -lh dist/assets/*.js

# Visualize bundle
npm run analyze
```

**Size Budget:**
| Chunk | Target | Max |
|-------|--------|-----|
| Initial (vendor) | <150KB | 200KB |
| Initial (app) | <50KB | 80KB |
| Route chunks | <80KB | 100KB |
| Total initial | <200KB | 300KB |

**Heavy Dependencies to Watch:**
| Library | Size | Alternative |
|---------|------|-------------|
| moment | 300KB | date-fns (6KB) |
| lodash | 70KB | lodash-es or native |
| chart.js | 200KB | Lazy load |
| monaco-editor | 3MB | Lazy load |

**Check Script:**
```bash
# frontend/src/.claude/skills/frontend-dev/scripts/check_bundle.sh
#!/bin/bash
npm run build
BUNDLE_SIZE=$(du -sk dist/assets/*.js | awk '{sum+=$1} END {print sum}')
if [ $BUNDLE_SIZE -gt 500 ]; then
  echo "Bundle size ${BUNDLE_SIZE}KB exceeds 500KB limit!"
  exit 1
fi
```

**Vite Chunk Splitting:**
```ts
// vite.config.ts
build: {
  rollupOptions: {
    output: {
      manualChunks: {
        vendor: ['react', 'react-dom', 'react-router-dom'],
        query: ['@tanstack/react-query'],
        ui: ['@radix-ui/react-dialog', '@radix-ui/react-dropdown-menu'],
      },
    },
  },
}
```

## References

- [Bundlephobia](https://bundlephobia.com/) - Check package sizes
- [Import Cost VS Code Extension](https://marketplace.visualstudio.com/items?itemName=wix.vscode-import-cost)
- [Vite Build Options](https://vitejs.dev/config/build-options.html)
