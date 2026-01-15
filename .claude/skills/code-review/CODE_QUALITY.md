# Code Quality Checklist

## PHP/Laravel Standards

### PSR-12 Compliance

```php
// ✅ Correct namespace declaration
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Bot;
use Illuminate\Support\Collection;

class BotService
{
    // Class body
}
```

### Type Declarations

```php
// ✅ Full type hints
public function create(User $user, array $data): Bot
{
    // ...
}

// ✅ Nullable types
public function find(int $id): ?Bot
{
    return Bot::find($id);
}

// ✅ Union types (PHP 8+)
public function process(string|int $identifier): Bot
{
    // ...
}
```

### Method Length

- [ ] Methods under 20 lines (ideal)
- [ ] Methods under 50 lines (acceptable)
- [ ] Complex logic extracted to helper methods

### Single Responsibility

```php
// ❌ Too many responsibilities
class BotController
{
    public function store(Request $request)
    {
        // Validation logic
        // Business logic
        // Notification logic
        // Response formatting
    }
}

// ✅ Separated responsibilities
class BotController
{
    public function store(StoreBotRequest $request, BotService $service)
    {
        $bot = $service->create($request->user(), $request->validated());
        return new BotResource($bot);
    }
}
```

## TypeScript/React Standards

### Type Safety

```typescript
// ✅ Explicit types
interface Bot {
  id: number;
  name: string;
  platform: 'line' | 'telegram';
  isActive: boolean;
  createdAt: string;
}

// ✅ Props typing
interface BotCardProps {
  bot: Bot;
  onSelect: (id: number) => void;
}

function BotCard({ bot, onSelect }: BotCardProps) {
  // ...
}
```

### Component Structure

```typescript
// ✅ Well-structured component
import { useCallback, useMemo } from 'react';

interface Props {
  items: Item[];
  onSelect: (id: string) => void;
}

export function ItemList({ items, onSelect }: Props) {
  // Hooks first
  const sortedItems = useMemo(
    () => items.sort((a, b) => a.name.localeCompare(b.name)),
    [items]
  );

  const handleSelect = useCallback(
    (id: string) => {
      onSelect(id);
    },
    [onSelect]
  );

  // Then render
  return (
    <ul>
      {sortedItems.map((item) => (
        <li key={item.id} onClick={() => handleSelect(item.id)}>
          {item.name}
        </li>
      ))}
    </ul>
  );
}
```

## Error Handling

### PHP Exceptions

```php
// ✅ Custom exceptions
class BotNotFoundException extends Exception
{
    public function __construct(int $id)
    {
        parent::__construct("Bot not found: {$id}");
    }
}

// ✅ Proper error handling
try {
    $bot = $this->botService->find($id);
} catch (BotNotFoundException $e) {
    Log::warning($e->getMessage());
    return response()->json(['error' => 'Bot not found'], 404);
} catch (Exception $e) {
    Log::error($e->getMessage(), ['exception' => $e]);
    return response()->json(['error' => 'Server error'], 500);
}
```

### TypeScript Error Handling

```typescript
// ✅ Type-safe error handling
interface ApiError {
  message: string;
  code: string;
  field?: string;
}

async function fetchBot(id: string): Promise<Bot> {
  const response = await fetch(`/api/bots/${id}`);

  if (!response.ok) {
    const error: ApiError = await response.json();
    throw new Error(error.message);
  }

  return response.json();
}
```

## Naming Conventions

### PHP

| Element | Convention | Example |
|---------|------------|---------|
| Classes | PascalCase | `BotService` |
| Methods | camelCase | `createBot` |
| Variables | camelCase | `$botName` |
| Constants | UPPER_SNAKE | `MAX_BOTS` |
| Tables | snake_case | `bot_settings` |
| Columns | snake_case | `created_at` |

### TypeScript

| Element | Convention | Example |
|---------|------------|---------|
| Components | PascalCase | `BotCard` |
| Functions | camelCase | `createBot` |
| Variables | camelCase | `botName` |
| Constants | UPPER_SNAKE | `MAX_BOTS` |
| Types/Interfaces | PascalCase | `BotSettings` |
| Files (components) | PascalCase | `BotCard.tsx` |
| Files (utilities) | camelCase | `formatDate.ts` |

## Code Smells to Avoid

### Magic Numbers/Strings

```php
// ❌ Magic numbers
if ($attempts > 5) { ... }

// ✅ Named constants
const MAX_LOGIN_ATTEMPTS = 5;
if ($attempts > self::MAX_LOGIN_ATTEMPTS) { ... }
```

### Deep Nesting

```php
// ❌ Deep nesting
if ($user) {
    if ($bot) {
        if ($bot->isActive) {
            if ($request->has('message')) {
                // ...
            }
        }
    }
}

// ✅ Early returns
if (!$user) return;
if (!$bot) return;
if (!$bot->isActive) return;
if (!$request->has('message')) return;

// Main logic here
```

### Long Parameter Lists

```php
// ❌ Too many parameters
function createBot($name, $platform, $description, $settings, $userId, $isActive) { }

// ✅ Use object/array
function createBot(CreateBotDTO $dto) { }

// Or
function createBot(array $data): Bot
{
    // Validate with FormRequest before this
}
```

## Testing Quality

### Test Coverage

- [ ] Happy path tested
- [ ] Edge cases tested
- [ ] Error cases tested
- [ ] Boundary conditions tested

### Test Naming

```php
// ✅ Descriptive test names
public function test_creates_bot_with_valid_data(): void { }
public function test_throws_exception_when_name_missing(): void { }
public function test_returns_empty_collection_when_no_bots(): void { }
```

### Test Structure (AAA)

```php
public function test_creates_bot(): void
{
    // Arrange
    $user = User::factory()->create();
    $data = ['name' => 'Test Bot', 'platform' => 'line'];

    // Act
    $bot = $this->service->create($user, $data);

    // Assert
    $this->assertInstanceOf(Bot::class, $bot);
    $this->assertEquals('Test Bot', $bot->name);
}
```

## Documentation

### PHPDoc

```php
/**
 * Create a new bot for the user.
 *
 * @param User $user The owner of the bot
 * @param array{name: string, platform: string, description?: string} $data
 * @return Bot
 * @throws ValidationException If data is invalid
 */
public function create(User $user, array $data): Bot
{
    // ...
}
```

### JSDoc

```typescript
/**
 * Creates a new bot with the provided data.
 *
 * @param data - The bot creation data
 * @returns Promise resolving to the created bot
 * @throws {ApiError} If the request fails
 *
 * @example
 * const bot = await createBot({ name: 'My Bot', platform: 'line' });
 */
async function createBot(data: CreateBotDTO): Promise<Bot> {
  // ...
}
```

## Quick Review Checklist

### Code Structure
- [ ] Single responsibility principle
- [ ] Methods under 50 lines
- [ ] No deep nesting (max 3 levels)
- [ ] No magic numbers/strings

### Types & Safety
- [ ] Full type hints (PHP)
- [ ] Proper TypeScript types
- [ ] Null safety handled
- [ ] Error handling in place

### Naming
- [ ] Descriptive names
- [ ] Consistent conventions
- [ ] No abbreviations (unless common)

### Testing
- [ ] Happy path covered
- [ ] Edge cases covered
- [ ] Error cases covered

### Documentation
- [ ] Complex logic documented
- [ ] Public APIs documented
- [ ] Non-obvious decisions explained
