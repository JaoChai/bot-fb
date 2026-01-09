<?php

namespace App\Http\Controllers\Bot;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class BotController extends Controller
{
    /**
     * Display a listing of the user's bots.
     */
    public function index(Request $request): Response
    {
        $user = Auth::user();

        $bots = Bot::forUser($user)
            ->with(['flow:id,bot_id,name'])
            ->withCount(['conversations', 'messages'])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return Inertia::render('Bots/Index', [
            'bots' => $bots,
            'filters' => $request->only(['search', 'status', 'channel_type']),
        ]);
    }

    /**
     * Display the bot settings/details page.
     */
    public function show(Bot $bot): Response
    {
        $this->authorize('view', $bot);

        $bot->load([
            'flow:id,bot_id,name,is_active',
            'knowledgeBases:id,bot_id,name,document_count',
        ]);

        $bot->loadCount(['conversations', 'messages', 'customers']);

        // Generate webhook URL
        $webhookUrl = $this->generateWebhookUrl($bot);

        return Inertia::render('Bots/Settings', [
            'bot' => $bot,
            'webhookUrl' => $webhookUrl,
            'stats' => [
                'conversations_count' => $bot->conversations_count,
                'messages_count' => $bot->messages_count,
                'customers_count' => $bot->customers_count,
            ],
        ]);
    }

    /**
     * Show the form for editing the bot.
     */
    public function edit(Bot $bot): Response
    {
        $this->authorize('update', $bot);

        return Inertia::render('Bots/Edit', [
            'bot' => $bot,
        ]);
    }

    /**
     * Update the bot.
     */
    public function update(Request $request, Bot $bot): RedirectResponse
    {
        $this->authorize('update', $bot);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'channel_type' => ['required', 'string', 'in:line,telegram,facebook'],
            'status' => ['required', 'string', 'in:active,inactive,paused'],
            'settings' => ['nullable', 'array'],
        ]);

        $bot->update($validated);

        return redirect()
            ->route('bots.show', $bot)
            ->with('success', 'บันทึกการตั้งค่าบอทเรียบร้อยแล้ว');
    }

    /**
     * Remove the bot.
     */
    public function destroy(Bot $bot): RedirectResponse
    {
        $this->authorize('delete', $bot);

        $bot->delete();

        return redirect()
            ->route('bots.index')
            ->with('success', 'ลบบอทเรียบร้อยแล้ว');
    }

    /**
     * Generate webhook URL for the bot.
     */
    protected function generateWebhookUrl(Bot $bot): string
    {
        $baseUrl = config('app.url');

        return match ($bot->channel_type) {
            'line' => "{$baseUrl}/api/webhook/{$bot->webhook_token}",
            'telegram' => "{$baseUrl}/api/webhook/telegram/{$bot->webhook_token}",
            'facebook' => "{$baseUrl}/api/webhook/facebook/{$bot->webhook_token}",
            default => '',
        };
    }
}
