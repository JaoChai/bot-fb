# Laravel 12 Patterns

## Controller Pattern

### Resource Controller

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Bot\StoreBotRequest;
use App\Http\Requests\Bot\UpdateBotRequest;
use App\Http\Resources\BotResource;
use App\Services\BotService;

class BotController extends Controller
{
    public function __construct(
        private BotService $botService
    ) {}

    public function index()
    {
        $bots = $this->botService->getUserBots(auth()->user());

        return BotResource::collection($bots)
            ->additional(['meta' => ['timestamp' => now()]]);
    }

    public function store(StoreBotRequest $request)
    {
        $bot = $this->botService->create(
            auth()->user(),
            $request->validated()
        );

        return new BotResource($bot);
    }

    public function show(Bot $bot)
    {
        $this->authorize('view', $bot);

        return new BotResource($bot->load(['settings', 'flows']));
    }

    public function update(UpdateBotRequest $request, Bot $bot)
    {
        $this->authorize('update', $bot);

        $bot = $this->botService->update($bot, $request->validated());

        return new BotResource($bot);
    }

    public function destroy(Bot $bot)
    {
        $this->authorize('delete', $bot);

        $this->botService->delete($bot);

        return response()->noContent();
    }
}
```

## FormRequest Pattern

```php
<?php

namespace App\Http\Requests\Bot;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled in controller
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'platform' => ['required', Rule::in(['line', 'telegram', 'messenger'])],
            'description' => ['nullable', 'string', 'max:1000'],
            'settings' => ['nullable', 'array'],
            'settings.welcome_message' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'กรุณาระบุชื่อ Bot',
            'platform.in' => 'Platform ไม่ถูกต้อง',
        ];
    }
}
```

## Service Pattern

```php
<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BotService
{
    public function create(User $user, array $data): Bot
    {
        return DB::transaction(function () use ($user, $data) {
            $bot = $user->bots()->create([
                'name' => $data['name'],
                'platform' => $data['platform'],
                'description' => $data['description'] ?? null,
            ]);

            if (isset($data['settings'])) {
                $bot->settings()->create($data['settings']);
            }

            // Create default flow
            $bot->flows()->create([
                'name' => 'Main Flow',
                'is_default' => true,
            ]);

            return $bot->load(['settings', 'flows']);
        });
    }

    public function update(Bot $bot, array $data): Bot
    {
        return DB::transaction(function () use ($bot, $data) {
            $bot->update([
                'name' => $data['name'] ?? $bot->name,
                'description' => $data['description'] ?? $bot->description,
            ]);

            if (isset($data['settings'])) {
                $bot->settings()->updateOrCreate(
                    ['bot_id' => $bot->id],
                    $data['settings']
                );
            }

            return $bot->fresh(['settings', 'flows']);
        });
    }

    public function delete(Bot $bot): void
    {
        DB::transaction(function () use ($bot) {
            // Soft delete related records
            $bot->conversations()->delete();
            $bot->flows()->delete();
            $bot->delete();
        });
    }

    public function getUserBots(User $user)
    {
        return $user->bots()
            ->with(['settings', 'flows' => fn($q) => $q->where('is_default', true)])
            ->withCount('conversations')
            ->latest()
            ->paginate(20);
    }
}
```

## API Resource Pattern

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'platform' => $this->platform,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'conversations_count' => $this->whenCounted('conversations'),
            'settings' => new BotSettingsResource($this->whenLoaded('settings')),
            'flows' => FlowResource::collection($this->whenLoaded('flows')),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
```

## Job Pattern

```php
<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\AI\MessageProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessIncomingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        public Message $message
    ) {}

    public function handle(MessageProcessor $processor): void
    {
        $processor->process($this->message);
    }

    public function failed(\Throwable $exception): void
    {
        \Log::error('Message processing failed', [
            'message_id' => $this->message->id,
            'error' => $exception->getMessage(),
        ]);

        $this->message->update(['status' => 'failed']);
    }
}
```

## Event & Listener Pattern

```php
<?php

// Event
namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Message $message
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("conversation.{$this->message->conversation_id}"),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'message' => new MessageResource($this->message),
        ];
    }
}

// Listener
namespace App\Listeners;

use App\Events\MessageReceived;
use App\Jobs\ProcessIncomingMessage;

class QueueMessageProcessing
{
    public function handle(MessageReceived $event): void
    {
        ProcessIncomingMessage::dispatch($event->message);
    }
}
```

## Policy Pattern

```php
<?php

namespace App\Policies;

use App\Models\Bot;
use App\Models\User;

class BotPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }

    public function create(User $user): bool
    {
        return $user->bots()->count() < 10; // Limit bots per user
    }

    public function update(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }

    public function delete(User $user, Bot $bot): bool
    {
        return $user->id === $bot->user_id;
    }
}
```

## Model Pattern

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bot extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'platform',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function settings(): HasOne
    {
        return $this->hasOne(BotSettings::class);
    }

    public function flows(): HasMany
    {
        return $this->hasMany(Flow::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }

    // Accessors
    public function getDefaultFlowAttribute()
    {
        return $this->flows()->where('is_default', true)->first();
    }
}
```

## Migration Pattern

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('platform', ['line', 'telegram', 'messenger']);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bots');
    }
};
```

## Query Scopes & Eager Loading

```php
// Avoid N+1
$bots = Bot::with(['settings', 'flows', 'conversations' => function ($query) {
    $query->latest()->limit(5);
}])->get();

// Conditional eager loading
$bots = Bot::when($includeStats, function ($query) {
    $query->withCount(['conversations', 'messages']);
})->get();

// Cursor pagination for large datasets
$messages = Message::where('bot_id', $botId)
    ->orderBy('id')
    ->cursorPaginate(50);
```

## Error Handling

```php
<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Handler extends ExceptionHandler
{
    public function register(): void
    {
        $this->renderable(function (NotFoundHttpException $e) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()],
                'errors' => [['message' => 'Resource not found']],
            ], 404);
        });

        $this->renderable(function (ValidationException $e) {
            return response()->json([
                'data' => null,
                'meta' => ['timestamp' => now()],
                'errors' => collect($e->errors())->map(fn($msgs, $field) => [
                    'field' => $field,
                    'message' => $msgs[0],
                ])->values(),
            ], 422);
        });
    }
}
```
