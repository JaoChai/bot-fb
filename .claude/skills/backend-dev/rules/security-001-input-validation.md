---
id: security-001-input-validation
title: Input Validation with FormRequest
impact: CRITICAL
impactDescription: "Prevents security vulnerabilities and data corruption from unvalidated input"
category: security
tags: [security, validation, formrequest, input]
relatedRules: [laravel-004-formrequest, security-002-sql-injection]
---

## Why This Matters

All user input must be validated before use. Unvalidated input can lead to SQL injection, XSS, data corruption, and business logic bypass. Laravel's FormRequest provides automatic validation before controller code executes.

## Bad Example

```php
// Problem: No validation - extremely dangerous
public function store(Request $request)
{
    $bot = Bot::create($request->all()); // Mass assignment vulnerability
    return $bot;
}

// Problem: Inline validation - clutters controller
public function store(Request $request)
{
    $data = $request->validate([
        'name' => 'required|string|max:255',
        'platform' => 'required|in:line,telegram',
        // 20+ more rules...
    ]);

    // Controller is now 50+ lines
}
```

**Why it's wrong:**
- `$request->all()` bypasses validation entirely
- Mass assignment can set unintended fields
- Inline validation bloats controllers
- Validation rules not reusable
- No custom error messages

## Good Example

```php
// Solution: FormRequest class
// app/Http/Requests/Bot/StoreBotRequest.php
class StoreBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Or policy check
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
            'platform.in' => 'Invalid platform. Must be line, telegram, or messenger',
        ];
    }
}

// Clean controller
class BotController extends Controller
{
    public function store(StoreBotRequest $request): BotResource
    {
        $bot = $this->service->create(
            auth()->user(),
            $request->validated() // Only validated data
        );

        return new BotResource($bot);
    }
}
```

**Why it's better:**
- Validation runs before controller
- `$request->validated()` returns only validated fields
- Custom error messages for UX
- Reusable validation rules
- Clean, focused controller

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
├── Message/
│   └── SendMessageRequest.php
└── Auth/
    ├── LoginRequest.php
    └── RegisterRequest.php
```

**Common Validation Rules:**
```php
// Platform validation
'platform' => ['required', Rule::in(['line', 'telegram', 'messenger'])]

// JSON array validation
'metadata' => ['nullable', 'array']
'metadata.*.key' => ['required', 'string']

// File validation
'avatar' => ['nullable', 'image', 'max:2048'] // 2MB max
```

## References

- [Laravel Validation](https://laravel.com/docs/validation)
- [Form Request Validation](https://laravel.com/docs/validation#form-request-validation)
