---
id: gotcha-001-response-data-access
title: API Response Data Access
impact: CRITICAL
impactDescription: "Prevents undefined errors and data loss when accessing API responses"
category: gotcha
tags: [api, axios, response, data-access]
relatedRules: [ts-003-api-response-types, query-007-error-handling]
---

## Why This Matters

BotFacebook's API responses are wrapped in a standard format by Laravel. The axios client also wraps responses in a `data` property. This double-wrapping causes confusion and undefined errors when not handled correctly.

Accessing `response.data` directly returns the Laravel wrapper, not your actual data. You need `response.data.data` to get the real payload.

## Bad Example

```tsx
// Problem: Accessing wrong level of response
const { data } = useQuery({
  queryKey: ['bots'],
  queryFn: async () => {
    const response = await api.get('/api/v1/bots');
    return response.data; // Returns { data: [...], meta: {...} }
  },
});

// Later in component - CRASHES!
return <BotList bots={data} />; // data is { data: [...], meta: {...} }, not Bot[]
```

**Why it's wrong:**
- `response.data` is axios wrapper containing `{ data: Bot[], meta: {...} }`
- Passing wrapper object to component expecting `Bot[]` causes type errors
- Accessing `data.name` on wrapper object returns `undefined`

## Good Example

```tsx
// Solution: Access the nested data property
const { data: bots } = useQuery({
  queryKey: ['bots'],
  queryFn: async () => {
    const response = await api.get('/api/v1/bots');
    return response.data.data; // Returns Bot[] directly
  },
});

// Or destructure properly when you need meta
const { data } = useQuery({
  queryKey: ['bots'],
  queryFn: async () => {
    const response = await api.get<ApiResponse<Bot[]>>('/api/v1/bots');
    return response.data; // Returns { data: Bot[], meta: PaginationMeta }
  },
});

// Access correctly
return <BotList bots={data.data} meta={data.meta} />;
```

**Why it's better:**
- Explicitly access `response.data.data` for the actual payload
- Use TypeScript generics `ApiResponse<T>` for type safety
- Clear distinction between axios wrapper and Laravel response wrapper

## Project-Specific Notes

**API Response Structure:**
```typescript
// Laravel wraps all responses in this format
interface ApiResponse<T> {
  data: T;
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
  message?: string;
}

// Axios adds another layer
// response.data = ApiResponse<T>
// response.data.data = T (your actual data)
```

**Key Files:**
- `frontend/src/lib/api.ts` - Axios client configuration
- `frontend/src/types/api.ts` - API response type definitions

## References

- [Axios Response Schema](https://axios-http.com/docs/res_schema)
- Related rule: ts-003-api-response-types
