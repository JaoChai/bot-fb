---
id: laravel-004-form-request
title: Extract FormRequest Refactoring
impact: MEDIUM
impactDescription: "Move validation from controllers to dedicated request classes"
category: laravel
tags: [validation, formrequest, controller, clean-code]
relatedRules: [laravel-002-extract-service, laravel-001-extract-method]
---

## Code Smell

- Validation logic in controller
- Duplicate validation rules
- Complex authorization in controller
- Large $request->validate() blocks

## Root Cause

1. Quick prototyping habits
2. Unfamiliar with FormRequest
3. Copy-paste validation
4. No clear pattern established
5. Validation grew over time

## When to Apply

**Apply when:**
- Validation > 5 rules
- Same validation in multiple places
- Custom validation messages needed
- Authorization logic needed

**Don't apply when:**
- Simple 2-3 field validation
- One-time use endpoint
- Would add unnecessary complexity

## Solution

### Before

```php
class BotController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:bots,name',
            'platform' => 'required|in:line,telegram',
            'system_prompt' => 'required|string|min:10|max:10000',
            'model' => 'required|string|exists:llm_models,slug',
            'temperature' => 'nullable|numeric|min:0|max:2',
            'max_tokens' => 'nullable|integer|min:1|max:4000',
            'settings' => 'nullable|array',
            'settings.greeting' => 'nullable|string|max:500',
            'settings.fallback_message' => 'nullable|string|max:500',
        ], [
            'name.unique' => 'You already have a bot with this name.',
            'system_prompt.min' => 'System prompt must be at least 10 characters.',
        ]);

        // Check user can create bot
        if (auth()->user()->bots()->count() >= 10) {
            return response()->json(['error' => 'Bot limit reached'], 403);
        }

        // Continue...
    }

    public function update(Request $request, Bot $bot)
    {
        // Same validation repeated...
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:bots,name,' . $bot->id,
            'platform' => 'required|in:line,telegram',
            // ... more rules
        ]);
    }
}
```

### After

```php
// app/Http/Requests/StoreBotRequest.php
class StoreBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->bots()->count() < 10;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:bots,name'],
            'platform' => ['required', 'in:line,telegram'],
            'system_prompt' => ['required', 'string', 'min:10', 'max:10000'],
            'model' => ['required', 'string', 'exists:llm_models,slug'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:4000'],
            'settings' => ['nullable', 'array'],
            'settings.greeting' => ['nullable', 'string', 'max:500'],
            'settings.fallback_message' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a bot with this name.',
            'system_prompt.min' => 'System prompt must be at least 10 characters.',
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Bot limit reached');
    }
}

// app/Http/Requests/UpdateBotRequest.php
class UpdateBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('bot'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:bots,name,' . $this->route('bot')->id],
            'platform' => ['required', 'in:line,telegram'],
            'system_prompt' => ['required', 'string', 'min:10', 'max:10000'],
            'model' => ['required', 'string', 'exists:llm_models,slug'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:4000'],
        ];
    }
}

// app/Http/Controllers/Api/BotController.php
class BotController extends Controller
{
    public function store(StoreBotRequest $request, BotService $service)
    {
        $bot = $service->create($request->user(), $request->validated());
        return new BotResource($bot);
    }

    public function update(UpdateBotRequest $request, Bot $bot, BotService $service)
    {
        $bot = $service->update($bot, $request->validated());
        return new BotResource($bot);
    }
}
```

### Step-by-Step

1. **Create FormRequest**
   ```bash
   php artisan make:request StoreBotRequest
   ```

2. **Move validation rules**
   - Copy rules to `rules()` method
   - Use array syntax for clarity
   - Add custom messages

3. **Add authorization**
   - Implement `authorize()` method
   - Use policies if complex

4. **Update controller**
   - Type-hint FormRequest
   - Use `$request->validated()`

## Verification

```bash
# Test validation
php artisan test --filter BotRequestTest

# Verify controller uses FormRequest
grep -r "StoreBotRequest" app/Http/Controllers/
```

## Anti-Patterns

- **Skipping authorize()**: Always set explicitly
- **Business logic in FormRequest**: Keep to validation
- **Ignoring messages()**: Provide user-friendly messages
- **Not using validated()**: Always use `$request->validated()`

## Project-Specific Notes

**BotFacebook Context:**
- Requests location: `app/Http/Requests/`
- Naming: Store{Model}Request, Update{Model}Request
- Use policies for complex authorization
- Custom validation rules in `app/Rules/`
