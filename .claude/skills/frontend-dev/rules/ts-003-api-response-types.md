---
id: ts-003-api-response-types
title: API Response Type Definitions
impact: HIGH
impactDescription: "Ensures type safety between frontend and backend data"
category: ts
tags: [typescript, api, types, responses]
relatedRules: [gotcha-001-response-data-access, ts-001-no-any]
---

## Why This Matters

API responses are a major source of runtime errors - the backend sends data, but the frontend might have wrong assumptions about its shape. Proper type definitions catch mismatches at compile time and provide autocomplete.

Without types, every `response.data.someProp` is a potential `undefined` error.

## Bad Example

```tsx
// Problem 1: No type for API response
async function fetchBot(id: string) {
  const response = await api.get(`/api/v1/bots/${id}`);
  return response.data; // What shape is this?
}

const bot = await fetchBot('123');
console.log(bot.naem); // Typo not caught!

// Problem 2: Inline type that drifts from backend
const { data } = useQuery({
  queryKey: ['bots', id],
  queryFn: () => api.get(`/api/v1/bots/${id}`).then(r => r.data),
});

// Accessing data with assumptions
<span>{data.settings.model}</span>
// Is settings nested? Is model a string? Who knows!

// Problem 3: Assuming array when it might be paginated
async function getBots() {
  const response = await api.get('/api/v1/bots');
  return response.data as Bot[]; // Actually { data: Bot[], meta: {...} }!
}

const bots = await getBots();
bots.map(b => b.name); // Runtime error: bots.map is not a function
```

**Why it's wrong:**
- No compile-time checking of property access
- Easy to miss nested wrappers (response.data.data)
- Pagination metadata lost
- Types drift from actual API structure

## Good Example

```tsx
// Solution 1: Define API wrapper types
// types/api.ts

// Laravel's standard pagination wrapper
interface PaginationMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number;
  to: number;
}

// Standard API response wrapper
interface ApiResponse<T> {
  data: T;
  message?: string;
}

// Paginated response
interface PaginatedResponse<T> {
  data: T[];
  meta: PaginationMeta;
  links?: {
    first: string;
    last: string;
    prev: string | null;
    next: string | null;
  };
}

// Solution 2: Define domain models
// types/models.ts

interface Bot {
  id: string;
  name: string;
  description: string | null;
  active: boolean;
  settings: BotSettings;
  created_at: string;
  updated_at: string;
}

interface BotSettings {
  model: string;
  temperature: number;
  system_prompt: string;
  max_tokens: number;
}

interface Conversation {
  id: string;
  bot_id: string;
  customer_id: string;
  platform: 'line' | 'facebook' | 'telegram';
  status: 'active' | 'closed';
  created_at: string;
}

interface Message {
  id: string;
  conversation_id: string;
  content: string;
  role: 'user' | 'assistant' | 'system';
  created_at: string;
}

// Solution 3: Type API calls properly
// hooks/useBots.ts

export function useBots(filters?: BotFilters) {
  return useQuery({
    queryKey: queryKeys.bots.list(filters),
    queryFn: async () => {
      const response = await api.get<PaginatedResponse<Bot>>(
        '/api/v1/bots',
        { params: filters }
      );
      return response.data; // Type: PaginatedResponse<Bot>
    },
  });
}

export function useBot(id: string) {
  return useQuery({
    queryKey: queryKeys.bots.detail(id),
    queryFn: async () => {
      const response = await api.get<ApiResponse<Bot>>(`/api/v1/bots/${id}`);
      return response.data.data; // Type: Bot
    },
    enabled: !!id,
  });
}

// Solution 4: Type mutations
interface CreateBotDTO {
  name: string;
  description?: string;
  settings: Partial<BotSettings>;
}

interface UpdateBotDTO {
  name?: string;
  description?: string;
  settings?: Partial<BotSettings>;
}

export function useCreateBot() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (data: CreateBotDTO) => {
      const response = await api.post<ApiResponse<Bot>>('/api/v1/bots', data);
      return response.data.data;
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.bots.all });
    },
  });
}

// Solution 5: Handle nested responses correctly
function BotList() {
  const { data: response } = useBots();

  // TypeScript knows response is PaginatedResponse<Bot>
  const bots = response?.data ?? [];
  const meta = response?.meta;

  return (
    <>
      {bots.map((bot) => (
        <BotCard key={bot.id} bot={bot} />
      ))}
      {meta && (
        <Pagination
          currentPage={meta.current_page}
          totalPages={meta.last_page}
        />
      )}
    </>
  );
}

// Solution 6: Type guards for runtime validation
function isBotResponse(data: unknown): data is ApiResponse<Bot> {
  return (
    typeof data === 'object' &&
    data !== null &&
    'data' in data &&
    typeof (data as any).data?.id === 'string'
  );
}

// Solution 7: Consistent naming convention
// DTO = Data Transfer Object (input to API)
// Response = Output from API
// Model = Domain entity

interface CreateBotDTO { /* ... */ }  // For POST/PUT requests
interface BotResponse { /* ... */ }   // Raw API response
interface Bot { /* ... */ }           // Domain model used in UI
```

**Why it's better:**
- Compile-time property checking
- Autocomplete for all fields
- Clear distinction between API shape and domain model
- Pagination metadata preserved
- Refactoring is safe

## Project-Specific Notes

**BotFacebook API Structure:**

| Endpoint | Response Type |
|----------|--------------|
| `GET /bots` | `PaginatedResponse<Bot>` |
| `GET /bots/:id` | `ApiResponse<Bot>` |
| `POST /bots` | `ApiResponse<Bot>` |
| `GET /conversations` | `PaginatedResponse<Conversation>` |
| `GET /conversations/:id/messages` | `PaginatedResponse<Message>` |

**Type File Locations:**
```
frontend/src/types/
├── api.ts      # ApiResponse, PaginatedResponse
├── models.ts   # Bot, Conversation, Message, etc.
├── dto.ts      # CreateBotDTO, UpdateBotDTO, etc.
└── index.ts    # Re-exports
```

**Axios Generic Type:**
```tsx
// api.get<T> returns Promise<AxiosResponse<T>>
const response = await api.get<ApiResponse<Bot>>('/bots/123');
// response.data is ApiResponse<Bot>
// response.data.data is Bot
```

## References

- [TypeScript Generics](https://www.typescriptlang.org/docs/handbook/2/generics.html)
- [Axios TypeScript](https://axios-http.com/docs/typescript)
