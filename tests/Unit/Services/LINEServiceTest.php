<?php

namespace Tests\Unit\Services;

use App\Exceptions\LINEException;
use App\Models\Bot;
use App\Services\LINEService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LINEServiceTest extends TestCase
{
    protected LINEService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LINEService();
    }

    /**
     * Create a bot instance without persisting to database.
     */
    protected function createBotInstance(array $attributes = []): Bot
    {
        $bot = new Bot(array_merge([
            'id' => 1,
            'user_id' => 1,
            'name' => 'Test Bot',
            'channel_type' => 'line',
            'channel_access_token' => 'test_access_token',
            'channel_secret' => 'test_channel_secret',
        ], $attributes));

        return $bot;
    }

    public function test_validates_correct_signature(): void
    {
        $body = '{"events":[]}';
        $secret = 'test_channel_secret';
        $signature = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $result = $this->service->validateSignature($body, $signature, $secret);

        $this->assertTrue($result);
    }

    public function test_rejects_invalid_signature(): void
    {
        $this->expectException(LINEException::class);
        $this->expectExceptionMessage('Invalid LINE webhook signature');

        $body = '{"events":[]}';
        $secret = 'test_channel_secret';
        $wrongSignature = base64_encode(hash_hmac('sha256', 'wrong_body', $secret, true));

        $this->service->validateSignature($body, $wrongSignature, $secret);
    }

    public function test_parses_events_from_body(): void
    {
        $body = [
            'destination' => 'U1234567890',
            'events' => [
                ['type' => 'message', 'replyToken' => 'token123'],
                ['type' => 'follow', 'replyToken' => 'token456'],
            ],
        ];

        $events = $this->service->parseEvents($body);

        $this->assertCount(2, $events);
        $this->assertEquals('message', $events[0]['type']);
        $this->assertEquals('follow', $events[1]['type']);
    }

    public function test_parses_empty_events(): void
    {
        $body = ['destination' => 'U1234567890'];

        $events = $this->service->parseEvents($body);

        $this->assertEmpty($events);
    }

    public function test_extracts_user_id(): void
    {
        $event = [
            'type' => 'message',
            'source' => [
                'type' => 'user',
                'userId' => 'U1234567890abcdef',
            ],
        ];

        $userId = $this->service->extractUserId($event);

        $this->assertEquals('U1234567890abcdef', $userId);
    }

    public function test_extracts_user_id_returns_null_when_missing(): void
    {
        $event = [
            'type' => 'message',
            'source' => ['type' => 'group'],
        ];

        $userId = $this->service->extractUserId($event);

        $this->assertNull($userId);
    }

    public function test_extracts_reply_token(): void
    {
        $event = [
            'type' => 'message',
            'replyToken' => 'reply_token_123',
        ];

        $replyToken = $this->service->extractReplyToken($event);

        $this->assertEquals('reply_token_123', $replyToken);
    }

    public function test_extracts_text_message(): void
    {
        $event = [
            'type' => 'message',
            'message' => [
                'id' => '123456789',
                'type' => 'text',
                'text' => 'Hello, World!',
            ],
        ];

        $message = $this->service->extractMessage($event);

        $this->assertEquals('123456789', $message['id']);
        $this->assertEquals('text', $message['type']);
        $this->assertEquals('Hello, World!', $message['text']);
    }

    public function test_extracts_sticker_message(): void
    {
        $event = [
            'type' => 'message',
            'message' => [
                'id' => '123456789',
                'type' => 'sticker',
                'packageId' => '1',
                'stickerId' => '2',
            ],
        ];

        $message = $this->service->extractMessage($event);

        $this->assertEquals('sticker', $message['type']);
        $this->assertEquals('1', $message['package_id']);
        $this->assertEquals('2', $message['sticker_id']);
    }

    public function test_extracts_location_message(): void
    {
        $event = [
            'type' => 'message',
            'message' => [
                'id' => '123456789',
                'type' => 'location',
                'latitude' => 13.7563,
                'longitude' => 100.5018,
                'address' => 'Bangkok, Thailand',
            ],
        ];

        $message = $this->service->extractMessage($event);

        $this->assertEquals('location', $message['type']);
        $this->assertEquals(13.7563, $message['latitude']);
        $this->assertEquals(100.5018, $message['longitude']);
        $this->assertEquals('Bangkok, Thailand', $message['address']);
    }

    public function test_is_message_event(): void
    {
        $messageEvent = ['type' => 'message', 'message' => []];
        $followEvent = ['type' => 'follow'];

        $this->assertTrue($this->service->isMessageEvent($messageEvent));
        $this->assertFalse($this->service->isMessageEvent($followEvent));
    }

    public function test_is_text_message(): void
    {
        $textEvent = [
            'type' => 'message',
            'message' => ['type' => 'text', 'text' => 'Hello'],
        ];
        $imageEvent = [
            'type' => 'message',
            'message' => ['type' => 'image'],
        ];

        $this->assertTrue($this->service->isTextMessage($textEvent));
        $this->assertFalse($this->service->isTextMessage($imageEvent));
    }

    public function test_creates_text_message(): void
    {
        $message = $this->service->textMessage('Hello, World!');

        $this->assertEquals([
            'type' => 'text',
            'text' => 'Hello, World!',
        ], $message);
    }

    public function test_truncates_long_text_message(): void
    {
        $longText = str_repeat('a', 6000);

        $message = $this->service->textMessage($longText);

        $this->assertEquals(5000, mb_strlen($message['text']));
    }

    public function test_creates_quick_reply_message(): void
    {
        $message = $this->service->quickReplyMessage('Choose one:', [
            ['label' => 'Option 1', 'text' => 'opt1'],
            ['label' => 'Option 2', 'text' => 'opt2'],
        ]);

        $this->assertEquals('text', $message['type']);
        $this->assertEquals('Choose one:', $message['text']);
        $this->assertCount(2, $message['quickReply']['items']);
        $this->assertEquals('message', $message['quickReply']['items'][0]['action']['type']);
    }

    public function test_quick_reply_truncates_label(): void
    {
        $message = $this->service->quickReplyMessage('Choose:', [
            ['label' => 'This is a very long label that exceeds 20 characters'],
        ]);

        $label = $message['quickReply']['items'][0]['action']['label'];
        $this->assertEquals(20, mb_strlen($label));
    }

    public function test_quick_reply_limits_to_13_items(): void
    {
        $items = array_fill(0, 20, ['label' => 'Option']);

        $message = $this->service->quickReplyMessage('Choose:', $items);

        $this->assertCount(13, $message['quickReply']['items']);
    }

    public function test_reply_sends_request_to_line_api(): void
    {
        Http::fake([
            'api.line.me/v2/bot/message/reply' => Http::response([], 200),
        ]);

        $bot = $this->createBotInstance([
            'channel_access_token' => 'test_token',
        ]);

        $result = $this->service->reply($bot, 'reply_token', ['Hello!']);

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.line.me/v2/bot/message/reply'
                && $request->hasHeader('Authorization', 'Bearer test_token')
                && $request['replyToken'] === 'reply_token'
                && $request['messages'][0]['type'] === 'text'
                && $request['messages'][0]['text'] === 'Hello!';
        });
    }

    public function test_reply_throws_exception_on_failure(): void
    {
        Http::fake([
            'api.line.me/v2/bot/message/reply' => Http::response([
                'message' => 'Invalid reply token',
            ], 400),
        ]);

        $bot = $this->createBotInstance([
            'channel_access_token' => 'test_token',
        ]);

        $this->expectException(LINEException::class);
        $this->expectExceptionMessage('Failed to send LINE reply: Invalid reply token');

        $this->service->reply($bot, 'invalid_token', ['Hello!']);
    }

    public function test_push_sends_request_to_line_api(): void
    {
        Http::fake([
            'api.line.me/v2/bot/message/push' => Http::response([], 200),
        ]);

        $bot = $this->createBotInstance([
            'channel_access_token' => 'test_token',
        ]);

        $result = $this->service->push($bot, 'U1234567890', ['Hello!']);

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.line.me/v2/bot/message/push'
                && $request['to'] === 'U1234567890';
        });
    }

    public function test_get_profile_returns_user_data(): void
    {
        Http::fake([
            'api.line.me/v2/bot/profile/*' => Http::response([
                'userId' => 'U1234567890',
                'displayName' => 'Test User',
                'pictureUrl' => 'https://example.com/picture.jpg',
                'statusMessage' => 'Hello!',
            ], 200),
        ]);

        $bot = $this->createBotInstance([
            'channel_access_token' => 'test_token',
        ]);

        $profile = $this->service->getProfile($bot, 'U1234567890');

        $this->assertEquals('Test User', $profile['displayName']);
        $this->assertEquals('https://example.com/picture.jpg', $profile['pictureUrl']);
    }

    public function test_get_profile_returns_empty_on_failure(): void
    {
        Http::fake([
            'api.line.me/v2/bot/profile/*' => Http::response([], 404),
        ]);

        $bot = $this->createBotInstance([
            'channel_access_token' => 'test_token',
        ]);

        $profile = $this->service->getProfile($bot, 'U_invalid');

        $this->assertEquals('U_invalid', $profile['userId']);
        $this->assertNull($profile['displayName']);
    }

    public function test_throws_exception_without_channel_access_token(): void
    {
        $this->expectException(LINEException::class);
        $this->expectExceptionMessage('Bot has no LINE channel access token configured');

        $bot = $this->createBotInstance([
            'channel_access_token' => null,
        ]);

        $this->service->reply($bot, 'token', ['Hello']);
    }
}
