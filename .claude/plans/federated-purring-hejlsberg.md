# แผนปรับปรุง OpenRouter Integration ตาม Best Practices

## สรุปสิ่งที่ต้องปรับปรุง

| หัวข้อ | สถานะปัจจุบัน | สิ่งที่ต้องทำ |
|-------|--------------|--------------|
| Native Fallback | ❌ ใช้ client-side recursive | ใช้ `models` array |
| Usage Tracking | ❌ ไม่ได้ส่ง `usage.include` | เพิ่ม parameter |
| Reasoning Tokens | ❌ ไม่รองรับ | เพิ่ม `reasoning` parameter |
| Provider Preferences | ❌ ไม่มี | เพิ่ม latency/throughput preferences |

---

## Phase 1: Native Fallback (High Priority)

### ปัญหา
- ปัจจุบัน: เมื่อ model ล้มเหลว → recursive call → **2x latency**
- OpenRouter รองรับ: `models: ["model1", "model2"]` → **server-side fallback**

### ไฟล์ที่แก้ไข
```
backend/app/Services/OpenRouterService.php
```

### การแก้ไข

#### 1. แก้ `chat()` method (line 63-68)

**ก่อน:**
```php
$response = $this->client($apiKey, $requestTimeout)->post('/chat/completions', [
    'model' => $model,
    'messages' => $messages,
    'temperature' => $temperature,
    'max_tokens' => $maxTokens,
]);
```

**หลัง:**
```php
$payload = [
    'messages' => $messages,
    'temperature' => $temperature,
    'max_tokens' => $maxTokens,
];

// ใช้ native fallback ถ้ามี fallback model
if ($useFallback && $fallbackModel && $model !== $fallbackModel) {
    $payload['models'] = [$model, $fallbackModel];
} else {
    $payload['model'] = $model;
}

$response = $this->client($apiKey, $requestTimeout)->post('/chat/completions', $payload);
```

#### 2. ลบ recursive fallback code (line 79-82, 108-111)

**ลบ:**
```php
if ($useFallback && $model !== $fallbackModel) {
    Log::info('Attempting fallback model', ['fallback' => $fallbackModel]);
    return $this->chat($messages, $fallbackModel, $temperature, $maxTokens, false, $apiKeyOverride, null, $timeout);
}
```

#### 3. เพิ่ม fallback ให้ `chatWithTools()` และ `chatWithVision()`

เพิ่ม parameters และ logic เหมือน `chat()`

### Agent Skill: `/backend-dev`

---

## Phase 2: Usage Tracking (High Priority)

### ปัญหา
- ไม่ได้ส่ง `usage: {include: true}`
- ไม่ได้รับ `cached_tokens`, `reasoning_tokens`, `cost` จาก OpenRouter

### ไฟล์ที่แก้ไข
```
backend/app/Services/OpenRouterService.php
backend/app/Services/CostTrackingService.php
```

### การแก้ไข

#### 1. เพิ่ม usage parameter ใน payload

```php
$payload = [
    'model' => $model,
    'messages' => $messages,
    'temperature' => $temperature,
    'max_tokens' => $maxTokens,
    'usage' => [
        'include' => true,
    ],
];
```

#### 2. Parse response ใหม่

```php
return [
    'content' => $data['choices'][0]['message']['content'] ?? '',
    'model' => $data['model'] ?? $model,
    'usage' => [
        'prompt_tokens' => $data['usage']['prompt_tokens'] ?? 0,
        'completion_tokens' => $data['usage']['completion_tokens'] ?? 0,
        'total_tokens' => $data['usage']['total_tokens'] ?? 0,
        // ใหม่ - จาก OpenRouter usage tracking
        'cached_tokens' => $data['usage']['prompt_tokens_details']['cached_tokens'] ?? 0,
        'reasoning_tokens' => $data['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0,
        'cost' => $data['usage']['cost'] ?? null,
    ],
    ...
];
```

#### 3. อัพเดท CostTrackingService

- เพิ่มการ track cached_tokens (ราคาถูกกว่า)
- เพิ่มการ track reasoning_tokens

### Agent Skill: `/backend-dev`

---

## Phase 3: Reasoning Tokens (Medium Priority)

### ปัญหา
- Models ที่รองรับ reasoning (o1, o1-mini, deepseek-r1) ไม่ได้ส่ง reasoning parameters
- ไม่ได้รับ reasoning content กลับมา

### ไฟล์ที่แก้ไข
```
backend/config/llm-models.php
backend/app/Services/OpenRouterService.php
```

### การแก้ไข

#### 1. เพิ่ม supports_reasoning ใน config

```php
// config/llm-models.php
'openai/o1' => [
    'name' => 'OpenAI o1',
    'supports_vision' => false,
    'supports_reasoning' => true,
    'default_reasoning_effort' => 'medium',
    ...
],
'deepseek/deepseek-r1' => [
    'name' => 'DeepSeek R1',
    'supports_vision' => false,
    'supports_reasoning' => true,
    'default_reasoning_tokens' => 8000,
    ...
],
```

#### 2. เพิ่ม reasoning parameter ใน chat()

```php
public function chat(
    array $messages,
    ?string $model = null,
    ?float $temperature = null,
    ?int $maxTokens = null,
    bool $useFallback = true,
    ?string $apiKeyOverride = null,
    ?string $fallbackModelOverride = null,
    ?int $timeout = null,
    ?array $reasoning = null  // ใหม่
): array {
```

#### 3. เพิ่ม reasoning ใน payload

```php
// เช็คว่า model รองรับ reasoning หรือไม่
$modelConfig = config("llm-models.models.{$model}");
if ($reasoning || ($modelConfig['supports_reasoning'] ?? false)) {
    $payload['reasoning'] = $reasoning ?? [
        'effort' => $modelConfig['default_reasoning_effort'] ?? 'medium',
    ];
}
```

#### 4. Parse reasoning content

```php
return [
    'content' => $data['choices'][0]['message']['content'] ?? '',
    'reasoning' => $data['choices'][0]['message']['reasoning'] ?? null,
    ...
];
```

### Agent Skill: `/backend-dev`

---

## Phase 4: Provider Preferences (Low Priority)

### ปัญหา
- ไม่ได้ใช้ latency/throughput preferences
- OpenRouter อาจ route ไป slow provider

### ไฟล์ที่แก้ไข
```
backend/config/services.php
backend/app/Services/OpenRouterService.php
```

### การแก้ไข

#### 1. เพิ่ม config

```php
// config/services.php
'openrouter' => [
    'api_key' => env('OPENROUTER_API_KEY'),
    ...
    'provider_preferences' => [
        'preferred_max_latency' => env('OPENROUTER_MAX_LATENCY', 5),
        'preferred_min_throughput' => env('OPENROUTER_MIN_THROUGHPUT', 100),
        'data_collection' => env('OPENROUTER_DATA_COLLECTION', 'deny'),
    ],
],
```

#### 2. เพิ่ม provider ใน payload

```php
$providerPrefs = config('services.openrouter.provider_preferences');
if (!empty($providerPrefs)) {
    $payload['provider'] = [
        'preferred_max_latency' => $providerPrefs['preferred_max_latency'],
        'preferred_min_throughput' => $providerPrefs['preferred_min_throughput'],
        'data_collection' => $providerPrefs['data_collection'],
    ];
}
```

### Agent Skill: `/backend-dev`

---

## ลำดับการทำงาน

```
Phase 1: Native Fallback
├── เรียก /backend-dev
├── แก้ OpenRouterService.php
├── เพิ่ม models array support
├── ลบ recursive fallback
└── เพิ่ม fallback ให้ chatWithTools(), chatWithVision()

Phase 2: Usage Tracking
├── เรียก /backend-dev
├── เพิ่ม usage.include parameter
├── Parse cached_tokens, reasoning_tokens
└── อัพเดท CostTrackingService

Phase 3: Reasoning Tokens
├── เรียก /backend-dev
├── เพิ่ม supports_reasoning ใน config
├── เพิ่ม reasoning parameter
└── Parse reasoning content

Phase 4: Provider Preferences
├── เรียก /backend-dev
├── เพิ่ม config ใน services.php
└── เพิ่ม provider ใน payload
```

---

## การทดสอบ

### Unit Tests
```bash
php artisan test --filter OpenRouterServiceTest
```

### Manual Tests
```bash
# Test native fallback
php artisan tinker
>>> app(\App\Services\OpenRouterService::class)->chat(
...     [['role' => 'user', 'content' => 'Hello']],
...     'invalid/model',  // จะ fallback
...     null, null, true, null, 'openai/gpt-4o-mini'
... )

# Test usage tracking
# ดู response['usage']['cached_tokens']

# Test reasoning
>>> app(\App\Services\OpenRouterService::class)->chat(
...     [['role' => 'user', 'content' => 'What is 2+2?']],
...     'openai/o1-mini',
...     null, null, true, null, null, null,
...     ['effort' => 'medium']
... )
```

---

## Effort Estimation

| Phase | Effort | Risk |
|-------|--------|------|
| Phase 1: Native Fallback | 2-3 ชม. | Medium |
| Phase 2: Usage Tracking | 1-2 ชม. | Low |
| Phase 3: Reasoning Tokens | 2-3 ชม. | Low |
| Phase 4: Provider Preferences | 1-2 ชม. | Low |

**รวม: 6-10 ชั่วโมง**

---

## ไฟล์ที่เกี่ยวข้อง

| ไฟล์ | Phase |
|-----|-------|
| `backend/app/Services/OpenRouterService.php` | 1, 2, 3, 4 |
| `backend/app/Services/CostTrackingService.php` | 2 |
| `backend/config/llm-models.php` | 3 |
| `backend/config/services.php` | 4 |
