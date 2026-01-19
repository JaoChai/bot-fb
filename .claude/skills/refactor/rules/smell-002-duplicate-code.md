---
id: smell-002-duplicate-code
title: Duplicate Code Detection
impact: HIGH
impactDescription: "Detect and eliminate duplicated code"
category: smell
tags: [code-smell, duplication, dry, refactor]
relatedRules: [laravel-003-extract-trait, react-003-extract-utility]
---

## Code Smell

- Same code in multiple places
- Copy-pasted with minor variations
- Similar method signatures
- Fix in one place, forget others
- "I've seen this before"

## Root Cause

1. Quick copy-paste solution
2. No shared utility established
3. Unfamiliar with existing code
4. Different developers wrote similar code
5. Fear of touching shared code

## Detection

### Quick Scan

```bash
# Find similar PHP code patterns
grep -rn "function processMessage" app/

# Find repeated imports in React
grep -rn "import { useState, useEffect }" src/

# Find similar function names
grep -rn "formatDate" src/
```

### Duplication Types

| Type | Example | Solution |
|------|---------|----------|
| Exact | Same code copied | Extract function/method |
| Structural | Same logic, different names | Extract with parameters |
| Algorithmic | Same algorithm, different data | Extract with generics |

## Solution

### Before (Duplicated Code)

```php
// BotController.php
class BotController extends Controller
{
    public function index()
    {
        $bots = Bot::where('user_id', auth()->id())
            ->with('platform')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => BotResource::collection($bots),
        ]);
    }

    public function show(Bot $bot)
    {
        $this->authorize('view', $bot);

        return response()->json([
            'success' => true,
            'data' => new BotResource($bot->load('platform')),
        ]);
    }
}

// ConversationController.php - SAME PATTERNS!
class ConversationController extends Controller
{
    public function index(Bot $bot)
    {
        $conversations = Conversation::where('bot_id', $bot->id)
            ->with('latestMessage')
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => ConversationResource::collection($conversations),
        ]);
    }

    public function show(Conversation $conversation)
    {
        $this->authorize('view', $conversation);

        return response()->json([
            'success' => true,
            'data' => new ConversationResource($conversation->load('messages')),
        ]);
    }
}
```

### After (Extracted)

```php
// app/Http/Traits/ApiResponses.php
trait ApiResponses
{
    protected function success($data, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
        ], $status);
    }

    protected function error(string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    protected function created($data): JsonResponse
    {
        return $this->success($data, 201);
    }

    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }
}

// BotController.php - CLEAN
class BotController extends Controller
{
    use ApiResponses;

    public function index()
    {
        $bots = Bot::forUser(auth()->user())
            ->with('platform')
            ->latest()
            ->get();

        return $this->success(BotResource::collection($bots));
    }

    public function show(Bot $bot)
    {
        $this->authorize('view', $bot);

        return $this->success(new BotResource($bot->load('platform')));
    }
}

// ConversationController.php - CLEAN
class ConversationController extends Controller
{
    use ApiResponses;

    public function index(Bot $bot)
    {
        $conversations = Conversation::forBot($bot)
            ->with('latestMessage')
            ->latest('updated_at')
            ->get();

        return $this->success(ConversationResource::collection($conversations));
    }
}
```

### React Example

```tsx
// Before - Duplicated in BotCard.tsx, ConversationItem.tsx, UserProfile.tsx
function BotCard({ bot }) {
    const formatDate = (date: string) => {
        return new Date(date).toLocaleDateString('th-TH', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const truncate = (text: string, max: number) => {
        if (text.length <= max) return text;
        return text.slice(0, max) + '...';
    };

    // ...
}

// After - Extracted to lib/format.ts
export function formatDate(date: string | Date, locale = 'th-TH'): string {
    return new Date(date).toLocaleDateString(locale, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export function truncate(text: string, max: number): string {
    if (text.length <= max) return text;
    return text.slice(0, max).trim() + '...';
}

// Usage in components
import { formatDate, truncate } from '@/lib/format';

function BotCard({ bot }) {
    return (
        <div>
            <h3>{truncate(bot.name, 30)}</h3>
            <span>{formatDate(bot.created_at)}</span>
        </div>
    );
}
```

## Step-by-Step

1. **Find duplications**
   ```bash
   # Search for similar code
   grep -rn "response()->json" app/Http/Controllers/
   ```

2. **Analyze patterns**
   - What's identical?
   - What varies?
   - Can variations be parameters?

3. **Choose extraction target**
   - Same class → private method
   - Multiple classes → trait/utility
   - Multiple files → shared module

4. **Extract**
   - Create new method/function
   - Parameterize variations
   - Add types

5. **Replace all occurrences**
   - Import/use new utility
   - Remove duplicate code
   - Test each location

## Verification

```bash
# Before: Find duplicates
grep -rn "response()->json" app/ | wc -l

# After: Should be reduced
grep -rn "response()->json" app/ | wc -l
# New: Uses trait
grep -rn "->success(" app/ | wc -l
```

## Anti-Patterns

- **Premature extraction**: Don't extract until 2+ uses
- **Wrong abstraction**: Extract similar, not slightly different
- **Over-generalization**: Keep it simple
- **Breaking cohesion**: Related code should stay together

## Project-Specific Notes

**BotFacebook Context:**
- Common duplications: API responses, date formatting, validation
- Traits location: `app/Http/Traits/`
- Utilities: `src/lib/` for frontend
- Pattern: Rule of Three - extract on third occurrence
