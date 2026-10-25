<?php

namespace Tests\Feature\Contracts;

use App\Events\ConversationUpdated;
use App\Events\MessageSent;
use App\Http\Resources\MessageResource;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Auth + chat data contract tests.
 *
 * Validates that the real HTTP responses for the `login`, `listConversations`
 * and `listConversationMessages` operations, and the real MessageSent /
 * ConversationUpdated broadcast payloads, match the committed contract in
 * contracts/openapi.yaml (OpenAPI 3.1).
 *
 * If these tests fail, either the backend changed its observable behavior
 * (update the contract deliberately, in review) or the contract drifted from
 * what the frontend consumes.
 */
class AuthChatContractTest extends TestCase
{
    use RefreshDatabase;

    // contracts/openapi.yaml lives at the REPO ROOT (one level above backend/).
    private const CONTRACT_PATH = '../contracts/openapi.yaml';

    private array $contract;

    protected function setUp(): void
    {
        parent::setUp();

        $path = base_path(self::CONTRACT_PATH);

        if (! is_file($path)) {
            $this->fail(sprintf(
                'Contract file missing: %s. These tests validate live API and broadcast '
                .'payloads against the committed OpenAPI contract; the file must exist.',
                $path
            ));
        }

        $this->contract = Yaml::parseFile($path);
    }

    // ------------------------------------------------------------------
    // login
    // ------------------------------------------------------------------

    public function test_login_success_response_matches_contract(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-password')]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
            'device_name' => 'contract-test',
        ]);

        $response->assertOk();
        $this->assertValidAgainstContract($response->json(), 'LoginResponse', 'login 200');
        $this->assertSame('Bearer', $response->json('token_type'));
    }

    public function test_login_wrapped_envelope_fails_validation(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-password')]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'secret-password',
        ]);

        $response->assertOk();
        $payload = $response->json();

        // A legacy/alternative `{data: {user, token}}` envelope must NOT validate:
        // the contract pins the flat `{message, user, token, token_type}` shape.
        $this->assertInvalidAgainstContract(
            ['data' => $payload],
            'LoginResponse',
            'login response wrapped in a data envelope'
        );

        // The same flat payload with the token removed must also fail.
        unset($payload['token']);
        $this->assertInvalidAgainstContract($payload, 'LoginResponse', 'login response without token');
    }

    public function test_login_validation_error_matches_contract(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'not-an-email',
            'password' => '',
        ]);

        $response->assertUnprocessable();
        $this->assertArrayHasKey('errors', $response->json());
        $this->assertValidAgainstContract($response->json(), 'ValidationError', 'login 422');
    }

    public function test_login_rate_limit_response_matches_contract(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-password')]);

        $attempt = fn () => $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        // throttle.auth: 5 attempts per minute per email|ip — attempt 6 gets the 429.
        for ($i = 0; $i < 5; $i++) {
            $attempt()->assertUnprocessable();
        }

        $response = $attempt();

        $response->assertTooManyRequests();
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertValidAgainstContract($response->json(), 'RateLimitError', 'login 429');
    }

    public function test_login_unknown_email_returns_422_and_contract_documents_no_404(): void
    {
        // Real behavior: an unknown but well-formed email raises a
        // ValidationException (AuthController::login), so the response is a
        // 422 ValidationError — there is no 404 on this operation.
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@unknown.test',
            'password' => 'whatever-password',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['email']);
        $this->assertValidAgainstContract($response->json(), 'ValidationError', 'login unknown email 422');

        $responses = $this->contract['paths']['/api/auth/login']['post']['responses'];
        $this->assertArrayNotHasKey(
            '404',
            $responses,
            'login must not document a 404 — an unknown email is answered as a 422 validation error.'
        );
        $this->assertArrayHasKey('422', $responses);
        $this->assertStringContainsStringIgnoringCase(
            'unknown email',
            (string) $responses['422']['description'],
            'The login 422 description must mention the unknown-email case.'
        );
    }

    // ------------------------------------------------------------------
    // listConversations
    // ------------------------------------------------------------------

    public function test_list_conversations_response_matches_contract(): void
    {
        $owner = User::factory()->owner()->create();
        $bot = Bot::factory()->for($owner)->create();
        Conversation::factory()->count(2)->for($bot)->create();

        $response = $this->actingAs($owner)
            ->getJson("/api/bots/{$bot->id}/conversations?per_page=2&page=1");

        $response->assertOk();
        $body = $response->json();

        $this->assertValidAgainstContract($body, 'ConversationListResponse', 'listConversations 200');
        $this->assertCount(2, $body['data']);
        $this->assertSame(1, $body['meta']['current_page']);
        $this->assertSame(2, $body['meta']['total']);
        $this->assertArrayHasKey('status_counts', $body['meta']);
    }

    public function test_list_conversations_refused_for_other_owner_with_no_data(): void
    {
        $owner = User::factory()->owner()->create();
        $otherOwner = User::factory()->owner()->create();
        $bot = Bot::factory()->for($owner)->create();
        Conversation::factory()->count(2)->for($bot)->create();

        $response = $this->actingAs($otherOwner)
            ->getJson("/api/bots/{$bot->id}/conversations");

        $response->assertForbidden();
        $body = $response->json();
        $this->assertValidAgainstContract($body, 'ForbiddenError', 'listConversations 403');
        $this->assertArrayNotHasKey('data', $body, 'A 403 refusal must not carry any conversation data.');
    }

    public function test_list_conversations_unauthenticated_matches_401_contract(): void
    {
        $owner = User::factory()->owner()->create();
        $bot = Bot::factory()->for($owner)->create();

        $response = $this->getJson("/api/bots/{$bot->id}/conversations");

        $response->assertUnauthorized();
        $this->assertSame('Unauthenticated.', $response->json('message'));
        $this->assertValidAgainstContract($response->json(), 'UnauthorizedError', 'listConversations 401');
    }

    // ------------------------------------------------------------------
    // listConversationMessages
    // ------------------------------------------------------------------

    public function test_list_conversation_messages_response_matches_contract(): void
    {
        $owner = User::factory()->owner()->create();
        $bot = Bot::factory()->for($owner)->create();
        $conversation = Conversation::factory()->for($bot)->create();
        Message::factory()->count(3)->for($conversation)->create([
            'sender' => 'user',
            'type' => 'text',
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/bots/{$bot->id}/conversations/{$conversation->id}/messages?per_page=2&page=2&order=asc");

        $response->assertOk();
        $body = $response->json();

        $this->assertValidAgainstContract($body, 'MessageListResponse', 'listConversationMessages 200');
        // Offset/page pagination (page 2 of 2 with per_page=2 holds only the last message).
        $this->assertCount(1, $body['data']);
        $this->assertSame(2, $body['meta']['current_page']);
        $this->assertSame(3, $body['meta']['total']);
        $this->assertSame(2, $body['meta']['per_page']);
    }

    public function test_list_conversation_messages_refused_for_other_owner_with_no_data(): void
    {
        $owner = User::factory()->owner()->create();
        $otherOwner = User::factory()->owner()->create();
        $bot = Bot::factory()->for($owner)->create();
        $conversation = Conversation::factory()->for($bot)->create();
        Message::factory()->for($conversation)->create([
            'sender' => 'user',
            'content' => 'customer-secret-message',
            'type' => 'text',
        ]);

        $response = $this->actingAs($otherOwner)
            ->getJson("/api/bots/{$bot->id}/conversations/{$conversation->id}/messages");

        $response->assertForbidden();
        $body = $response->json();
        $this->assertValidAgainstContract($body, 'ForbiddenError', 'listConversationMessages 403');
        $this->assertArrayNotHasKey('data', $body, 'A 403 refusal must not carry any message data.');
        $this->assertStringNotContainsString('customer-secret-message', $response->getContent());
    }

    // ------------------------------------------------------------------
    // Message type: postback (Facebook postbacks are persisted as messages)
    // ------------------------------------------------------------------

    /**
     * Build an unsaved postback Message. The sqlite test schema's enum check
     * has no `postback` (the 2026-08-26 migration only alters pg/mysql), so
     * the row is factory-made, never inserted — prod writes it via
     * PersistUserMessageStep / ProcessFacebookWebhook.
     */
    private function makePostbackMessage(Conversation $conversation): Message
    {
        $message = Message::factory()->for($conversation)->make([
            'sender' => 'user',
            'content' => 'ซื้อแพ็กเกจโปร',
            'type' => 'postback',
        ]);
        $message->id = 999999;
        $message->created_at = now();
        $message->updated_at = now();

        return $message;
    }

    public function test_postback_message_resource_matches_contract(): void
    {
        $owner = User::factory()->owner()->create();
        $bot = Bot::factory()->for($owner)->create();
        $conversation = Conversation::factory()->for($bot)->create();

        $payload = MessageResource::make($this->makePostbackMessage($conversation))->resolve();

        $this->assertSame('postback', $payload['type']);
        $this->assertValidAgainstContract($payload, 'Message', 'postback message via MessageResource');
    }

    public function test_message_sent_postback_payload_matches_contract(): void
    {
        $owner = User::factory()->owner()->create();
        $bot = Bot::factory()->for($owner)->create();
        $conversation = Conversation::factory()->for($bot)->create(['unread_count' => 1]);

        $event = new MessageSent($this->makePostbackMessage($conversation));

        $this->assertSame('postback', $event->broadcastWith()['type']);
        $this->assertValidAgainstContract($event->broadcastWith(), 'MessageSentPayload', 'postback MessageSent payload');
    }

    // ------------------------------------------------------------------
    // Broadcast channel authorization
    // ------------------------------------------------------------------

    public function test_broadcast_channel_refuses_other_owner_and_allows_bot_owner(): void
    {
        $owner = User::factory()->owner()->create();
        $otherOwner = User::factory()->owner()->create();
        $bot = Bot::factory()->for($owner)->create();

        // The null broadcast connection used in tests skips channel callbacks
        // entirely (auth is a no-op). Use the real reverb broadcaster and
        // re-require the production channel registration file, because at
        // boot time the channels were registered on the null broadcaster.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'contract-test-key',
            'broadcasting.connections.reverb.secret' => 'contract-test-secret',
            'broadcasting.connections.reverb.app_id' => 'contract-test-app',
        ]);
        require base_path('routes/channels.php');

        $denied = $this->actingAs($otherOwner)->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-bot.{$bot->id}",
        ]);

        $denied->assertForbidden();
        $this->assertNull($denied->json('auth'), 'Channel auth refusal must not contain a signature.');

        $allowed = $this->actingAs($owner)->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-bot.{$bot->id}",
        ]);

        $allowed->assertOk();
        // Pusher protocol auth response is "<app_key>:<hmac signature>".
        $this->assertStringStartsWith('contract-test-key:', (string) $allowed->json('auth'));
    }

    // ------------------------------------------------------------------
    // MessageSent broadcast
    // ------------------------------------------------------------------

    public function test_message_sent_broadcast_payload_matches_contract(): void
    {
        $owner = User::factory()->owner()->create();
        $bot = Bot::factory()->for($owner)->create();
        $conversation = Conversation::factory()->for($bot)->create([
            'unread_count' => 3,
            'message_count' => 5,
        ]);
        $message = Message::factory()->for($conversation)->create([
            'sender' => 'user',
            'content' => 'Hello, world!',
            'type' => 'text',
        ]);

        $event = new MessageSent($message);

        $this->assertValidAgainstContract($event->broadcastWith(), 'MessageSentPayload', 'MessageSent broadcastWith');
        $this->assertBroadcastingMatchesXBroadcast($event, 'MessageSent', $bot->id);
    }

    public function test_message_sent_payload_missing_unread_count_fails_validation(): void
    {
        $owner = User::factory()->owner()->create();
        $bot = Bot::factory()->for($owner)->create();
        $conversation = Conversation::factory()->for($bot)->create(['unread_count' => 3]);
        $message = Message::factory()->for($conversation)->create([
            'sender' => 'user',
            'content' => 'Hello, world!',
            'type' => 'text',
        ]);

        $payload = (new MessageSent($message))->broadcastWith();
        unset($payload['conversation']['unread_count']);

        $this->assertInvalidAgainstContract(
            $payload,
            'MessageSentPayload',
            'MessageSent payload without conversation.unread_count'
        );
    }

    // ------------------------------------------------------------------
    // ConversationUpdated broadcast
    // ------------------------------------------------------------------

    public function test_conversation_updated_broadcast_payload_matches_contract(): void
    {
        $owner = User::factory()->owner()->create();
        $bot = Bot::factory()->for($owner)->create();
        $conversation = Conversation::factory()->for($bot)->create([
            'unread_count' => 2,
            'message_count' => 7,
        ]);

        $event = new ConversationUpdated($conversation);

        $this->assertValidAgainstContract($event->broadcastWith(), 'ConversationUpdatedPayload', 'ConversationUpdated broadcastWith');
        $this->assertBroadcastingMatchesXBroadcast($event, 'ConversationUpdated', $bot->id);

        $messageReceived = new ConversationUpdated($conversation, 'message_received');
        $this->assertSame('conversation.message_received', $messageReceived->broadcastAs());
        $this->assertValidAgainstContract(
            $messageReceived->broadcastWith(),
            'ConversationUpdatedPayload',
            'ConversationUpdated (message_received) broadcastWith'
        );
    }

    public function test_conversation_updated_payload_missing_unread_count_fails_validation(): void
    {
        $owner = User::factory()->owner()->create();
        $bot = Bot::factory()->for($owner)->create();
        $conversation = Conversation::factory()->for($bot)->create(['unread_count' => 2]);

        $payload = (new ConversationUpdated($conversation))->broadcastWith();
        unset($payload['unread_count']);

        $this->assertInvalidAgainstContract(
            $payload,
            'ConversationUpdatedPayload',
            'ConversationUpdated payload without unread_count'
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Assert an event's channel and broadcast name match the `x-broadcast`
     * map committed in contracts/openapi.yaml.
     *
     * @param  MessageSent|ConversationUpdated  $event
     */
    private function assertBroadcastingMatchesXBroadcast(object $event, string $eventName, int $botId): void
    {
        $map = $this->contract['x-broadcast'][$eventName] ?? null;
        $this->assertIsArray($map, "x-broadcast map is missing the '{$eventName}' entry.");

        $this->assertSame(
            str_replace('{update_type}', $this->resolveUpdateType($event), $map['event']),
            $event->broadcastAs(),
            "{$eventName}::broadcastAs() does not match the x-broadcast map."
        );

        $channelNames = array_map(fn ($channel) => $channel->name, $event->broadcastOn());
        $this->assertContains(
            str_replace('{bot_id}', (string) $botId, $map['channel']),
            $channelNames,
            "{$eventName} is not broadcast on the private channel declared in the x-broadcast map."
        );
    }

    /**
     * @param  MessageSent|ConversationUpdated  $event
     */
    private function resolveUpdateType(object $event): string
    {
        return $event instanceof ConversationUpdated ? $event->updateType : '';
    }

    /**
     * Validate a decoded payload against a named schema inside the contract's
     * components.schemas, using a JSON Schema draft 2020-12 validator
     * (OpenAPI 3.1 Schema Objects are JSON Schema 2020-12).
     *
     * @return string[] list of validation error messages; empty = valid
     */
    private function validateAgainst(array $data, string $schemaName): array
    {
        $wrapper = [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'urn:botjao:auth-chat-contract:tests',
            'components' => [
                'schemas' => $this->contract['components']['schemas'] ?? null,
            ],
            'allOf' => [
                ['$ref' => '#/components/schemas/'.$schemaName],
            ],
        ];

        $validator = new Validator;
        $result = $validator->validate(
            json_decode(json_encode($data), false),
            json_decode(json_encode($wrapper), false)
        );

        if ($result->isValid()) {
            return [];
        }

        return array_map(
            fn ($error) => is_string($error) ? $error : (string) $error,
            (new ErrorFormatter)->formatFlat($result->error())
        );
    }

    private function assertValidAgainstContract(array $data, string $schemaName, string $context): void
    {
        $errors = $this->validateAgainst($data, $schemaName);

        $this->assertSame([], $errors, sprintf(
            "%s: payload does not match contracts/openapi.yaml #/components/schemas/%s:\n%s",
            $context,
            $schemaName,
            implode("\n", $errors)
        ));
    }

    private function assertInvalidAgainstContract(array $data, string $schemaName, string $context): void
    {
        $errors = $this->validateAgainst($data, $schemaName);

        $this->assertNotSame([], $errors, sprintf(
            '%s: payload unexpectedly matched #/components/schemas/%s — the contract is not strict enough.',
            $context,
            $schemaName
        ));
    }
}
