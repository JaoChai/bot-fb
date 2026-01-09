<?php

namespace App\Http\Controllers\Flow;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Flow;
use App\Models\KnowledgeBase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class FlowController extends Controller
{
    /**
     * Display a listing of flows for user's bots.
     * Supports optional bot_id filter.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        // Get flows query with bot relationship
        $query = Flow::whereHas('bot', function ($q) use ($user) {
            // Owner can see flows from their own bots
            if ($user->isOwner()) {
                $q->where('user_id', $user->id);
            } else {
                // Admin can see flows from assigned bots
                $q->whereIn('id', $user->assignedBots()->pluck('bots.id'));
            }
        })->with(['bot:id,name,channel_type,status']);

        // Filter by bot_id if provided
        if ($request->filled('bot_id')) {
            $query->where('bot_id', $request->input('bot_id'));
        }

        // Paginate results, default flows first
        $flows = $query
            ->withCount('knowledgeBases')
            ->orderByDesc('is_default')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        // Get bots for filter dropdown
        $bots = $user->isOwner()
            ? $user->bots()->select(['id', 'name', 'channel_type'])->orderBy('name')->get()
            : $user->assignedBots()->select(['bots.id', 'bots.name', 'bots.channel_type'])->orderBy('name')->get();

        return Inertia::render('Flows/Index', [
            'flows' => $flows,
            'bots' => $bots,
            'filters' => $request->only(['bot_id', 'search']),
        ]);
    }

    /**
     * Display the flow editor page.
     */
    public function show(Flow $flow): Response
    {
        $this->authorizeFlow($flow);

        // Eager load relationships to avoid N+1
        $flow->load([
            'bot:id,name,channel_type,status,primary_chat_model,fallback_chat_model',
            'knowledgeBases',
        ]);

        // Get available knowledge bases for this bot's owner
        $availableKnowledgeBases = KnowledgeBase::where('user_id', $flow->bot->user_id)
            ->select(['id', 'name', 'description', 'document_count', 'chunk_count'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Flows/Editor', [
            'flow' => [
                'id' => $flow->id,
                'bot_id' => $flow->bot_id,
                'name' => $flow->name,
                'description' => $flow->description,
                'system_prompt' => $flow->system_prompt,
                'model' => $flow->model,
                'fallback_model' => $flow->fallback_model,
                'decision_model' => $flow->decision_model,
                'fallback_decision_model' => $flow->fallback_decision_model,
                'temperature' => (float) $flow->temperature,
                'max_tokens' => $flow->max_tokens,
                'agentic_mode' => $flow->agentic_mode,
                'max_tool_calls' => $flow->max_tool_calls,
                'agent_timeout_seconds' => $flow->agent_timeout_seconds,
                'agent_max_cost_per_request' => $flow->agent_max_cost_per_request,
                'hitl_enabled' => $flow->hitl_enabled,
                'hitl_dangerous_actions' => $flow->hitl_dangerous_actions ?? [],
                'enabled_tools' => $flow->enabled_tools ?? [],
                'language' => $flow->language,
                'is_default' => $flow->is_default,
                'second_ai_enabled' => $flow->second_ai_enabled ?? false,
                'second_ai_options' => $flow->second_ai_options ?? [
                    'fact_check' => false,
                    'policy' => false,
                    'personality' => false,
                ],
                'knowledge_bases' => $flow->knowledgeBases->map(fn ($kb) => [
                    'id' => $kb->id,
                    'name' => $kb->name,
                    'kb_top_k' => $kb->pivot->kb_top_k,
                    'kb_similarity_threshold' => (float) $kb->pivot->kb_similarity_threshold,
                ]),
                'created_at' => $flow->created_at->toIso8601String(),
                'updated_at' => $flow->updated_at->toIso8601String(),
            ],
            'bot' => $flow->bot->only(['id', 'name', 'channel_type', 'status', 'primary_chat_model', 'fallback_chat_model']),
            'availableKnowledgeBases' => $availableKnowledgeBases,
        ]);
    }

    /**
     * Store a newly created flow.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bot_id' => ['required', 'integer', 'exists:bots,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'system_prompt' => ['nullable', 'string'],
            'model' => ['nullable', 'string', 'max:100'],
            'fallback_model' => ['nullable', 'string', 'max:100'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['nullable', 'integer', 'min:100', 'max:32000'],
            'agentic_mode' => ['nullable', 'boolean'],
            'max_tool_calls' => ['nullable', 'integer', 'min:1', 'max:50'],
            'enabled_tools' => ['nullable', 'array'],
            'language' => ['nullable', 'string', 'in:th,en,zh,ja,ko'],
            'is_default' => ['nullable', 'boolean'],
            'knowledge_bases' => ['nullable', 'array'],
            'knowledge_bases.*.id' => ['required', 'integer', 'exists:knowledge_bases,id'],
            'knowledge_bases.*.kb_top_k' => ['nullable', 'integer', 'min:1', 'max:20'],
            'knowledge_bases.*.kb_similarity_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        // Authorize: user must own the bot
        $bot = Bot::findOrFail($validated['bot_id']);
        $this->authorizeBot($bot);

        // Cast boolean fields for PostgreSQL compatibility
        $validated = $this->castBooleanFields($validated);

        // Extract knowledge_bases before creating flow
        $knowledgeBases = $validated['knowledge_bases'] ?? [];
        unset($validated['knowledge_bases']);

        DB::transaction(function () use ($bot, &$validated, $knowledgeBases, &$flow) {
            // If this is marked as default, unset other defaults
            if ($validated['is_default'] ?? false) {
                $bot->flows()->update(['is_default' => false]);
            }

            // If this is the first flow, make it default
            if (!$bot->flows()->exists()) {
                $validated['is_default'] = true;
            }

            $flow = $bot->flows()->create($validated);

            // Sync knowledge bases with pivot data
            if (!empty($knowledgeBases)) {
                $syncData = [];
                foreach ($knowledgeBases as $kb) {
                    $syncData[$kb['id']] = [
                        'kb_top_k' => $kb['kb_top_k'] ?? 5,
                        'kb_similarity_threshold' => $kb['kb_similarity_threshold'] ?? 0.7,
                    ];
                }
                $flow->knowledgeBases()->sync($syncData);
            }

            // Update bot's default flow if this is the default
            if ($flow->is_default) {
                $bot->update(['default_flow_id' => $flow->id]);
            }
        });

        return redirect()
            ->route('flows.show', $flow)
            ->with('success', 'Flow created successfully');
    }

    /**
     * Update the specified flow.
     */
    public function update(Request $request, Flow $flow): RedirectResponse
    {
        $this->authorizeFlow($flow);

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'system_prompt' => ['nullable', 'string'],
            'model' => ['nullable', 'string', 'max:100'],
            'fallback_model' => ['nullable', 'string', 'max:100'],
            'decision_model' => ['nullable', 'string', 'max:100'],
            'fallback_decision_model' => ['nullable', 'string', 'max:100'],
            'temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['nullable', 'integer', 'min:100', 'max:32000'],
            'agentic_mode' => ['nullable', 'boolean'],
            'max_tool_calls' => ['nullable', 'integer', 'min:1', 'max:50'],
            'agent_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:300'],
            'agent_max_cost_per_request' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'hitl_enabled' => ['nullable', 'boolean'],
            'hitl_dangerous_actions' => ['nullable', 'array'],
            'enabled_tools' => ['nullable', 'array'],
            'language' => ['nullable', 'string', 'in:th,en,zh,ja,ko'],
            'is_default' => ['nullable', 'boolean'],
            'second_ai_enabled' => ['nullable', 'boolean'],
            'second_ai_options' => ['nullable', 'array'],
            'knowledge_bases' => ['nullable', 'array'],
            'knowledge_bases.*.id' => ['required', 'integer', 'exists:knowledge_bases,id'],
            'knowledge_bases.*.kb_top_k' => ['nullable', 'integer', 'min:1', 'max:20'],
            'knowledge_bases.*.kb_similarity_threshold' => ['nullable', 'numeric', 'min:0', 'max:1'],
        ]);

        // Cast boolean fields for PostgreSQL compatibility
        $validated = $this->castBooleanFields($validated);

        // Extract knowledge_bases before updating flow
        $knowledgeBases = $validated['knowledge_bases'] ?? null;
        unset($validated['knowledge_bases']);

        $bot = $flow->bot;

        DB::transaction(function () use ($bot, $flow, $validated, $knowledgeBases) {
            // If setting as default, unset other defaults
            if ($validated['is_default'] ?? false) {
                $bot->flows()->where('id', '!=', $flow->id)->update(['is_default' => false]);
            }

            $flow->update($validated);

            // Sync knowledge bases if provided
            if ($knowledgeBases !== null) {
                $syncData = [];
                foreach ($knowledgeBases as $kb) {
                    $syncData[$kb['id']] = [
                        'kb_top_k' => $kb['kb_top_k'] ?? 5,
                        'kb_similarity_threshold' => $kb['kb_similarity_threshold'] ?? 0.7,
                    ];
                }
                $flow->knowledgeBases()->sync($syncData);
            }

            // Update bot's default flow if this is now the default
            if ($flow->is_default) {
                $bot->update(['default_flow_id' => $flow->id]);
            }
        });

        return redirect()
            ->back()
            ->with('success', 'Flow updated successfully');
    }

    /**
     * Remove the specified flow.
     */
    public function destroy(Flow $flow): RedirectResponse
    {
        $this->authorizeFlow($flow);

        $bot = $flow->bot;

        // Prevent deleting Base Flow (default flow)
        if ($flow->is_default) {
            return redirect()
                ->back()
                ->with('error', 'Cannot delete the default flow. Set another flow as default first.');
        }

        // Don't allow deleting the only flow
        if (!$bot->flows()->where('id', '!=', $flow->id)->exists()) {
            return redirect()
                ->back()
                ->with('error', 'Cannot delete the only flow. Create another flow first.');
        }

        $flow->delete();

        return redirect()
            ->route('flows.index', ['bot_id' => $bot->id])
            ->with('success', 'Flow deleted successfully');
    }

    /**
     * Duplicate the specified flow.
     */
    public function duplicate(Flow $flow): RedirectResponse
    {
        $this->authorizeFlow($flow);

        // Eager load knowledgeBases to prevent N+1
        $flow->loadMissing('knowledgeBases');

        DB::transaction(function () use ($flow, &$newFlow) {
            $newFlow = $flow->replicate();
            $newFlow->name = $flow->name . ' (Copy)';
            $newFlow->is_default = false;
            $newFlow->save();

            // Copy knowledge base associations
            $kbData = [];
            foreach ($flow->knowledgeBases as $kb) {
                $kbData[$kb->id] = [
                    'kb_top_k' => $kb->pivot->kb_top_k,
                    'kb_similarity_threshold' => $kb->pivot->kb_similarity_threshold,
                ];
            }
            $newFlow->knowledgeBases()->sync($kbData);
        });

        return redirect()
            ->route('flows.show', $newFlow)
            ->with('success', 'Flow duplicated successfully');
    }

    /**
     * Authorize that the current user can access the flow's bot.
     */
    protected function authorizeFlow(Flow $flow): void
    {
        $flow->loadMissing('bot');
        $this->authorizeBot($flow->bot);
    }

    /**
     * Authorize that the current user owns or has access to the bot.
     */
    protected function authorizeBot(Bot $bot): void
    {
        $user = Auth::user();

        // Owner can access their own bots
        if ($user->isOwner() && $bot->user_id === $user->id) {
            return;
        }

        // Admin can access assigned bots
        if ($user->assignedBots()->where('bots.id', $bot->id)->exists()) {
            return;
        }

        abort(403, 'You do not have access to this bot');
    }

    /**
     * Cast boolean fields for PostgreSQL compatibility.
     */
    protected function castBooleanFields(array $data): array
    {
        $booleanFields = [
            'agentic_mode',
            'is_default',
            'hitl_enabled',
            'second_ai_enabled',
        ];

        foreach ($booleanFields as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $data;
    }
}
