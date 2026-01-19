---
id: api-003-validation
title: API Input Validation
impact: HIGH
impactDescription: "Missing validation allows invalid data and potential security issues"
category: api
tags: [api, validation, security, formrequest]
relatedRules: [backend-003-formrequest, security-001-sql-injection]
---

## Why This Matters

APIs must validate all input. Missing validation leads to data integrity issues, security vulnerabilities, and confusing errors.

## Bad Example

```php
// No validation
public function store(Request $request)
{
    return Bot::create($request->all()); // Dangerous!
}

// Partial validation
public function update(Request $request, Bot $bot)
{
    $validated = $request->validate([
        'name' => 'required|string',
        // Missing: max length, other fields
    ]);

    $bot->update($request->all()); // Uses unvalidated data!
}

// Generic error messages
public function store(Request $request)
{
    $request->validate([
        'email' => 'required|email|unique:users',
    ]);
    // Returns: "The email has already been taken" - doesn't help API consumers
}
```

**Why it's wrong:**
- Mass assignment vulnerability
- Data integrity issues
- Unhelpful error messages
- Uses unvalidated data

## Good Example

```php
// FormRequest with complete validation
class StoreBotRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'system_prompt' => ['required', 'string', 'min:10', 'max:10000'],
            'model' => ['required', Rule::in(array_keys(config('llm-models.models')))],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'platform' => ['required', Rule::in(['line', 'telegram', 'web'])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Bot name is required',
            'name.max' => 'Bot name cannot exceed 255 characters',
            'model.in' => 'Selected model is not available in your plan',
            'system_prompt.min' => 'System prompt must be at least 10 characters',
        ];
    }
}

// Controller uses validated data only
public function store(StoreBotRequest $request)
{
    $bot = $this->botService->create(
        auth()->user(),
        $request->validated() // Only validated fields
    );

    return new BotResource($bot);
}
```

**Why it's better:**
- Complete validation rules
- Helpful error messages
- Only validated data used
- Prevents mass assignment

## Review Checklist

- [ ] All endpoints validate input
- [ ] FormRequest classes for complex validation
- [ ] Custom error messages for API consumers
- [ ] `->validated()` used, not `->all()`
- [ ] All expected fields have rules

## Detection

```bash
# Using all() instead of validated()
grep -rn "->all()" --include="*.php" app/Http/Controllers/

# Missing validation
grep -rn "public function store\|public function update" --include="*.php" app/Http/Controllers/ | xargs -I {} grep -L "validated\|validate"
```

## Project-Specific Notes

**BotFacebook Validation Patterns:**

```php
// Complex validation with dependent rules
class UpdateBotRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'system_prompt' => ['sometimes', 'string', 'min:10'],
            'model' => ['sometimes', Rule::in($this->availableModels())],
            'is_active' => ['sometimes', 'boolean'],

            // Conditional validation
            'line_channel_id' => [
                Rule::requiredIf($this->platform === 'line'),
                'nullable',
                'string',
            ],

            // Array validation
            'tools' => ['sometimes', 'array'],
            'tools.*' => ['string', Rule::in(config('tools.available'))],
        ];
    }

    private function availableModels(): array
    {
        // Based on user's subscription
        $tier = $this->user()->subscription?->tier ?? 'free';
        return array_keys(config("llm-models.tiers.{$tier}"));
    }
}

// Validation error response format
{
    "message": "The given data was invalid.",
    "errors": {
        "name": ["Bot name cannot exceed 255 characters"],
        "model": ["Selected model is not available in your plan"]
    }
}
```
