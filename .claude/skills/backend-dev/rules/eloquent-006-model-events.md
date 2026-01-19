---
id: eloquent-006-model-events
title: Model Events
impact: MEDIUM
impactDescription: "Enables automatic side effects when models change state"
category: eloquent
tags: [eloquent, model, events, lifecycle]
relatedRules: [event-001-dispatching]
---

## Why This Matters

Model events fire automatically during model lifecycle (creating, created, updating, etc.). They're useful for setting defaults, logging changes, or triggering side effects without cluttering controllers.

## Bad Example

```php
// Problem: Side effects scattered in controllers
class BotController extends Controller
{
    public function store(StoreBotRequest $request)
    {
        $bot = Bot::create($request->validated());

        // Side effects in controller
        $bot->api_key = Str::random(32);
        $bot->save();

        Log::info('Bot created', ['id' => $bot->id]);
        event(new BotCreated($bot));
    }

    public function update(UpdateBotRequest $request, Bot $bot)
    {
        $oldName = $bot->name;
        $bot->update($request->validated());

        // More scattered logic
        if ($oldName !== $bot->name) {
            Log::info('Bot renamed', ['old' => $oldName, 'new' => $bot->name]);
        }
    }
}
```

**Why it's wrong:**
- Side effects scattered
- Easy to forget
- Not triggered from other code paths
- Controllers bloated

## Good Example

```php
// Model events
class Bot extends Model
{
    protected static function booted()
    {
        // Before saving new record
        static::creating(function (Bot $bot) {
            $bot->api_key ??= Str::random(32);
            $bot->uuid ??= Str::uuid();
        });

        // After new record saved
        static::created(function (Bot $bot) {
            Log::info('Bot created', ['id' => $bot->id, 'user_id' => $bot->user_id]);

            // Dispatch event for listeners
            event(new BotCreated($bot));
        });

        // Before any save (create or update)
        static::saving(function (Bot $bot) {
            $bot->name = trim($bot->name);
        });

        // After update
        static::updated(function (Bot $bot) {
            if ($bot->wasChanged('name')) {
                Log::info('Bot renamed', [
                    'id' => $bot->id,
                    'old' => $bot->getOriginal('name'),
                    'new' => $bot->name,
                ]);
            }
        });

        // Before delete
        static::deleting(function (Bot $bot) {
            // Cleanup related data
            $bot->conversations()->delete();
        });
    }
}

// Clean controller
public function store(StoreBotRequest $request)
{
    $bot = $this->service->create($request->validated());
    return new BotResource($bot);
}
```

**Why it's better:**
- Logic centralized in model
- Always triggered
- Clean controllers
- Can check what changed

## Project-Specific Notes

**BotFacebook Event Patterns:**

```php
// Common model events
static::creating(function ($model) {
    $model->uuid ??= Str::uuid();
});

static::created(function ($model) {
    event(new ModelCreated($model));
});

// Check specific field changes
static::updated(function ($model) {
    if ($model->wasChanged('status')) {
        event(new StatusChanged($model));
    }
});
```

**Available Events:**
- `creating`, `created`
- `updating`, `updated`
- `saving`, `saved`
- `deleting`, `deleted`
- `restoring`, `restored`
- `forceDeleting`, `forceDeleted`

## References

- [Laravel Model Events](https://laravel.com/docs/eloquent#events)
