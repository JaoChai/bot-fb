---
id: laravel-004-formrequest
title: FormRequest Validation Classes
impact: HIGH
impactDescription: "Ensures consistent validation and keeps controllers clean"
category: laravel
tags: [validation, formrequest, input, controller]
relatedRules: [security-001-input-validation, laravel-001-thin-controller]
---

## Why This Matters

FormRequest classes extract validation logic from controllers, making it reusable and testable. They automatically validate before the controller method runs and return proper 422 responses for validation failures.

## Bad Example

```php
// Problem: Validation in controller
public function store(Request $request)
{
    $data = $request->validate([
        'name' => 'required|string|max:255',
        'platform' => 'required|in:line,telegram',
        'description' => 'nullable|string|max:1000',
        'settings' => 'nullable|array',
        'settings.welcome_message' => 'nullable|string|max:500',
        // ... 15 more rules
    ]);

    // Controller now 40+ lines
}
```

**Why it's wrong:**
- Bloats controller
- Rules not reusable
- No custom messages
- Hard to test validation

## Good Example

```php
// app/Http/Requests/Bot/StoreBotRequest.php
class StoreBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check if user can create bots
        return $this->user()->bots()->count() < 10;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(['line', 'telegram', 'messenger'])],
            'description' => ['nullable', 'string', 'max:1000'],
            'settings' => ['nullable', 'array'],
            'settings.welcome_message' => ['nullable', 'string', 'max:500'],
            'settings.ai_enabled' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Bot name is required',
            'name.max' => 'Bot name cannot exceed 255 characters',
            'platform.in' => 'Platform must be line, telegram, or messenger',
        ];
    }

    /**
     * Prepare data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim($this->name),
        ]);
    }
}

// Clean controller
public function store(StoreBotRequest $request): BotResource
{
    $bot = $this->service->create(auth()->user(), $request->validated());
    return new BotResource($bot);
}
```

**Why it's better:**
- Validation extracted from controller
- Custom error messages
- Authorization check in one place
- Data preparation hooks
- Reusable across routes

## Project-Specific Notes

**BotFacebook FormRequest Organization:**
```
app/Http/Requests/
├── Bot/
│   ├── StoreBotRequest.php
│   └── UpdateBotRequest.php
├── Flow/
│   ├── StoreFlowRequest.php
│   └── UpdateFlowRequest.php
└── Auth/
    ├── LoginRequest.php
    └── RegisterRequest.php
```

**Create FormRequest:**
```bash
php artisan make:request Bot/StoreBotRequest
```

**Common Patterns:**
```php
// Update request - make fields optional
public function rules(): array
{
    return [
        'name' => ['sometimes', 'string', 'max:255'],
        'description' => ['nullable', 'string', 'max:1000'],
    ];
}

// Unique validation on update
'email' => ['required', 'email', Rule::unique('users')->ignore($this->user)]
```

## References

- [Form Request Validation](https://laravel.com/docs/validation#form-request-validation)
