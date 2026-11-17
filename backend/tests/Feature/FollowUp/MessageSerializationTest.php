<?php

namespace Tests\Feature\FollowUp;

use App\Events\MessageSent;
use App\Http\Resources\MessageResource;
use App\Models\Bot;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * B4b (D39/D45) — ป้าย "ข้อความตามลูกค้า" ต้องเดินทางเป็นบูลีน is_follow_up
 * บนทุกช่องทางที่แอดมินเห็นข้อความ: หน้าข้อความ, รายละเอียดแชท, พรีวิวรายการแชท,
 * sync รายการแชท, sync ข้อความ, MessageResource, broadcast MessageSent —
 * และ metadata ดิบ (รวม follow_up_log_id) ห้ามหลุดออกจาก JSON ใด ๆ ที่แอดมินเห็น
 */
class MessageSerializationTest extends TestCase
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

    /** แถวข้อความตามลูกค้า: sender=bot + metadata.follow_up=true (log id ใน metadata ต้องไม่หลุด) */
    private function createFollowUp(): Message
    {
        return $this->conversation->messages()->create([
            'sender' => 'bot',
            'content' => 'คุณลูกค้าครับ จากที่คุยกันไป เรามีทางเลือกให้ต่อครับ',
            'type' => 'text',
            'metadata' => ['follow_up' => true, 'follow_up_log_id' => 987654321],
        ]);
    }

    /** แถวบอทปกติ (ไม่ได้ตามลูกค้า) */
    private function createOrdinaryBot(): Message
    {
        return $this->conversation->messages()->create([
            'sender' => 'bot',
            'content' => 'ตอบปกติครับ',
            'type' => 'text',
            'metadata' => null,
        ]);
    }

    /** แถวลูกค้า */
    private function createCustomer(): Message
    {
        return $this->conversation->messages()->create([
            'sender' => 'user',
            'content' => 'สวัสดีครับ',
            'type' => 'text',
            'metadata' => null,
        ]);
    }

    #[Test]
    public function test_all_message_ingresses_only_expose_follow_up_boolean(): void
    {
        $tagged = $this->createFollowUp();
        $ordinary = $this->createOrdinaryBot();
        $customer = $this->createCustomer();

        $expected = [
            $tagged->id => true,
            $ordinary->id => false,
            $customer->id => false,
        ];

        // 1) หน้าข้อความแบ่งหน้า (ConversationMessageController@index)
        $page = $this->actingAs($this->user)
            ->getJson("/api/bots/{$this->bot->id}/conversations/{$this->conversation->id}/messages");
        $page->assertOk();
        $this->assertRowsHideMetadataExposeFlag($page->json('data'), $expected);
        $this->assertBodyHidesFollowUpMetadata($page->getContent());

        // 2) รายละเอียดแชท (ConversationController@show → data.messages)
        $detail = $this->actingAs($this->user)
            ->getJson("/api/bots/{$this->bot->id}/conversations/{$this->conversation->id}");
        $detail->assertOk();
        $this->assertRowsHideMetadataExposeFlag($detail->json('data.messages'), $expected);
        $this->assertBodyHidesFollowUpMetadata($detail->getContent());

        // 3) พรีวิวรายการแชท (ConversationController@index → last_message) — แถวตามลูกค้าคือข้อความล่าสุด
        $this->conversation->forceFill(['last_message_id' => $tagged->id])->save();
        $list = $this->actingAs($this->user)
            ->getJson("/api/bots/{$this->bot->id}/conversations");
        $list->assertOk();
        $item = collect($list->json('data'))->firstWhere('id', $this->conversation->id);
        $this->assertNotNull($item, 'conversation list must contain the conversation');
        $this->assertSame(true, data_get($item, 'last_message.is_follow_up'));
        $this->assertFalse(array_key_exists('metadata', (array) data_get($item, 'last_message')));
        $this->assertBodyHidesFollowUpMetadata($list->getContent());

        // 4) sync รายการแชท (SyncController::conversations → last_message)
        $syncConversations = $this->actingAs($this->user)
            ->getJson("/api/bots/{$this->bot->id}/conversations/sync");
        $syncConversations->assertOk();
        $syncItem = collect($syncConversations->json('data'))->firstWhere('id', $this->conversation->id);
        $this->assertNotNull($syncItem, 'conversation sync must contain the conversation');
        $this->assertSame(true, data_get($syncItem, 'last_message.is_follow_up'));
        $this->assertFalse(array_key_exists('metadata', (array) data_get($syncItem, 'last_message')));
        $this->assertBodyHidesFollowUpMetadata($syncConversations->getContent());

        // 5) sync ข้อความ (SyncController::messages → data)
        $syncMessages = $this->actingAs($this->user)
            ->getJson("/api/bots/{$this->bot->id}/conversations/{$this->conversation->id}/messages/sync");
        $syncMessages->assertOk();
        $this->assertRowsHideMetadataExposeFlag($syncMessages->json('data'), $expected);
        $this->assertBodyHidesFollowUpMetadata($syncMessages->getContent());

        // 6) resource เดี่ยว (MessageResource::make → resolve)
        foreach ([$tagged, $ordinary, $customer] as $row) {
            $expectedFlag = $expected[$row->id];
            $payload = MessageResource::make($row)->resolve();
            $this->assertSame($expectedFlag, data_get($payload, 'is_follow_up'), "resource flag row {$row->id}");
            $this->assertFalse(array_key_exists('metadata', $payload), "metadata leaked in resource row {$row->id}");
            $this->assertBodyHidesFollowUpMetadata((string) json_encode($payload));
        }

        // 7) broadcast (MessageSent::broadcastWith) + JSON ที่จะถูกส่งจริง
        foreach ([$tagged, $ordinary, $customer] as $row) {
            $expectedFlag = $expected[$row->id];
            $payload = (new MessageSent($row))->broadcastWith();
            $this->assertSame($expectedFlag, data_get($payload, 'is_follow_up'), "broadcast flag row {$row->id}");
            $this->assertFalse(array_key_exists('metadata', $payload), "metadata leaked in broadcast row {$row->id}");
            $this->assertBodyHidesFollowUpMetadata((string) json_encode($payload));
        }
    }

    /**
     * บูลีนเป็น true เฉพาะแถวบอทที่ metadata.follow_up === true เท่านั้น —
     * string "true"/"1", 1, false, null, key ไม่มี, metadata null/[]/list/obj ซ้อน,
     * แถวลูกค้า/แอดมิน, มีแต่ follow_up_log_id ล้วนเป็น false
     *
     * @return array<string, array{string, array<string, mixed>|null, bool}>
     */
    public static function followUpPredicateCases(): array
    {
        return [
            'bot + follow_up true' => ['bot', ['follow_up' => true], true],
            'bot + follow_up string true' => ['bot', ['follow_up' => 'true'], false],
            'bot + follow_up int 1' => ['bot', ['follow_up' => 1], false],
            'bot + follow_up string 1' => ['bot', ['follow_up' => '1'], false],
            'bot + follow_up false' => ['bot', ['follow_up' => false], false],
            'bot + follow_up null value' => ['bot', ['follow_up' => null], false],
            'bot + key absent' => ['bot', ['other' => 1], false],
            'bot + metadata null' => ['bot', null, false],
            'bot + metadata empty array' => ['bot', [], false],
            'bot + metadata json list [true]' => ['bot', [true], false],
            'bot + metadata nested object' => ['bot', ['follow_up' => ['x' => true]], false],
            'user + follow_up true' => ['user', ['follow_up' => true], false],
            'agent + follow_up true' => ['agent', ['follow_up' => true], false],
            'bot + only follow_up_log_id' => ['bot', ['follow_up_log_id' => 987654321], false],
        ];
    }

    #[Test]
    #[DataProvider('followUpPredicateCases')]
    public function test_follow_up_flag_predicate_table(string $sender, ?array $metadata, bool $expected): void
    {
        $message = Message::factory()->for($this->conversation)->create([
            'sender' => $sender,
            'content' => 'ข้อความทดสอบ',
            'type' => 'text',
            'metadata' => $metadata,
        ]);

        $label = "sender={$sender} metadata=".json_encode($metadata);

        $resourcePayload = MessageResource::make($message)->resolve();
        $this->assertSame($expected, data_get($resourcePayload, 'is_follow_up'), "resource: {$label}");
        $this->assertFalse(array_key_exists('metadata', $resourcePayload), "metadata leaked in resource: {$label}");

        $broadcastPayload = (new MessageSent($message))->broadcastWith();
        $this->assertSame($expected, data_get($broadcastPayload, 'is_follow_up'), "broadcast: {$label}");
        $this->assertFalse(array_key_exists('metadata', $broadcastPayload), "metadata leaked in broadcast: {$label}");
    }

    /**
     * metadata ที่ไม่ใช่ object: แถวบอทที่เก็บ JSON string "follow_up" และ JSON number 1
     * (เขียนผ่าน query builder ข้าม cast) — ทั้ง is_follow_up และ is_outage_notice เป็น false
     * โดยไม่ throw และทั้งสอง resource ต้อง resolve ได้
     */
    #[Test]
    public function test_scalar_metadata_json_values_yield_false_flags_and_still_resolve(): void
    {
        $stringRowId = DB::table('messages')->insertGetId([
            'conversation_id' => $this->conversation->id,
            'sender' => 'bot',
            'content' => 'metadata เป็น JSON string',
            'type' => 'text',
            'metadata' => '"follow_up"',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $numberRowId = DB::table('messages')->insertGetId([
            'conversation_id' => $this->conversation->id,
            'sender' => 'bot',
            'content' => 'metadata เป็น JSON number',
            'type' => 'text',
            'metadata' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$stringRowId, $numberRowId] as $id) {
            $row = Message::query()->findOrFail($id);

            $this->assertSame(false, $row->isFollowUp(), "isFollowUp for row {$id} (metadata raw: ".var_export($row->getRawOriginal('metadata'), true).')');
            $this->assertSame(false, $row->isOutageNotice(), "isOutageNotice for row {$id}");

            $resourcePayload = MessageResource::make($row)->resolve();
            $this->assertSame(false, data_get($resourcePayload, 'is_follow_up'), "resource is_follow_up for row {$id}");
            $this->assertSame(false, data_get($resourcePayload, 'is_outage_notice'), "resource is_outage_notice for row {$id}");

            $broadcastPayload = (new MessageSent($row))->broadcastWith();
            $this->assertSame(false, data_get($broadcastPayload, 'is_follow_up'), "broadcast is_follow_up for row {$id}");
            $this->assertSame(false, data_get($broadcastPayload, 'is_outage_notice'), "broadcast is_outage_notice for row {$id}");
        }
    }

    /** สองป้ายเป็นอิสระจากกัน: ติดคู่ = true ทั้งคู่, ติดเดี่ยว = อีกป้าย false */
    #[Test]
    public function test_follow_up_and_outage_notice_flags_are_independent(): void
    {
        $both = $this->conversation->messages()->create([
            'sender' => 'bot',
            'content' => 'ติดทั้งสองป้าย',
            'type' => 'text',
            'metadata' => ['outage_notice' => true, 'follow_up' => true],
        ]);

        $outageOnly = $this->conversation->messages()->create([
            'sender' => 'bot',
            'content' => 'ติดป้ายล่มอย่างเดียว',
            'type' => 'text',
            'metadata' => ['outage_notice' => true],
        ]);

        $followUpOnly = $this->conversation->messages()->create([
            'sender' => 'bot',
            'content' => 'ติดป้ายตามลูกค้าอย่างเดียว',
            'type' => 'text',
            'metadata' => ['follow_up' => true],
        ]);

        $this->assertSame(true, data_get(MessageResource::make($both)->resolve(), 'is_follow_up'));
        $this->assertSame(true, data_get(MessageResource::make($both)->resolve(), 'is_outage_notice'));
        $this->assertSame(true, data_get((new MessageSent($both))->broadcastWith(), 'is_follow_up'));
        $this->assertSame(true, data_get((new MessageSent($both))->broadcastWith(), 'is_outage_notice'));

        $this->assertSame(false, data_get(MessageResource::make($outageOnly)->resolve(), 'is_follow_up'), 'outage-only row must not be follow-up');
        $this->assertSame(true, data_get(MessageResource::make($outageOnly)->resolve(), 'is_outage_notice'));
        $this->assertSame(false, data_get((new MessageSent($outageOnly))->broadcastWith(), 'is_follow_up'), 'outage-only row must not be follow-up (broadcast)');

        $this->assertSame(false, data_get(MessageResource::make($followUpOnly)->resolve(), 'is_outage_notice'), 'follow-up-only row must not be outage notice');
        $this->assertSame(true, data_get(MessageResource::make($followUpOnly)->resolve(), 'is_follow_up'));
        $this->assertSame(false, data_get((new MessageSent($followUpOnly))->broadcastWith(), 'is_outage_notice'), 'follow-up-only row must not be outage notice (broadcast)');
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
            $this->assertSame($expectedFlag, data_get($row, 'is_follow_up'), "flag for row {$id}");
            $this->assertFalse(array_key_exists('metadata', $row), "raw metadata leaked for row {$id}");
        }
    }

    private function assertBodyHidesFollowUpMetadata(string $body): void
    {
        $this->assertStringNotContainsString('follow_up_log_id', $body, 'metadata key follow_up_log_id leaked into client JSON');
        $this->assertStringNotContainsString('987654321', $body, 'follow-up log id 987654321 leaked into client JSON');
    }
}
