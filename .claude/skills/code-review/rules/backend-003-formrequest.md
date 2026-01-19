---
id: backend-003-formrequest
title: FormRequest Validation
impact: MEDIUM
impactDescription: "Inline validation clutters controllers and isn't reusable"
category: backend
tags: [laravel, validation, formrequest, clean-code]
relatedRules: [backend-001-thin-controller, api-003-validation]
---

## Why This Matters

FormRequest classes separate validation from controller logic, making validation reusable, testable, and keeping controllers clean.

## Bad Example

```php
public function store(Request $request)
{
    // Validation in controller
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'email' => 'required|email|unique:users',
        'system_prompt' => 'required|string|min:10',
        'model' => 'required|in:gpt-4o,claude-3',
    ]);

    // Now business logic starts...
}

public function update(Request $request, Bot $bot)
{
    // Same validation duplicated
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        // ... same rules again
    ]);
}
```

**Why it's wrong:**
- Validation duplicated
- Controller bloated
- Can't test validation separately
- Authorization mixed in

## Good Example

```php
// FormRequest class
class StoreBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Bot::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'system_prompt' => ['required', 'string', 'min:10'],
            'model' => ['required', Rule::in(config('llm-models.available'))],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Bot name is required',
            'model.in' => 'Selected model is not available',
        ];
    }
}

// Clean controller
public function store(StoreBotRequest $request)
{
    $bot = $this->botService->create(
        auth()->user(),
        $request->validated()
    );

    return new BotResource($bot);
}
```

**Why it's better:**
- Validation centralized
- Authorization in one place
- Custom messages supported
- Controller stays clean

## Review Checklist

- [ ] No `$request->validate()` in controllers
- [ ] FormRequest for each create/update action
- [ ] `authorize()` method returns appropriate check
- [ ] `rules()` uses array syntax (not pipe)
- [ ] Custom messages for user-facing errors

## Detection

```bash
# Inline validation
grep -rn "\->validate(" --include="*.php" app/Http/Controllers/

# Missing FormRequests
ls app/Http/Requests/ | wc -l

# Compare to controllers needing validation
grep -rn "public function store\|public function update" --include="*.php" app/Http/Controllers/ | wc -l
```

## Project-Specific Notes

**BotFacebook FormRequest Pattern:**

```php
// app/Http/Requests/Bot/UpdateBotRequest.php
class UpdateBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('bot'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'system_prompt' => ['sometimes', 'string', 'min:10'],
            'model' => ['sometimes', Rule::in(array_keys(config('llm-models.models')))],
            'temperature' => ['sometimes', 'numeric', 'min:0', 'max:2'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Normalize data before validation
        if ($this->has('name')) {
            $this->merge(['name' => trim($this->name)]);
        }
    }
}
```
