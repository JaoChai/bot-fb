<?php

namespace Tests\Unit\Services\Channel;

use App\Models\Bot;
use App\Models\Conversation;
use App\Services\Channel\ChannelAdapterFactory;
use App\Services\Channel\FacebookChannelAdapter;
use App\Services\FacebookService;
use Mockery;
use Tests\TestCase;

class FacebookChannelAdapterTest extends TestCase
{
    public function test_factory_registers_facebook(): void
    {
        $factory = app(ChannelAdapterFactory::class);

        $this->assertTrue($factory->supports('facebook'));
        $this->assertInstanceOf(FacebookChannelAdapter::class, $factory->make('facebook'));
    }

    public function test_text_message_goes_to_send_message_with_psid(): void
    {
        $bot = new Bot(['id' => 1]);
        $conversation = new Conversation(['external_customer_id' => 'PSID-1']);

        $fb = Mockery::mock(FacebookService::class);
        $fb->shouldReceive('sendMessage')->once()->with($bot, 'PSID-1', 'hello')->andReturn([]);

        (new FacebookChannelAdapter($fb))->sendMessage($bot, $conversation, 'text', 'hello');
        $this->addToAssertionCount(1);
    }

    public function test_image_with_media_url_goes_to_send_image(): void
    {
        $bot = new Bot(['id' => 1]);
        $conversation = new Conversation(['external_customer_id' => 'PSID-1']);

        $fb = Mockery::mock(FacebookService::class);
        $fb->shouldReceive('sendImage')->once()->with($bot, 'PSID-1', 'https://x/img.jpg')->andReturn([]);
        $fb->shouldNotReceive('sendMessage');

        (new FacebookChannelAdapter($fb))->sendMessage($bot, $conversation, 'image', 'caption', 'https://x/img.jpg');
        $this->addToAssertionCount(1);
    }

    public function test_image_without_media_url_falls_back_to_text(): void
    {
        $bot = new Bot(['id' => 1]);
        $conversation = new Conversation(['external_customer_id' => 'PSID-1']);

        $fb = Mockery::mock(FacebookService::class);
        $fb->shouldReceive('sendMessage')->once()->with($bot, 'PSID-1', 'caption')->andReturn([]);

        (new FacebookChannelAdapter($fb))->sendMessage($bot, $conversation, 'image', 'caption', null);
        $this->addToAssertionCount(1);
    }
}
