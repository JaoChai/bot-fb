<?php

namespace Tests\Feature\Outage;

use App\Events\MessageSent;
use App\Http\Resources\MessageResource;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * R11 — ป้าย "ส่งอัตโนมัติหลังบอทล่ม" ต้องเดินทางเป็นบูลีน is_outage_notice
 * บนทุกเส้นทางแอดมิน (รายการข้อความ, รายละเอียดแชท, พรีวิวรายการแชท, broadcast)
 * และ metadata ดิบห้ามหลุดออกจาก JSON ใด ๆ ที่แอดมินเห็น
 */
class OutageMessageSerializationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Bot $bot;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->owner()->create();
        $this->bot = Bot::factory()->create(['user_id' => $this->user->id]);
        $this->conversation = Conversation::factory()->create(['bot_id' => $this->bot->id]);
    }

    /** แถวแจ้งลูกค้าหลังบอทกลับมา: sender=bot + metadata.outage_notice=true */
    private function createNotice(): Message
    {
        return $this->conversation->messages()->create([
            'sender' => 'bot',
            'content' => 'ขออภัยครับพี่ ระบบกลับมาใช้ได้แล้ว รบกวนพิมพ์ใหม่อีกครั้งนะครับ',
            'type' => 'text',
            'metadata' => ['outage_notice' => true],
        ]);
    }

    /** แถว down reply ระหว่างบอทล่ม: tag model_error=auth — ต้องไม่ติดป้าย */
    private function createDownReply(): Message
    {
        return $this->conversation->messages()->create([
            'sender' => 'bot',
            'content' => 'ระบบขัดข้องชั่วคราวครับ 🙏 เดี๋ยวแอดมินรีบตรวจสอบให้นะครับ',
            'type' => 'text',
            'metadata' => ['model_error' => 'auth'],
        ]);
    }

    private function createCustomerMessage(): Message
    {
        return $this->conversation->messages()->create([
            'sender' => 'user',
            'content' => 'สวัสดีครับ',
            'type' => 'text',
            'metadata' => ['outage_notice' => true], // ขยะจากลูกค้า/ระบบอื่น — ต้องไม่ติดป้าย
        ]);
    }

    #[Test]
    public function test_all_admin_message_ingresses_expose_only_the_boolean(): void
    {
        $notice = $this->createNotice();
        $down = $this->createDownReply();
        $customer = $this->createCustomerMessage();

        $expected = [
            $notice->id => true,
            $down->id => false,
            $customer->id => false,
        ];

        // 1) รายการข้อความแบบแบ่งหน้า (ConversationMessageController@index)
        $page = $this->actingAs($this->user)
            ->getJson("/api/bots/{$this->bot->id}/conversations/{$this->conversation->id}/messages");
        $page->assertOk();
        $this->assertRowsHideMetadataExposeFlag($page->json('data'), $expected);

        // 2) รายละเอียดแชท (ConversationController@show → messages)
        $detail = $this->actingAs($this->user)
            ->getJson("/api/bots/{$this->bot->id}/conversations/{$this->conversation->id}");
        $detail->assertOk();
        $this->assertRowsHideMetadataExposeFlag($detail->json('data.messages'), $expected);

        // 3) พรีวิวรายการแชท (ConversationController@index → last_message)
        $this->conversation->forceFill(['last_message_id' => $notice->id])->save();
        $list = $this->actingAs($this->user)
            ->getJson("/api/bots/{$this->bot->id}/conversations");
        $list->assertOk();
        $item = collect($list->json('data'))->firstWhere('id', $this->conversation->id);
        $this->assertNotNull($item, 'conversation list must contain the conversation');
        $this->assertSame(true, data_get($item, 'last_message.is_outage_notice'));
        $this->assertFalse(array_key_exists('metadata', (array) data_get($item, 'last_message')));

        // 4) Broadcast (MessageSent::broadcastWith)
        $payload = (new MessageSent($notice))->broadcastWith();
        $this->assertSame(true, data_get($payload, 'is_outage_notice'));
        $this->assertFalse(array_key_exists('metadata', $payload));
    }

    /**
     * บูลีนเป็น true เฉพาะแถวบอทที่ metadata.outage_notice === true เท่านั้น —
     * string "true", null, key อื่น, แถวลูกค้า/แอดมิน ล้วนเป็น false
     */
    #[Test]
    public function test_flag_is_true_only_for_bot_rows_tagged_outage_notice_true(): void
    {
        $cases = [
            'bot tagged true' => ['sender' => 'bot', 'metadata' => ['outage_notice' => true], 'expected' => true],
            'bot tagged string true' => ['sender' => 'bot', 'metadata' => ['outage_notice' => 'true'], 'expected' => false],
            'bot tagged null' => ['sender' => 'bot', 'metadata' => ['outage_notice' => null], 'expected' => false],
            'bot without the key' => ['sender' => 'bot', 'metadata' => ['other' => 1], 'expected' => false],
            'bot with null metadata' => ['sender' => 'bot', 'metadata' => null, 'expected' => false],
            'customer row with junk tag' => ['sender' => 'user', 'metadata' => ['outage_notice' => true], 'expected' => false],
            'agent row untagged' => ['sender' => 'agent', 'metadata' => null, 'expected' => false],
        ];

        foreach ($cases as $label => $case) {
            $message = Message::factory()->for($this->conversation)->make([
                'sender' => $case['sender'],
                'content' => 'ข้อความทดสอบ',
                'type' => 'text',
                'metadata' => $case['metadata'],
            ]);
            $message->id = 4242;
            $message->created_at = now();
            $message->updated_at = now();

            $payload = MessageResource::make($message)->resolve();

            $this->assertSame($case['expected'], data_get($payload, 'is_outage_notice'), "case: {$label}");
            $this->assertFalse(array_key_exists('metadata', $payload), "metadata leaked in case: {$label}");
        }
    }

    #[Test]
    public function test_message_sent_broadcast_carries_the_flag_and_no_metadata(): void
    {
        $notice = $this->createNotice();
        $down = $this->createDownReply();

        $noticePayload = (new MessageSent($notice))->broadcastWith();
        $this->assertSame(true, data_get($noticePayload, 'is_outage_notice'));
        $this->assertFalse(array_key_exists('metadata', $noticePayload));

        $downPayload = (new MessageSent($down))->broadcastWith();
        $this->assertSame(false, data_get($downPayload, 'is_outage_notice'), 'down reply must NOT carry the badge flag');
        $this->assertFalse(array_key_exists('metadata', $downPayload));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, bool>  $expectedById
     */
    private function assertRowsHideMetadataExposeFlag(array $rows, array $expectedById): void
    {
        foreach ($expectedById as $id => $expectedFlag) {
            $row = collect($rows)->firstWhere('id', $id);
            $this->assertNotNull($row, "row {$id} missing");
            $this->assertSame($expectedFlag, data_get($row, 'is_outage_notice'), "flag for row {$id}");
            $this->assertFalse(array_key_exists('metadata', $row), "raw metadata leaked for row {$id}");
        }
    }
}
