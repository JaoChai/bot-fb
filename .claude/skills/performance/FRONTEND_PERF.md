# Frontend Performance Guide

## Core Web Vitals Targets

| Metric | Good | Needs Improvement | Poor |
|--------|------|-------------------|------|
| LCP (Largest Contentful Paint) | ≤ 2.5s | ≤ 4.0s | > 4.0s |
| FID (First Input Delay) | ≤ 100ms | ≤ 300ms | > 300ms |
| CLS (Cumulative Layout Shift) | ≤ 0.1 | ≤ 0.25 | > 0.25 |
| INP (Interaction to Next Paint) | ≤ 200ms | ≤ 500ms | > 500ms |

## Bundle Size Optimization

### Analyze Bundle

```bash
# Build with bundle analysis
npm run build -- --report

# Or use source-map-explorer
npm install -D source-map-explorer
npx source-map-explorer dist/assets/*.js
```

### Code Splitting

```typescript
// Route-based splitting
import { lazy, Suspense } from 'react';

const Dashboard = lazy(() => import('./pages/Dashboard'));
const Settings = lazy(() => import('./pages/Settings'));

function App() {
  return (
    <Suspense fallback={<PageLoader />}>
      <Routes>
        <Route path="/dashboard" element={<Dashboard />} />
        <Route path="/settings" element={<Settings />} />
      </Routes>
    </Suspense>
  );
}
```

### Tree Shaking

```typescript
// ❌ Imports entire library
import _ from 'lodash';
_.debounce(fn, 300);

// ✅ Import only what you need
import debounce from 'lodash/debounce';
debounce(fn, 300);

// ✅ Or use lodash-es for tree shaking
import { debounce } from 'lodash-es';
```

### Dynamic Imports for Heavy Libraries

```typescript
// ❌ Always loaded
import Chart from 'chart.js';

// ✅ Load when needed
const loadChart = async () => {
  const { Chart } = await import('chart.js');
  return Chart;
};

function Analytics() {
  const [Chart, setChart] = useState(null);

  useEffect(() => {
    loadChart().then(setChart);
  }, []);

  if (!Chart) return <Skeleton />;
  // render chart
}
```

## React Performance

### Memoization

```typescript
// Memoize expensive computations
const sortedItems = useMemo(
  () => items.sort((a, b) => a.name.localeCompare(b.name)),
  [items]
);

// Memoize callbacks to prevent child re-renders
const handleClick = useCallback(
  (id: string) => setSelected(id),
  [] // stable reference
);

// Memoize entire components
const MemoizedList = memo(function List({ items }: { items: Item[] }) {
  return items.map(item => <Item key={item.id} {...item} />);
});
```

### Virtualization for Long Lists

```typescript
import { useVirtualizer } from '@tanstack/react-virtual';

function VirtualList({ items }) {
  const parentRef = useRef<HTMLDivElement>(null);

  const virtualizer = useVirtualizer({
    count: items.length,
    getScrollElement: () => parentRef.current,
    estimateSize: () => 50, // estimated row height
  });

  return (
    <div ref={parentRef} style={{ height: 400, overflow: 'auto' }}>
      <div style={{ height: virtualizer.getTotalSize() }}>
        {virtualizer.getVirtualItems().map((virtualRow) => (
          <div
            key={virtualRow.key}
            style={{
              position: 'absolute',
              top: 0,
              transform: `translateY(${virtualRow.start}px)`,
              height: virtualRow.size,
            }}
          >
            {items[virtualRow.index].name}
          </div>
        ))}
      </div>
    </div>
  );
}
```

### Avoid Unnecessary Re-renders

```typescript
// ❌ Creates new object every render
<Component style={{ color: 'red' }} />

// ✅ Stable reference
const style = { color: 'red' };
<Component style={style} />

// ❌ Creates new function every render
<Button onClick={() => handleClick(id)} />

// ✅ Stable callback
const onClick = useCallback(() => handleClick(id), [id]);
<Button onClick={onClick} />
```

## Image Optimization

### Lazy Loading

```tsx
// Native lazy loading
<img src="image.jpg" loading="lazy" alt="..." />

// With placeholder
<img
  src="image.jpg"
  loading="lazy"
  alt="..."
  style={{ backgroundColor: '#f0f0f0' }}
/>
```

### Responsive Images

```tsx
<picture>
  <source media="(min-width: 1024px)" srcSet="large.webp" type="image/webp" />
  <source media="(min-width: 768px)" srcSet="medium.webp" type="image/webp" />
  <img src="small.jpg" alt="..." loading="lazy" />
</picture>
```

### Image Dimensions

```tsx
// ✅ Prevents CLS
<img
  src="image.jpg"
  width={800}
  height={600}
  alt="..."
  style={{ maxWidth: '100%', height: 'auto' }}
/>
```

## Network Optimization

### Prefetching

```typescript
// Prefetch on hover
const queryClient = useQueryClient();

function BotCard({ bot }) {
  const prefetch = () => {
    queryClient.prefetchQuery({
      queryKey: ['bot', bot.id],
      queryFn: () => fetchBot(bot.id),
    });
  };

  return (
    <Link to={`/bots/${bot.id}`} onMouseEnter={prefetch}>
      {bot.name}
    </Link>
  );
}
```

### Request Deduplication

```typescript
// React Query automatically deduplicates
// Multiple components calling same query = 1 request

function BotName({ botId }) {
  const { data } = useQuery({
    queryKey: ['bot', botId],
    queryFn: () => fetchBot(botId),
    staleTime: 5 * 60 * 1000, // 5 minutes
  });

  return <span>{data?.name}</span>;
}
```

### Caching Strategy

```typescript
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 5 * 60 * 1000, // 5 minutes
      gcTime: 30 * 60 * 1000, // 30 minutes
      refetchOnWindowFocus: false,
    },
  },
});
```

## Preventing Layout Shift (CLS)

### Reserve Space

```tsx
// ✅ Reserve space for dynamic content
<div style={{ minHeight: 200 }}>
  {isLoading ? <Skeleton height={200} /> : <Content />}
</div>

// ✅ Aspect ratio container
<div style={{ aspectRatio: '16/9', backgroundColor: '#f0f0f0' }}>
  <img src="..." alt="..." style={{ width: '100%' }} />
</div>
```

### Font Loading

```css
/* Prevent FOUT (Flash of Unstyled Text) */
@font-face {
  font-family: 'CustomFont';
  src: url('/fonts/custom.woff2') format('woff2');
  font-display: swap; /* or 'optional' for less CLS */
}
```

## Monitoring

### Web Vitals Tracking

```typescript
import { getCLS, getFID, getLCP, getFCP, getTTFB } from 'web-vitals';

function sendToAnalytics(metric) {
  console.log(metric.name, metric.value);
  // Send to your analytics service
}

getCLS(sendToAnalytics);
getFID(sendToAnalytics);
getLCP(sendToAnalytics);
```

### Performance Observer

```typescript
const observer = new PerformanceObserver((list) => {
  for (const entry of list.getEntries()) {
    console.log(`${entry.name}: ${entry.duration}ms`);
  }
});

observer.observe({ entryTypes: ['measure', 'resource'] });
```

## Build Optimization

### Vite Configuration

```typescript
// vite.config.ts
export default defineConfig({
  build: {
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['react', 'react-dom'],
          router: ['react-router-dom'],
          query: ['@tanstack/react-query'],
        },
      },
    },
    // Enable compression
    minify: 'terser',
    terserOptions: {
      compress: {
        drop_console: true,
        drop_debugger: true,
      },
    },
  },
});
```

### Compression

```bash
# Enable gzip/brotli on server
# Or use vite plugin
npm install -D vite-plugin-compression

# vite.config.ts
import compression from 'vite-plugin-compression';

export default defineConfig({
  plugins: [
    compression({ algorithm: 'gzip' }),
    compression({ algorithm: 'brotliCompress' }),
  ],
});
```

## Checklist

- [ ] Bundle size < 500KB gzipped
- [ ] LCP < 2.5s
- [ ] CLS < 0.1
- [ ] Images lazy loaded
- [ ] Large dependencies code-split
- [ ] API responses cached
- [ ] Skeleton loaders for async content
- [ ] Font display: swap
