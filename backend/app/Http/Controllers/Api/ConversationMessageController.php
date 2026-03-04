<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Bot;
use App\Models\Conversation;
use App\Services\Chat\MessageService;
use App\Services\ConversationCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationMessageController extends BaseConversationController
{
    public function __construct(
        ConversationCacheService $cacheService,
        private MessageService $messageService
    ) {
        parent::__construct($cacheService);
    }

    /**
     * Get messages for a conversation with pagination.
     * Enforces max 100 messages per page to prevent memory issues.
     */
    public function index(Request $request, Bot $bot, Conversation $conversation): AnonymousResourceCollection
    {
        $this->authorize('view', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $messages = $this->messageService->getMessages(
            $conversation,
            (int) $request->input('per_page', 50),
            $request->input('order', 'desc')
        );

        return MessageResource::collection($messages);
    }

    /**
     * Send a message from agent to customer (HITL).
     */
    public function store(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:5000', function ($attribute, $value, $fail) {
                // LINE API has 5000 byte limit, not character limit
                if (strlen($value) > 5000) {
                    $fail('The message is too long (max 5000 bytes for LINE).');
                }
            }],
            'type' => 'sometimes|in:text,image,file,photo,video,document,voice',
            'media_url' => 'sometimes|url|max:2048',
        ]);

        try {
            $result = $this->messageService->sendAgentMessage($bot, $conversation, $validated);

            return response()->json([
                'message' => 'Message sent successfully',
                'data' => new MessageResource($result['message']),
                'delivery_error' => $result['delivery_error'],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Mark conversation as read (reset unread count).
     */
    public function markAsRead(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $conversation = $this->messageService->markAsRead($conversation);

        return response()->json([
            'message' => 'Conversation marked as read',
            'data' => new ConversationResource($conversation),
        ]);
    }

    /**
     * Upload media file for agent messages.
     * Stores the file and returns the public URL.
     */
    public function upload(Request $request, Bot $bot, Conversation $conversation): JsonResponse
    {
        $this->authorize('update', $bot);
        $this->validateConversationBelongsToBot($conversation, $bot);

        $request->validate([
            'file' => 'required|file|max:20480|mimes:jpg,jpeg,png,gif,webp,mp4,mov,avi,webm,mp3,m4a,wav,ogg',
        ]);

        $file = $request->file('file');
        $mimeType = $file->getMimeType();

        // Determine type from mime
        $type = 'file';
        if (str_starts_with($mimeType, 'image/')) {
            $type = 'image';
        } elseif (str_starts_with($mimeType, 'video/')) {
            $type = 'video';
        } elseif (str_starts_with($mimeType, 'audio/')) {
            $type = 'audio';
        }

        // Generate storage path
        $extension = $file->getClientOriginalExtension();
        $storagePath = 'chat/'.$bot->id.'/'.date('Y/m/d').'/'.uniqid().'.'.$extension;

        // Store file
        $disk = config('filesystems.default');
        $file->storeAs(dirname($storagePath), basename($storagePath), $disk);

        // Generate URL
        $url = $this->generateStorageUrl($disk, $storagePath);

        return response()->json([
            'url' => $url,
            'type' => $type,
            'mime_type' => $mimeType,
            'size' => $file->getSize(),
            'filename' => $file->getClientOriginalName(),
        ]);
    }

    /**
     * Generate storage URL - use R2_URL directly if R2 disk.
     */
    private function generateStorageUrl(string $disk, string $path): string
    {
        if ($disk === 'r2') {
            $r2Url = env('R2_URL') ?: config('filesystems.disks.r2.url');
            if ($r2Url) {
                return rtrim($r2Url, '/').'/'.$path;
            }
        }

        return \Illuminate\Support\Facades\Storage::disk($disk)->url($path);
    }
}
