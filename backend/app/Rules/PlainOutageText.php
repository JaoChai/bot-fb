<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class PlainOutageText implements ValidationRule
{
    /** ตัวแปร template {name} / {{name}} / ${name} — ตัวเปิด ${ หรือวงเล็บปีกมีเนื้อความในบรรทัดเดียว */
    private const TEMPLATE_TOKEN_REGEX = '/\$\{|\{[^{}\r\n]*\}/u';

    /**
     * ข้อความบอทล่มต้องเป็นข้อความธรรมดา (Lead D1): ปฏิเสธแท็ก HTML ที่ยังหลงเหลือ
     * (global sanitizer ทำงานก่อนถึงกฎนี้แล้ว) และปฏิเสธ brace template — ไม่เขียนทับ
     * ข้อความของเจ้าของเงียบๆ แต่ตอบ 422 ให้แก้เอง
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // nullable จัดการค่าว่างแล้ว; rule วิ่งหลัง string rule แต่ยังต้องกันชนิดข้อมูลเอง
        if ($value === null) {
            return;
        }

        if (! is_string($value) || strip_tags($value) !== $value || preg_match(self::TEMPLATE_TOKEN_REGEX, $value) === 1) {
            $fail('ใช้ได้เฉพาะข้อความธรรมดา ห้ามใส่แท็ก HTML หรือ {ตัวแปร}');
        }
    }
}
