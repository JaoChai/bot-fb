---
id: bundle-001-barrel-imports
title: Import from Source, Not Barrel Files
impact: CRITICAL
impactDescription: "Can reduce bundle size by 50-70% for large libraries"
category: bundle
tags: [bundle, imports, tree-shaking, performance]
relatedRules: [perf-005-bundle-size, bundle-002-tree-shaking]
---

## Why This Matters

Barrel files (index.ts that re-exports everything) can prevent tree-shaking and include unused code in your bundle. Importing directly from the source file ensures only the code you use is bundled.

## Bad Example

```tsx
// Problem: Importing from barrel file
import { Button, Card, Dialog } from '@/components/ui';
// This might import ALL components even if you only use Button

import { format, parse, addDays } from 'date-fns';
// date-fns barrel can include the entire library (~200KB)

import { merge, cloneDeep, debounce } from 'lodash';
// lodash barrel includes everything (~70KB)
```

**Why it's wrong:**
- Barrel files may include all exports in bundle
- Tree-shaking doesn't always work with re-exports
- Bundle size grows unnecessarily

## Good Example

```tsx
// Solution: Import directly from source
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog } from '@/components/ui/dialog';

// date-fns: import specific functions
import { format } from 'date-fns/format';
import { parse } from 'date-fns/parse';
import { addDays } from 'date-fns/addDays';

// lodash: use lodash-es with direct imports
import merge from 'lodash-es/merge';
import cloneDeep from 'lodash-es/cloneDeep';
import debounce from 'lodash-es/debounce';
```

**Why it's better:**
- Only imported code goes into bundle
- Guaranteed tree-shaking
- Smaller bundle, faster load

## Icon Libraries (Common Issue)

```tsx
// Bad: Imports entire icon library
import { Home, Settings, User } from 'lucide-react';

// Good: Direct imports (if tree-shaking issues)
import Home from 'lucide-react/dist/esm/icons/home';
import Settings from 'lucide-react/dist/esm/icons/settings';
import User from 'lucide-react/dist/esm/icons/user';

// Note: lucide-react usually tree-shakes correctly,
// but other icon libraries (react-icons) may not
```

## When Barrel Files Are OK

```tsx
// Internal components with small re-exports are usually fine
// components/ui/index.ts with 5-10 small components

// But avoid for:
// - Large libraries (lodash, date-fns, icons)
// - Deeply nested re-exports
// - Libraries with side effects
```

## Check Your Bundle

```bash
# Analyze bundle to find large imports
npm run build -- --report
npx source-map-explorer dist/assets/*.js
```

## Project-Specific Notes

BotFacebook uses:
- `@/components/ui/` - OK (shadcn components are small)
- `date-fns` - Use direct imports
- `lucide-react` - Usually OK, but check bundle if issues

## References

- [Vercel: How we optimized package imports](https://vercel.com/blog/how-we-optimized-package-imports-in-next-js)
- [Tree Shaking - Webpack](https://webpack.js.org/guides/tree-shaking/)
