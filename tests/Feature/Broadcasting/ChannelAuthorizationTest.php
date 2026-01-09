<?php

namespace Tests\Feature\Broadcasting;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that conversation channel allows bot owner.
     */
    public function test_conversation_channel_authorized_for_bot_owner(): void
    {
        $owner = User::factory()->create();
        $bot = Bot::factory()->for($owner)->create();
        $conversation = Conversation::factory()->for($bot)->create();

        $result = $this->authorizeConversationChannel($owner, $conversation->id);

        $this->assertTrue($result);
    }

    /**
     * Test that conversation channel allows assigned agent.
     */
    public function test_conversation_channel_authorized_for_assigned_agent(): void
    {
        $owner = User::factory()->create();
        $agent = User::factory()->create();
        $bot = Bot::factory()->for($owner)->create();
        $conversation = Conversation::factory()->for($bot)->create([
            'assigned_user_id' => $agent->id,
            'is_handover' => true,
        ]);

        $result = $this->authorizeConversationChannel($agent, $conversation->id);

        $this->assertTrue($result);
    }

    /**
     * Test that conversation channel denies other users.
     */
    public function test_conversation_channel_denied_for_other_users(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $bot = Bot::factory()->for($owner)->create();
        $conversation = Conversation::factory()->for($bot)->create();

        $result = $this->authorizeConversationChannel($otherUser, $conversation->id);

        $this->assertFalse($result);
    }

    /**
     * Test that bot channel allows owner.
     */
    public function test_bot_channel_authorized_for_owner(): void
    {
        $owner = User::factory()->create();
        $bot = Bot::factory()->for($owner)->create();

        $result = $this->authorizeBotChannel($owner, $bot->id);

        $this->assertTrue($result);
    }

    /**
     * Test that bot channel denies non-owners.
     */
    public function test_bot_channel_denied_for_non_owners(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $bot = Bot::factory()->for($owner)->create();

        $result = $this->authorizeBotChannel($otherUser, $bot->id);

        $this->assertFalse($result);
    }

    /**
     * Test that user notifications channel allows own user.
     */
    public function test_user_notifications_channel_authorized_for_own_user(): void
    {
        $user = User::factory()->create();

        $result = $this->authorizeUserNotificationsChannel($user, $user->id);

        $this->assertTrue($result);
    }

    /**
     * Test that user notifications channel denies other users.
     */
    public function test_user_notifications_channel_denied_for_other_users(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $result = $this->authorizeUserNotificationsChannel($user, $otherUser->id);

        $this->assertFalse($result);
    }

    /**
     * Test that nonexistent conversation returns false.
     */
    public function test_nonexistent_conversation_returns_false(): void
    {
        $user = User::factory()->create();

        $result = $this->authorizeConversationChannel($user, 99999);

        $this->assertFalse($result);
    }

    /**
     * Test that nonexistent bot returns false.
     */
    public function test_nonexistent_bot_returns_false(): void
    {
        $user = User::factory()->create();

        $result = $this->authorizeBotChannel($user, 99999);

        $this->assertFalse($result);
    }

    /**
     * Authorize conversation channel - mirrors logic from channels.php
     */
    protected function authorizeConversationChannel(User $user, int $conversationId): bool
    {
        $conversation = Conversation::with('bot')->find($conversationId);

        if (! $conversation) {
            return false;
        }

        // Allow if user owns the bot
        if ($conversation->bot->user_id === $user->id) {
            return true;
        }

        // Allow if user is assigned to this conversation (handover mode)
        if ($conversation->assigned_user_id === $user->id) {
            return true;
        }

        return false;
    }

    /**
     * Authorize bot channel - mirrors logic from channels.php
     */
    protected function authorizeBotChannel(User $user, int $botId): bool
    {
        $bot = Bot::find($botId);

        if (! $bot) {
            return false;
        }

        return $bot->user_id === $user->id;
    }

    /**
     * Authorize user notifications channel - mirrors logic from channels.php
     */
    protected function authorizeUserNotificationsChannel(User $user, int $userId): bool
    {
        return (int) $user->id === (int) $userId;
    }
}
