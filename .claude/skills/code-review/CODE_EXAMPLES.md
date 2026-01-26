# Code Review Examples

Good and bad patterns for BotFacebook code review.

## Backend (Laravel)

### Controller Pattern

```php
// ✅ Good: Thin controller with service layer
class BotController extends Controller
{
    public function __construct(
        private BotService $service
    ) {}

    public function store(StoreBotRequest $request): BotResource
    {
        $bot = $this->service->create(
            $request->validated(),
            $request->user()
        );

        return new BotResource($bot);
    }

    public function update(UpdateBotRequest $request, Bot $bot): BotResource
    {
        $this->authorize('update', $bot);

        $bot = $this->service->update($bot, $request->validated());

        return new BotResource($bot);
    }
}

// ❌ Bad: Fat controller with business logic
class BotController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->all();  // No validation!

        // Business logic in controller
        $bot = Bot::create([
            'name' => $data['name'],
            'user_id' => auth()->id(),
            'settings' => json_encode($data['settings']),
        ]);

        // More business logic
        if ($data['enable_ai']) {
            $bot->update(['ai_enabled' => true]);
        }

        return $bot;  // No resource transformation
    }
}
```

### Service Pattern

```php
// ✅ Good: Service with clear responsibility
class BotService
{
    public function __construct(
        private ConversationService $conversationService,
        private KnowledgeBaseService $knowledgeBaseService
    ) {}

    public function create(array $data, User $user): Bot
    {
        return DB::transaction(function () use ($data, $user) {
            $bot = Bot::create([
                ...$data,
                'user_id' => $user->id,
            ]);

            if (!empty($data['knowledge_base_ids'])) {
                $this->knowledgeBaseService->attachToBots(
                    $data['knowledge_base_ids'],
                    $bot
                );
            }

            return $bot->load('knowledgeBases');
        });
    }
}

// ❌ Bad: God service doing everything
class BotService
{
    public function doEverything($data)
    {
        // 500 lines of mixed concerns...
    }
}
```

### Model Pattern

```php
// ✅ Good: Model with proper casts and relationships
class Bot extends Model
{
    protected $fillable = [
        'name',
        'platform',
        'settings',
        'status',
    ];

    protected $casts = [
        'settings' => 'array',
        'status' => BotStatus::class,
        'last_active_at' => 'datetime',
    ];

    // Relationships
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    // Scopes
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', BotStatus::Active);
    }
}

// ❌ Bad: Model with business logic
class Bot extends Model
{
    protected $guarded = [];  // Dangerous!

    public function processMessage($message)
    {
        // Business logic doesn't belong in model
    }
}
```

## Frontend (React)

### Component Pattern

```typescript
// ✅ Good: Component with proper types and hooks
interface BotListProps {
  filter?: BotStatus;
  onSelect: (bot: Bot) => void;
}

export function BotList({ filter, onSelect }: BotListProps) {
  const { data: bots, isLoading, error } = useQuery({
    queryKey: queryKeys.bots.list({ filter }),
    queryFn: () => api.getBots({ filter }),
  });

  if (isLoading) return <BotListSkeleton />;
  if (error) return <ErrorMessage error={error} />;
  if (!bots?.length) return <EmptyState message="No bots found" />;

  return (
    <ul className="space-y-2">
      {bots.map((bot) => (
        <BotCard key={bot.id} bot={bot} onClick={() => onSelect(bot)} />
      ))}
    </ul>
  );
}

// ❌ Bad: Component without types or error handling
function BotList({ onSelect }) {
  const [bots, setBots] = useState([]);

  useEffect(() => {
    api.getBots().then(setBots);  // No error handling!
  }, []);

  return (
    <ul>
      {bots.map((bot) => (
        <div onClick={() => onSelect(bot)}>{bot.name}</div>
      ))}
    </ul>
  );
}
```

### Custom Hook Pattern

```typescript
// ✅ Good: Custom hook encapsulating logic
export function useBot(botId: string) {
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: queryKeys.bots.detail(botId),
    queryFn: () => api.getBot(botId),
    enabled: !!botId,
  });

  const updateMutation = useMutation({
    mutationFn: (data: UpdateBotData) => api.updateBot(botId, data),
    onSuccess: (updatedBot) => {
      queryClient.setQueryData(queryKeys.bots.detail(botId), updatedBot);
      queryClient.invalidateQueries({ queryKey: queryKeys.bots.list() });
    },
  });

  return {
    bot: query.data,
    isLoading: query.isLoading,
    error: query.error,
    update: updateMutation.mutate,
    isUpdating: updateMutation.isPending,
  };
}

// ❌ Bad: Logic scattered in component
function BotSettings({ botId }) {
  const [bot, setBot] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    setLoading(true);
    api.getBot(botId).then(setBot).finally(() => setLoading(false));
  }, [botId]);

  const handleUpdate = async (data) => {
    await api.updateBot(botId, data);
    // Forgot to update local state!
    // Forgot to invalidate cache!
  };
}
```

### Form Pattern

```typescript
// ✅ Good: Form with proper validation
export function BotForm({ onSubmit }: BotFormProps) {
  const form = useForm<BotFormData>({
    resolver: zodResolver(botSchema),
    defaultValues: {
      name: '',
      platform: 'line',
    },
  });

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
        <FormField
          control={form.control}
          name="name"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Name</FormLabel>
              <FormControl>
                <Input {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <Button type="submit" disabled={form.formState.isSubmitting}>
          {form.formState.isSubmitting ? 'Saving...' : 'Save'}
        </Button>
      </form>
    </Form>
  );
}

// ❌ Bad: Form without validation
function BotForm({ onSubmit }) {
  const [name, setName] = useState('');

  return (
    <form onSubmit={(e) => {
      e.preventDefault();
      onSubmit({ name });  // No validation!
    }}>
      <input value={name} onChange={(e) => setName(e.target.value)} />
      <button type="submit">Save</button>
    </form>
  );
}
```

## Database

### Migration Pattern

```php
// ✅ Good: Migration with indexes and foreign keys
Schema::create('messages', function (Blueprint $table) {
    $table->id();
    $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->text('content');
    $table->string('type')->default('text');
    $table->jsonb('metadata')->nullable();
    $table->timestamps();

    // Indexes for common queries
    $table->index(['conversation_id', 'created_at']);
    $table->index('created_at');
});

// ❌ Bad: Migration without constraints
Schema::create('messages', function (Blueprint $table) {
    $table->id();
    $table->integer('conversation_id');  // No foreign key!
    $table->text('content');
    $table->timestamps();
    // No indexes!
});
```

## API Response

### Resource Pattern

```php
// ✅ Good: API Resource with consistent structure
class BotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'platform' => $this->platform,
            'status' => $this->status,
            'settings' => $this->when(
                $request->user()->can('viewSettings', $this->resource),
                $this->settings
            ),
            'stats' => new BotStatsResource($this->whenLoaded('stats')),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}

// ❌ Bad: Direct model return
public function show(Bot $bot)
{
    return $bot;  // Exposes all fields including sensitive data!
}
```
