<?php

namespace App\Support;

/**
 * ค่าเริ่มต้นข้อความ "เมื่อบอทล่ม" และความหมายของ "ค่าว่าง" — ที่เดียวเท่านั้น (การ์ด outage-settings B1)
 *
 * ข้อความจริงของบอทแต่ละตัวเก็บใน bot_settings (nullable text); ค่าว่าง/null =
 * ใช้ค่าเริ่มต้นที่นี่ จึงแก้ค่าเริ่มต้นทั้งระบบได้ที่โค้ดโดยไม่ต้องแตะข้อมูลเดิม
 * ไม่มีการเข้าถึง database/cache ในคลาสนี้
 */
class OutageMessageText
{
    public const RECOVERY_THAI = 'ขออภัยครับพี่ 🙏 ก่อนหน้านี้ระบบขัดข้องชั่วคราว ตอนนี้กลับมาใช้งานได้ปกติแล้วครับ รบกวนพี่พิมพ์ข้อความเข้ามาใหม่อีกครั้งนะครับ';

    /** RECOVERY_ENGLISH เหลือไว้เพื่อค่าเริ่มต้นของ API (defaults()) เท่านั้น — เส้นทางส่งข้อความจริงไม่ใช้แล้ว (owner order 14 Nov 2026) */
    public const RECOVERY_ENGLISH = 'Sorry for the inconvenience 🙏 Our system had a temporary issue and is working again now. Please send your message again.';

    public const DOWN_REPLY = 'ระบบขัดข้องชั่วคราวครับ 🙏 เดี๋ยวแอดมินรีบตรวจสอบให้นะครับ';

    /**
     * {recovery_message_th, recovery_message_en, down_message} สำหรับ API defaults
     *
     * @return array{outage_recovery_message_th: string, outage_recovery_message_en: string, outage_down_message: string}
     */
    public static function defaults(): array
    {
        return [
            'outage_recovery_message_th' => self::RECOVERY_THAI,
            'outage_recovery_message_en' => self::RECOVERY_ENGLISH,
            'outage_down_message' => self::DOWN_REPLY,
        ];
    }

    /**
     * "ค่าว่าง" = null หรือ whitespace ยูนิโคดล้วน (\s + /u) — ไม่ใช่ PHP falsiness
     * เพราะ '0' เป็นข้อความปกติที่เจ้าของตั้งได้จริง
     */
    public static function isBlank(?string $value): bool
    {
        return $value === null || preg_match('/^\s*$/uD', $value) === 1;
    }

    /**
     * ค่าที่เก็บจากผู้ใช้ → ข้อความจริงที่ใช้ส่ง: ค่าว่างใช้ค่าเริ่มต้น, ไม่งั้นคืนค่าเดิมทุกตัวอักษร
     */
    public static function resolve(?string $value, string $default): string
    {
        return self::isBlank($value) ? $default : $value;
    }
}
