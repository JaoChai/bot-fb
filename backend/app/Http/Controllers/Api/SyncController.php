<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

        // F9: คงรูป envelope/ฟิลด์แชทเดิมทุกอย่าง แต่ "last_message" ต้องเป็น
        // รูป resource (มี is_outage_notice) ไม่ใช่ JSON ดิบของโมเดล (มี metadata)
        $data = $query->get()->map(function (Conversation $conversation) {
            $array = $conversation->toArray();
            $array['last_message'] = $conversation->lastMessage !== null
                ? MessageResource::make($conversation->lastMessage)->resolve()
                : null;

            return $array;
        });

        return response()->json([
            'data' => $data,
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

        // F9: ฉายผ่าน resource — since_id/ลำดับ/limit/has_more/synced_at คงเดิมทั้งหมด
        /** @var Collection<int, array<string, mixed>> $projected */
        $projected = $messages
            ->map(fn ($message) => MessageResource::make($message)->resolve())
            ->values();

        return response()->json([
            'data' => $projected,
            'has_more' => $hasMore,
            'synced_at' => now()->toISOString(),
        ]);
    }
}
