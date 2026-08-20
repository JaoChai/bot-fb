<?php

namespace App\Services\Guardrail;

use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Support\Facades\Cache;

/**
 * ตัดวงจรเมื่อลูกค้าคนเดียวโดน off-topic guardrail ซ้ำเกิน threshold ในบทสนทนาเดียว
 * ภายใน 24 ชม. — กัน token cost จากการใช้บอทฟรีซ้ำๆ (ถามแปลภาษา/เขียนโค้ด ฯลฯ)
 * เกินแล้วข้ามการเรียก LLM ไปเลย ตอบข้อความสำเร็จรูปแทนทุกครั้ง
 * (ดู docs/superpowers/specs/2026-08-20-off-topic-guardrail-enforcement-design.md)
 */
class OffTopicCircuitBreaker
{
    public const THRESHOLD = 3;

    private const TTL_SECONDS = 86400;

    public const CANNED_MESSAGE = 'ขอโทษครับพี่ อันนี้ผมช่วยไม่ได้ตรงนี้ครับ สนใจสินค้าตัวไหนดีครับ?';

    public function isTripped(Bot $bot, Conversation $conversation): bool
    {
        $count = (int) Cache::get(self::cacheKey($bot->id, $conversation->id), 0);

        return $count >= self::THRESHOLD;
    }

    public function recordTrigger(Bot $bot, Conversation $conversation): void
    {
        $key = self::cacheKey($bot->id, $conversation->id);

        // Cache::add() ตั้งค่า+TTL แบบ atomic เฉพาะตอน key ไม่มีอยู่ — กัน race ที่ key
        // หมดอายุระหว่าง has()/increment() แล้ว increment() ไปสร้าง key ใหม่แบบไม่มี TTL
        // (นับไม่มีวันหมดอายุ = ล็อกลูกค้าถาวรแทนที่จะรีเซ็ตทุก 24 ชม.)
        if (Cache::add($key, 1, self::TTL_SECONDS)) {
            return;
        }

        Cache::increment($key);
    }

    public static function cacheKey(int $botId, int $conversationId): string
    {
        return "off_topic_count:{$botId}:{$conversationId}";
    }
}
