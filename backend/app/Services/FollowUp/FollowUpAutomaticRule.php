<?php

namespace App\Services\FollowUp;

use App\Models\LeadRecoveryLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * กฎ A1 ตัดสิน "ร่างข้อความตามลูกค้าฉบับนี้ ส่งเองได้ไหม" — การ์ด B1
 * (plan "Rule and single decision writer" + spec.md A1 + คำสั่ง Lead A14-A15)
 *
 * reason() คืน null เมื่อทุกเงื่อนไขผ่าน ไม่งั้นคืนโค้ดเสถียรตัวแรกที่ตก ตามลำดับตายตัว:
 *
 * | เงื่อนไข                                   | โค้ด              |
 * |--------------------------------------------|-------------------|
 * | bot_settings.follow_up_enabled exactly true | auto_switch_off   |
 * | follow_up_mode เป็น "automatic" เป๊ะ        | auto_mode_approve |
 * | forBot()['automatic_allowed'] (host+14วัน)  | auto_not_allowed  |
 * | group_code เป็น G2 หรือ G3                  | auto_group        |
 * | group_confidence ตัวเลข [0,1] >= min        | auto_confidence   |
 * | declined_score ตัวเลข [0,1] < threshold     | auto_declined     |
 * | เช็คฉบับร่างสะอาด (failed [] และ error false)| auto_check       |
 * | revision 1 + ข้อความตรงร่าง + ไม่ override   | auto_revision     |
 * | FollowUpEligibility::recheck(forSend) ผ่าน  | auto_eligibility  |
 *
 * ขอบเขตตามคำสั่ง Lead:
 * - ไม่มีเงื่อนไขโควต้า LINE (A15 — โควต้าอยู่ที่ตัวส่ง step 5 ตามเดิม) และไม่มี HTTP/การเขียนใด ๆ
 *   อ่าน database + config เท่านั้น — การ์ดนี้ยังไม่มี caller บอททุกตัวยัง mode approve จึงส่งเองไม่ได้
 * - ผู้เรียก (การ์ด B3a) ต้องเรียก reason() ใหม่จากฐานข้อมูลเป็นขั้นสุดท้ายก่อน freeze write (A14)
 * - fail closed: ขาดบอท / ขาด bot_settings → auto_switch_off (แนวเดียวกับ
 *   FollowUpEligibility::recheck ที่บอทหาย = bot_switched_off), ขาดบัตรอนุมัติ → auto_check
 * - คะแนนต้องเป็นตัวเลขจริงในช่วง [0,1]: null, boolean, string ไม่เป็นเลข, NaN, INF,
 *   นอกช่วง = ไม่ผ่านทั้งหมด (ห้ามแปลง null เป็น 0)
 */
class FollowUpAutomaticRule
{
    public const AUTO_SWITCH_OFF = 'auto_switch_off';

    public const AUTO_MODE_APPROVE = 'auto_mode_approve';

    public const AUTO_NOT_ALLOWED = 'auto_not_allowed';

    public const AUTO_GROUP = 'auto_group';

    public const AUTO_CONFIDENCE = 'auto_confidence';

    public const AUTO_DECLINED = 'auto_declined';

    public const AUTO_CHECK = 'auto_check';

    public const AUTO_REVISION = 'auto_revision';

    public const AUTO_ELIGIBILITY = 'auto_eligibility';

    /**
     * ตัดสินกฎ A1 ณ เวลา $now — อ่านสถานะล่าสุดจากฐานข้อมูลทุกครั้ง (ไม่มีแคช)
     */
    public function reason(LeadRecoveryLog $log, ?object $approval, CarbonImmutable $now): ?string
    {
        // ขาดบอทหรือขาดแถว bot_settings = สวิตช์ไม่เปิดชัดเจน → fail closed ที่เงื่อนไขแรก
        $bot = $log->bot()->first();
        $settings = $bot === null ? null : DB::table('bot_settings')->where('bot_id', $bot->id)->first();

        if (! $settings instanceof stdClass || ! in_array($settings->follow_up_enabled, [true, 1], true)) {
            return self::AUTO_SWITCH_OFF;
        }

        // ทุกบอทวันนี้เก็บ "approve" — ตกข้อนี้เสมอจนกว่าเจ้าของจะเปลี่ยนโหมดเอง
        if (($settings->follow_up_mode ?? null) !== 'automatic') {
            return self::AUTO_MODE_APPROVE;
        }

        // host list ฝั่ง host + อายุ 14 วัน (list ว่าง/ขาด = ไม่มีบอทไหนผ่าน — A2/A12)
        if (FollowUpCapabilities::forBot($bot, $now)['automatic_allowed'] !== true) {
            return self::AUTO_NOT_ALLOWED;
        }

        // pipeline ปล่อยผ่านเฉพาะ G2/G3 (A21(3): กฎนี้ใช้กับร่างที่ถูกผลิตเท่านั้น)
        if (! in_array($log->group_code, [LeadRecoveryLog::GROUP_CODE_G2, LeadRecoveryLog::GROUP_CODE_G3], true)) {
            return self::AUTO_GROUP;
        }

        $minConfidence = config('customer_follow_up.automatic_min_confidence');
        $confidence = self::score($log, 'group_confidence');
        if (! is_numeric($minConfidence) || $confidence === null || $confidence < (float) $minConfidence) {
            return self::AUTO_CONFIDENCE;
        }

        $declinedThreshold = config('customer_follow_up.declined_threshold');
        $declined = self::score($log, 'declined_score');
        if (! is_numeric($declinedThreshold) || $declined === null || $declined >= (float) $declinedThreshold) {
            return self::AUTO_DECLINED;
        }

        // บัตรอนุมัติ: ขาดหายหรือเช็คไม่สะอาด → auto_check (parse ด้วย parser กลางของตัวส่ง ไม่เขียน parser ซ้ำ)
        if ($approval === null || self::checkFlagged($approval)) {
            return self::AUTO_CHECK;
        }

        // ส่งเองได้เฉพาะฉบับร่างตั้งต้น: revision 1, ข้อความเป๊ะร่าง, ไม่ยอมรับข้อห้าม
        if ((int) ($approval->revision ?? 0) !== 1
            || ($approval->text ?? null) !== $log->draft
            || (bool) ($approval->check_overridden ?? true)) {
            return self::AUTO_REVISION;
        }

        // ประตูส่งจริงที่เดิม (08:00-22:00 กรุงเทพฯ, ห้องยังว่าง, ไม่มีข้อความ/ออเดอร์ใหม่ ฯลฯ)
        if (FollowUpEligibility::recheck($log, $now, true) !== null) {
            return self::AUTO_ELIGIBILITY;
        }

        return null;
    }

    /**
     * คะแนนจากคอลัมน์ decimal — อ่านค่าดิบ (ไม่ผ่าน cast ของ Eloquent) เพื่อตัดสินแบบ fail closed:
     * null, boolean, string ไม่เป็นเลข, NaN, INF และนอกช่วง [0,1] ล้วนไม่ผ่าน
     * โมเดลที่ยังไม่เคยถูกเซฟ (original ว่าง) ใช้ค่าที่ cast แล้วแทน
     */
    private static function score(LeadRecoveryLog $log, string $key): ?float
    {
        $missing = new stdClass;
        $raw = $log->getRawOriginal($key, $missing);
        if ($missing === $raw) {
            $raw = $log->getAttribute($key);
        }

        if (! is_numeric($raw)) {
            return null;
        }

        $value = (float) $raw;

        if (! is_finite($value) || $value < 0.0 || $value > 1.0) {
            return null;
        }

        return $value;
    }

    /**
     * เช็คฉบับร่างไม่สะอาดไหม — ต้องตัดสินจาก JSON ดิบเสมอ:
     * cast array ของโมเดล decode แบบ assoc แล้วทำให้ `failed` ที่เป็น object กลายเป็น [] เงียบ ๆ
     * รับได้ทั้ง JSON string ดิบ (แถว query builder) และ array ที่ decode แล้ว (โมเดลที่ยังไม่เคยเซฟ);
     * อ่านไม่ได้ = มีข้อห้าม (fail closed)
     */
    private static function checkFlagged(object $approval): bool
    {
        $raw = $approval instanceof Model ? $approval->getRawOriginal('check_answers') : null;

        if (! is_string($raw)) {
            $raw = $approval->check_answers ?? null;
        }

        if (is_array($raw)) {
            $raw = json_encode($raw);
        }

        return FollowUpSendService::checkFlagged(is_string($raw) ? $raw : '');
    }
}
