<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SyncController extends Controller
{
    public function conversations(Request $request, Bot $bot): JsonResponse
    {
        $this->authorize('view', $bot);

        $request->validate(['since' => 'sometimes|date']);
        $since = $request->query('since');

        $query = $bot->conversations()
            ->with(['customerProfile', 'lastMessage'])
            ->orderBy('updated_at', 'desc')
            ->limit(50);

        if ($since) {
            $sinceDate = Carbon::parse($since)->setTimezone(config('app.timezone'));
            $query->where('updated_at', '>', $sinceDate);
        }

        return response()->json([
            'data' => $query->get(),
            'synced_at' => now()->toISOString(),
        ]);
    }

    public function messages(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $bot);
        abort_if($conversation->bot_id !== $bot->id, 403);

        $sinceId = $request->integer('since_id', 0);

        $messages = $conversation->messages()
            ->when($sinceId > 0, fn ($q) => $q->where('id', '>', $sinceId))
            ->orderBy('id', 'asc')
            ->limit(200)
            ->get();

        $hasMore = $messages->count() === 200;

        return response()->json([
            'data' => $messages,
            'has_more' => $hasMore,
            'synced_at' => now()->toISOString(),
        ]);
    }
}
