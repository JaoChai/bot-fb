<?php

namespace App\Services\Webhook\Steps;

use App\Models\Conversation;
use App\Models\CustomerProfile;
use App\Services\AutoAssignmentService;
use App\Services\LINEService;
use App\Services\ProfilePictureService;
use App\Services\TelegramService;
use App\Services\Webhook\WebhookContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolve conversation step — per-channel find-or-create (Task 9).
 *
 * The per-channel find-or-create logic below is a VERBATIM extraction of the
 * job methods (only `$this->bot` → `$ctx->bot` and the method bodies kept
 * byte-for-byte):
 *   - LINE:     ProcessLINEWebhook::createNewConversation + findOrCreateCustomerProfile
 *   - Facebook: ProcessFacebookWebhook::createNewConversation + findOrCreateCustomerProfile + fetchFacebookProfile
 *   - Telegram: ProcessTelegramWebhook::createNewConversation + findOrCreateCustomerProfile + fetchUserProfilePhoto
 *
 * The step sets $ctx->conversation, $ctx->profile, and
 * $ctx->metadata['is_new_conversation'] / $ctx->metadata['is_handover'].
 */
class ResolveConversationStep
{
    public function __construct(
        private ?LINEService $lineService = null,
        private ?TelegramService $telegramService = null,
    ) {
        $this->lineService ??= app(LINEService::class);
        $this->telegramService ??= app(TelegramService::class);
    }

    public function handle(WebhookContext $ctx, Closure $next): void
    {
        $conversation = match ($ctx->channelType) {
            'line' => $this->resolveLine($ctx),
            'facebook' => $this->resolveFacebook($ctx),
            'telegram' => $this->resolveTelegram($ctx),
            default => throw new \InvalidArgumentException("Unsupported channel type: {$ctx->channelType}"),
        };

        $ctx->conversation = $conversation;
        $ctx->metadata['is_handover'] = (bool) $conversation->is_handover;

        // Surface the resolved customer profile on the context (both new and
        // reused conversations carry a customer_profile_id).
        if ($ctx->profile === null && $conversation->customer_profile_id) {
            $ctx->profile = CustomerProfile::find($conversation->customer_profile_id);
        }

        $next($ctx);
    }

    /**
     * Resolve the LINE conversation: find-or-create.
     */
    private function resolveLine(WebhookContext $ctx): Conversation
    {
        $userId = $ctx->metadata['user_id'] ?? $ctx->userId();

        $existingConversation = Conversation::where('bot_id', $ctx->bot->id)
            ->where('external_customer_id', $userId)
            ->where('channel_type', 'line')
            ->whereIn('status', ['active', 'handover'])
            ->lockForUpdate()
            ->first();

        $isNew = ! $existingConversation;
        $conversation = $existingConversation ?? $this->createNewConversationLine($userId, $ctx);
        $ctx->metadata['is_new_conversation'] = $isNew;

        return $conversation;
    }

    /**
     * LINE createNewConversation (verbatim from ProcessLINEWebhook).
     */
    private function createNewConversationLine(string $userId, WebhookContext $ctx): Conversation
    {
        // Create or update customer profile
        $customerProfile = $this->findOrCreateCustomerProfileLine($userId, $ctx);

        // Check if bot has auto_handover enabled
        $autoHandover = $ctx->bot->auto_handover ?? false;

        // Create new conversation
        $conversation = Conversation::create([
            'bot_id' => $ctx->bot->id,
            'customer_profile_id' => $customerProfile?->id,
            'external_customer_id' => $userId,
            'channel_type' => 'line',
            'status' => $autoHandover ? 'handover' : 'active',
            'is_handover' => $autoHandover,
            'current_flow_id' => $ctx->bot->default_flow_id,
            'message_count' => 0,
        ]);

        // Auto-assign conversation if enabled
        $autoAssignment = app(AutoAssignmentService::class);
        $assignedUser = $autoAssignment->assignConversation($ctx->bot, $conversation);

        if ($assignedUser) {
            $conversation->update(['assigned_user_id' => $assignedUser->id]);
        }

        Log::info('New LINE conversation created', [
            'conversation_id' => $conversation->id,
            'user_id' => $userId,
        ]);

        return $conversation;
    }

    /**
     * LINE findOrCreateCustomerProfile (verbatim from ProcessLINEWebhook).
     */
    private function findOrCreateCustomerProfileLine(string $userId, WebhookContext $ctx): ?CustomerProfile
    {
        // Get LINE profile first (outside of DB operation)
        $lineProfile = $this->lineService->getProfile($ctx->bot, $userId);

        // Use updateOrCreate which generates ON CONFLICT DO UPDATE in PostgreSQL
        $profile = CustomerProfile::updateOrCreate(
            [
                'external_id' => $userId,
                'channel_type' => 'line',
            ],
            [
                'display_name' => $lineProfile['displayName'] ?? null,
                'picture_url' => app(ProfilePictureService::class)->downloadAndStore(
                    'line', $userId, $lineProfile['pictureUrl'] ?? null
                ),
                'last_interaction_at' => now(),
                'metadata' => [
                    'status_message' => $lineProfile['statusMessage'] ?? null,
                ],
            ]
        );

        // Set first_interaction_at only for new profiles
        if ($profile->wasRecentlyCreated) {
            $profile->update([
                'first_interaction_at' => now(),
                'interaction_count' => 1,
            ]);
        } else {
            // Increment interaction count for existing profiles
            $profile->increment('interaction_count');
        }

        return $profile;
    }

    /**
     * Resolve the Facebook conversation: find-or-create.
     */
    private function resolveFacebook(WebhookContext $ctx): Conversation
    {
        $senderId = $ctx->metadata['sender_id'];

        $existingConversation = Conversation::where('bot_id', $ctx->bot->id)
            ->where('external_customer_id', $senderId)
            ->where('channel_type', 'facebook')
            ->whereIn('status', ['active', 'handover'])
            ->first();

        $isNew = ! $existingConversation;
        $conversation = $existingConversation ?? $this->createNewConversationFacebook($senderId, $ctx);
        $ctx->metadata['is_new_conversation'] = $isNew;

        return $conversation;
    }

    /**
     * Facebook createNewConversation (verbatim from ProcessFacebookWebhook).
     */
    private function createNewConversationFacebook(string $psid, WebhookContext $ctx): Conversation
    {
        // Create or update customer profile
        $customerProfile = $this->findOrCreateCustomerProfileFacebook($psid, $ctx);

        // Check if bot has auto_handover enabled
        $autoHandover = $ctx->bot->auto_handover ?? false;

        // Create new conversation
        $conversation = Conversation::create([
            'bot_id' => $ctx->bot->id,
            'customer_profile_id' => $customerProfile?->id,
            'external_customer_id' => $psid,
            'channel_type' => 'facebook',
            'status' => $autoHandover ? 'handover' : 'active',
            'is_handover' => $autoHandover,
            'message_count' => 0,
        ]);

        // Auto-assign conversation if enabled
        $autoAssignment = app(AutoAssignmentService::class);
        $assignedUser = $autoAssignment->assignConversation($ctx->bot, $conversation);

        if ($assignedUser) {
            $conversation->update(['assigned_user_id' => $assignedUser->id]);
        }

        Log::info('New Facebook conversation created', [
            'conversation_id' => $conversation->id,
            'psid' => $psid,
        ]);

        return $conversation;
    }

    /**
     * Facebook findOrCreateCustomerProfile (verbatim from ProcessFacebookWebhook).
     */
    private function findOrCreateCustomerProfileFacebook(string $psid, WebhookContext $ctx): ?CustomerProfile
    {
        // Try to find existing profile
        $profile = CustomerProfile::where('external_id', $psid)
            ->where('channel_type', 'facebook')
            ->first();

        if ($profile) {
            // Update last interaction
            $profile->update([
                'last_interaction_at' => now(),
                'interaction_count' => DB::raw('interaction_count + 1'),
            ]);

            return $profile;
        }

        // Fetch profile from Facebook Graph API
        $fbProfile = $this->fetchFacebookProfile($psid, $ctx);

        // Create new profile with race condition handling
        try {
            return CustomerProfile::create([
                'external_id' => $psid,
                'channel_type' => 'facebook',
                'display_name' => $fbProfile['name'] ?? 'Facebook User',
                'picture_url' => app(ProfilePictureService::class)->downloadAndStore(
                    'facebook', $psid, $fbProfile['profile_pic'] ?? null
                ),
                'first_interaction_at' => now(),
                'last_interaction_at' => now(),
                'interaction_count' => 1,
                'metadata' => [
                    'first_name' => $fbProfile['first_name'] ?? null,
                    'last_name' => $fbProfile['last_name'] ?? null,
                ],
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Race condition: another job created the profile, query again
            return CustomerProfile::where('external_id', $psid)
                ->where('channel_type', 'facebook')
                ->first();
        }
    }

    /**
     * Facebook fetchFacebookProfile (verbatim from ProcessFacebookWebhook).
     */
    private function fetchFacebookProfile(string $psid, WebhookContext $ctx): array
    {
        try {
            $accessToken = $ctx->bot->channel_access_token;

            if (! $accessToken) {
                Log::warning('No Facebook access token configured', [
                    'bot_id' => $ctx->bot->id,
                ]);

                return [];
            }

            $response = Http::get("https://graph.facebook.com/v19.0/{$psid}", [
                'fields' => 'first_name,last_name,name,profile_pic',
                'access_token' => $accessToken,
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Failed to fetch Facebook profile', [
                'bot_id' => $ctx->bot->id,
                'psid' => $psid,
                'status' => $response->status(),
                'error' => $response->json()['error'] ?? null,
            ]);

            return [];

        } catch (\Exception $e) {
            Log::warning('Exception fetching Facebook profile', [
                'bot_id' => $ctx->bot->id,
                'psid' => $psid,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Resolve the Telegram conversation: find-or-create.
     */
    private function resolveTelegram(WebhookContext $ctx): Conversation
    {
        $chatId = $ctx->metadata['chat_id'];

        $existingConversation = Conversation::where('bot_id', $ctx->bot->id)
            ->where('external_customer_id', $chatId)
            ->where('channel_type', 'telegram')
            ->whereIn('status', ['active', 'handover'])
            ->first();

        $isNew = ! $existingConversation;
        $conversation = $existingConversation ?? $this->createNewConversationTelegram($chatId, $ctx);
        $ctx->metadata['is_new_conversation'] = $isNew;

        return $conversation;
    }

    /**
     * Telegram createNewConversation (verbatim from ProcessTelegramWebhook).
     */
    private function createNewConversationTelegram(string $chatId, WebhookContext $ctx): Conversation
    {
        // Create or update customer profile
        $customerProfile = $this->findOrCreateCustomerProfileTelegram($ctx);

        // Check if bot has auto_handover enabled
        $autoHandover = $ctx->bot->auto_handover ?? false;

        // Create new conversation
        $conversation = Conversation::create([
            'bot_id' => $ctx->bot->id,
            'customer_profile_id' => $customerProfile?->id,
            'external_customer_id' => $chatId,
            'channel_type' => 'telegram',
            'status' => $autoHandover ? 'handover' : 'active',
            'is_handover' => $autoHandover,
            'telegram_chat_type' => $ctx->metadata['chat_type'],
            'telegram_chat_title' => $ctx->metadata['chat_title'],
            'message_count' => 0,
        ]);

        // Auto-assign conversation if enabled
        $autoAssignment = app(AutoAssignmentService::class);
        $assignedUser = $autoAssignment->assignConversation($ctx->bot, $conversation);

        if ($assignedUser) {
            $conversation->update(['assigned_user_id' => $assignedUser->id]);
        }

        Log::info('New Telegram conversation created', [
            'conversation_id' => $conversation->id,
            'chat_id' => $chatId,
            'chat_type' => $ctx->metadata['chat_type'],
        ]);

        return $conversation;
    }

    /**
     * Telegram findOrCreateCustomerProfile (verbatim from ProcessTelegramWebhook).
     */
    private function findOrCreateCustomerProfileTelegram(WebhookContext $ctx): ?CustomerProfile
    {
        // For group chats, use chat_id; for private chats, use user_id
        $externalId = $ctx->metadata['chat_type'] === 'private'
            ? $ctx->metadata['user_id']
            : $ctx->metadata['chat_id'];

        // Try to find existing profile
        $profile = CustomerProfile::where('external_id', $externalId)
            ->where('channel_type', 'telegram')
            ->first();

        if ($profile) {
            // Update last interaction
            $profile->update([
                'last_interaction_at' => now(),
                'interaction_count' => DB::raw('interaction_count + 1'),
            ]);

            return $profile;
        }

        // Determine display name
        $displayName = $ctx->metadata['chat_type'] === 'private'
            ? trim(($ctx->metadata['first_name'] ?? '').' '.($ctx->metadata['last_name'] ?? ''))
            : $ctx->metadata['chat_title'];

        if (! $displayName) {
            $displayName = $ctx->metadata['username'] ?? null;
        }

        // Create new profile
        return CustomerProfile::create([
            'external_id' => $externalId,
            'channel_type' => 'telegram',
            'display_name' => $displayName ?: 'Telegram User',
            'picture_url' => $this->fetchUserProfilePhoto($ctx),
            'first_interaction_at' => now(),
            'last_interaction_at' => now(),
            'interaction_count' => 1,
            'metadata' => [
                'username' => $ctx->metadata['username'],
                'user_id' => $ctx->metadata['user_id'],
                'chat_type' => $ctx->metadata['chat_type'],
                'chat_title' => $ctx->metadata['chat_title'],
            ],
        ]);
    }

    /**
     * Telegram fetchUserProfilePhoto (verbatim from ProcessTelegramWebhook).
     */
    private function fetchUserProfilePhoto(WebhookContext $ctx): ?string
    {
        // Only fetch for private chats (individual users)
        if ($ctx->metadata['chat_type'] !== 'private') {
            return null;
        }

        $userId = $ctx->metadata['user_id'] ?? null;
        if (! $userId) {
            return null;
        }

        return $this->telegramService->getUserProfilePhoto($ctx->bot, $userId);
    }
}
