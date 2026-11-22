<?php

namespace App\Services\FollowUp;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HR1 — ประตูเวลาทำการของบอทสำหรับ "ตามลูกค้า": จุดเช็คเดียวที่ทุกโหมดใช้ร่วมกัน
 *
 * หน้าต่างที่ทำงานได้ = จุดตัดของ
 *  (1) หน้าต่างคงที่ 08:00-22:00 เวลากรุงเทพฯ (ขอบรวม 08:00:00 ตัด 22:00:00 — เดิมของระบบตามลูกค้า)
 *  (2) สล็อต response hours ที่เจ้าของตั้งไว้ในแอดมิน (bot_settings.response_hours)
 *      — เปิดใช้เท่านั้น (enabled + ค่าเป็น array ไม่ว่าง); วันไหนไม่มีสล็อต = ร้านปิดวันนั้น
 *
 * ผลลัพธ์: null = เปิดทำงานได้; ไม่งั้นคืน reason เสถียร
 *  - day_closed    : วันตามโซนของบอทไม่มีสล็อต (ปิดทั้งวัน ไม่ขึ้นกับนาฬิกา)
 *  - outside_hours : นอกหน้าต่างคงที่ หรือนอกสล็อตของวันนั้น (โค้ดเดิมของ recheck)
 *
 * pure ต่อนาฬิกา: วันและเวลามาจาก $now ที่ caller ส่งเข้ามาเสมอ (เทสเดินนาฬิกาได้)
 * โซนของสล็อตเอาจาก bot_settings.response_hours_timezone (fallback Asia/Bangkok)
 *
 * ความหมายของ setting คงตาม ResponseHoursService::checkResponseHours เดิม (LINE webhook):
 * enabled เท็จ / ไม่มีแถว settings / response_hours ว่างหรือไม่ใช่ array = ไม่เพิ่มข้อจำกัด;
 * วันไม่มีใน config หรือ list สล็อตว่าง/ไม่ใช่ array = วันปิด; สล็อตเสียรูป = สล็อตนั้นปิด (fail-closed)
 */
class FollowUpResponseHours
{
    public const REASON_DAY_CLOSED = 'day_closed';

    public const REASON_OUTSIDE_HOURS = 'outside_hours';

    /** null = เปิดทำงาน; ไม่งั้น = reason เสถียร */
    public static function closureReason(int $botId, CarbonImmutable $now): ?string
    {
        $settings = DB::table('bot_settings')
            ->where('bot_id', $botId)
            ->first(['response_hours_enabled', 'response_hours_timezone', 'response_hours']);

        $hours = self::restrictedHours($settings);
        $botNow = $hours === null ? null : $now->tz(self::timezone($settings));

        // วันปิดตัดสินก่อนเวลา — คำตอบไม่ขึ้นกับนาฬิกา
        if ($hours !== null && ! self::dayOpen($hours, strtolower($botNow->format('D')))) {
            return self::REASON_DAY_CLOSED;
        }

        if (! self::withinFixedWindow($now)) {
            return self::REASON_OUTSIDE_HOURS;
        }

        if ($hours !== null && ! self::slotOpen($hours, $botNow)) {
            return self::REASON_OUTSIDE_HOURS;
        }

        return null;
    }

    /** response hours เมื่อ setting จำกัดจริง — null = ไม่จำกัด (หน้าต่างคงที่ยังกั้นตามเดิม) */
    private static function restrictedHours(?object $settings): ?array
    {
        if ($settings === null || ! (bool) $settings->response_hours_enabled) {
            return null;
        }

        $hours = $settings->response_hours;
        // อ่านผ่าน query builder — คอลัมน์ json มาเป็น string ทุกฐานข้อมูล ไม่ผ่าน cast ของ Eloquent
        if (is_string($hours)) {
            $hours = json_decode($hours, true);
        }

        return is_array($hours) && $hours !== [] ? $hours : null;
    }

    /** หน้าต่างคงที่ของระบบตามลูกค้า: 08:00:00 ≤ เวลากรุงเทพฯ < 22:00:00 */
    private static function withinFixedWindow(CarbonImmutable $now): bool
    {
        $local = $now->tz(FollowUpCapabilities::TIMEZONE);
        $secondsOfDay = $local->hour * 3600 + $local->minute * 60 + $local->second;

        return $secondsOfDay >= self::secondsOf(FollowUpCapabilities::SEND_START)
            && $secondsOfDay < self::secondsOf(FollowUpCapabilities::SEND_END);
    }

    private static function secondsOf(string $hourMinute): int
    {
        [$hour, $minute] = array_map(intval(...), explode(':', $hourMinute));

        return $hour * 3600 + $minute * 60;
    }

    /** วันนั้นมี list สล็อตใช้ได้หรือยัง — ไม่มีใน config / ว่าง / ไม่ใช่ array = วันปิด */
    private static function dayOpen(array $hours, string $dayKey): bool
    {
        return isset($hours[$dayKey]) && is_array($hours[$dayKey]) && $hours[$dayKey] !== [];
    }

    /** เวลาปัจจุบัน (โซนบอท) อยู่ในสล็อตใดสล็อตหนึ่งของวัน — สล็อตเสียรูปนับเป็นสล็อตปิด */
    private static function slotOpen(array $hours, CarbonImmutable $botNow): bool
    {
        $time = $botNow->format('H:i');

        foreach ($hours[strtolower($botNow->format('D'))] as $slot) {
            if (self::slotContains($slot, $time)) {
                return true;
            }
        }

        return false;
    }

    /**
     * รูปแบบเดียวกับ ResponseHoursService::isWithinSlot — เทียบ string H:i, ขอบปิดแบบเข้มงวด
     * (start ≤ เวลา < end; สล็อตข้ามเที่ยงคืนคือ end < start) แต่เข้มกว่าเดิมตรง
     * สล็อตเสียรูป: ไม่ใช่ array, ขาด start/end หรือเวลาอ่านไม่ได้ = ปิดเสมอ (fail-closed)
     */
    private static function slotContains(mixed $slot, string $time): bool
    {
        if (! is_array($slot)) {
            return false;
        }

        $start = $slot['start'] ?? null;
        $end = $slot['end'] ?? null;

        if (! self::isClock($start) || ! self::isClock($end)) {
            return false;
        }

        if ($end < $start) {
            return $time >= $start || $time < $end;
        }

        return $time >= $start && $time < $end;
    }

    /** H:MM หรือ HH:MM เท่านั้น — อย่างอื่นคือสล็อตเสียรูป */
    private static function isClock(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /** โซนของบอทตาม setting — ค่าว่าง/ค่าอ่านไม่ได้ = กรุงเทพฯ (เงียบ ไม่ log ใน pure gate) */
    private static function timezone(?object $settings): string
    {
        $timezone = $settings?->response_hours_timezone;

        if (is_string($timezone) && $timezone !== '') {
            try {
                new \DateTimeZone($timezone);

                return $timezone;
            } catch (\Exception) {
                return FollowUpCapabilities::TIMEZONE;
            }
        }

        return FollowUpCapabilities::TIMEZONE;
    }
}
