<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bot\StoreBotRequest;
use App\Http\Requests\Bot\UpdateBotRequest;
use App\Http\Resources\BotResource;
use App\Models\Bot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class BotController extends Controller
{
    /**
     * List all bots for the authenticated user.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $bots = $request->user()
            ->bots()
            ->latest()
            ->paginate($request->input('per_page', 15));

        return BotResource::collection($bots);
    }

    /**
     * Create a new bot.
     */
    public function store(StoreBotRequest $request): JsonResponse
    {
        $bot = $request->user()->bots()->create([
            ...$request->validated(),
            'webhook_url' => $this->generateWebhookUrl(),
            'status' => 'inactive',
        ]);

        return response()->json([
            'message' => 'Bot created successfully',
            'data' => new BotResource($bot),
        ], 201);
    }

    /**
     * Get a specific bot.
     */
    public function show(Request $request, Bot $bot): BotResource
    {
        $this->authorize('view', $bot);

        return new BotResource($bot->load(['settings', 'defaultFlow']));
    }

    /**
     * Update a bot.
     */
    public function update(UpdateBotRequest $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        $bot->update($request->validated());

        return response()->json([
            'message' => 'Bot updated successfully',
            'data' => new BotResource($bot->fresh()),
        ]);
    }

    /**
     * Delete a bot.
     */
    public function destroy(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('delete', $bot);

        $bot->delete();

        return response()->json([
            'message' => 'Bot deleted successfully',
        ]);
    }

    /**
     * Get the webhook URL for a bot.
     */
    public function webhookUrl(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        return response()->json([
            'webhook_url' => $bot->webhook_url,
            'channel_type' => $bot->channel_type,
        ]);
    }

    /**
     * Test bot with a sample message.
     */
    public function test(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        // TODO: Implement actual bot testing with AI response
        // For now, return a placeholder response
        return response()->json([
            'message' => 'Test message received',
            'input' => $request->input('message'),
            'response' => 'This is a placeholder response. AI integration coming soon.',
            'bot_id' => $bot->id,
        ]);
    }

    /**
     * Regenerate webhook URL for a bot.
     */
    public function regenerateWebhook(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('update', $bot);

        $bot->update([
            'webhook_url' => $this->generateWebhookUrl(),
        ]);

        return response()->json([
            'message' => 'Webhook URL regenerated successfully',
            'webhook_url' => $bot->webhook_url,
        ]);
    }

    /**
     * Generate a unique webhook URL.
     */
    private function generateWebhookUrl(): string
    {
        $token = Str::random(32);
        return config('app.url') . '/webhook/' . $token;
    }
}
