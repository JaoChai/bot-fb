<?php

namespace App\Services\SupportRouter;

use RuntimeException;

/**
 * Masks credential VALUES out of the text we send to the Luna Decisions API
 * (Support Router payload only — nothing stored, nothing shown to the chat
 * model, nothing sent to the admin alert changes).
 *
 * Constraints (team task 2026-11-25, "PR ล้างรหัสก่อนส่งไป Luna"):
 *  - values are replaced with bracketed placeholders, so Luna still SEES that
 *    account data was pasted — the support QUESTION explicitly treats "pasted
 *    account credentials/IDs/card numbers" as a post-sale signal;
 *  - credential-free text must come back byte-identical (frozen body fixtures
 *    in SupportRouterScrubbingTest guard this);
 *  - no catastrophic backtracking: every pattern is one greedy character class
 *    over messages of at most ~300 chars (a pathological-input test guards it);
 *  - out of scope on purpose: phone numbers, emails, names.
 *
 * IRON RULE: this class never logs, never stores, never returns the matched
 * value — only counts/exceptions bubble up.
 */
class LunaPayloadScrubber
{
    private const PLACEHOLDER_CODE = '[รหัส]';

    private const PLACEHOLDER_SECRET = '[รหัสลับ]';

    private const PLACEHOLDER_COOKIE = '[คุกกี้]';

    private const PLACEHOLDER_TOKEN = '[โทเคน]';

    private const PLACEHOLDER_CARD = '[เลขบัตร]';

    /**
     * Value-level steps applied in order: [regex, replacement] where the
     * replacement is a string or a callback. Step names exist so the read-only
     * production measurement can attribute changes per type via reflection —
     * they are never logged.
     *
     * @return array<string, array{0: non-empty-string, 1: string|callable}>
     */
    private function valueSteps(): array
    {
        return [
            'cookie' => ['/((?:c_user|xs|datr|fr)\s*=\s*)[^\s&;"\'<>]{1,200}/i', '$1'.self::PLACEHOLDER_COOKIE],
            // base32 (2FA secrets) runs BEFORE the wider mixed-token rule so the
            // specific placeholder wins over [โทเคน]
            'base32' => ['/(?<![A-Za-z0-9])(?:[A-Z2-7] ?){15,39}[A-Z2-7](?![A-Za-z0-9])/', self::PLACEHOLDER_SECRET],
            'token_eaa' => ['/(?<![A-Za-z0-9])EAA[A-Za-z0-9]{5,}(?![A-Za-z0-9])/', self::PLACEHOLDER_TOKEN],
            'token_eyJ' => ['/(?<![A-Za-z0-9_-])eyJ[A-Za-z0-9_-]{15,}(?![A-Za-z0-9_-])/', self::PLACEHOLDER_TOKEN],
            'token_mixed' => [
                '#(?<![A-Za-z0-9])(?=[A-Za-z0-9_\-./=@]{20,})(?=[A-Za-z0-9_\-./=@]*[0-9])(?=[A-Za-z0-9_\-./=@]*[A-Za-z])[A-Za-z0-9_\-./=@]{20,}#',
                self::PLACEHOLDER_TOKEN,
            ],
            'card' => ['/(?<![0-9])[0-9]{4}[- ][0-9]{4}[- ][0-9]{4}(?:[- ][0-9]{1,4})?(?![0-9])/', self::PLACEHOLDER_CARD],
            'otp' => ['/(code|โค้ด|otp|2fa|ยืนยัน)(\s*[:=]?\s*)[0-9]{6,8}(?![0-9])/iu', '$1$2'.self::PLACEHOLDER_CODE],
            'password_value' => [
                '/(รหัสผ่าน|พาสเวิร์ด|password|pass|pwd|pw|พาส|รหัส)(\s*[:=｜|]\s*)[^\s|｜]{1,120}/iu',
                '$1$2'.self::PLACEHOLDER_CODE,
            ],
            // space form: bare "pass" included (QA seq 1464: "pass FakePw1",
            // "ID 12345 pass 67890"); the value must contain an ASCII letter or
            // digit, so plain Thai words after the keyword are not eaten;
            // Latin keywords are word-bounded so "bypass" etc. stay untouched
            'password_value_space' => [
                '/((?<![A-Za-z])(?:password|pwd|pw|pass)(?![A-Za-z])|รหัสผ่าน|พาสเวิร์ด|พาส|รหัส)(\s+)([^\s]{4,120})/iu',
                fn (array $m): string => preg_match('/[A-Za-z0-9]/', $m[3]) === 1
                    ? $m[1].$m[2].self::PLACEHOLDER_CODE
                    : $m[0],
            ],
        ];
    }

    /**
     * Line-level rules (the lessons from the blind-sample misses):
     *  - account data rows: >= 2 separators among | ; tab AND a credential
     *    keyword on the line (a keyword is required so bot prose joined with
     *    "|||" is not destroyed);
     *  - any line where รหัส/บัญชี meets a run of >= 5 digits.
     */
    private function scrubLines(string $text): string
    {
        $lines = explode("\n", $text);

        foreach ($lines as $index => $line) {
            if ($line !== '' && ($this->isCredentialDataRow($line) || $this->hasKeywordWithLongDigitRun($line))) {
                $lines[$index] = self::PLACEHOLDER_CODE;
            }
        }

        return implode("\n", $lines);
    }

    private function isCredentialDataRow(string $line): bool
    {
        if (preg_match_all('#[|;\t]#', $line) < 2) {
            return false;
        }

        return preg_match('/(?:uid|pass|pwd|pw|password|2fa|otp|user|email|mail|ไอดี|ยูสเซอร์|ยูส|รหัส|บัญชี|เมล|โทเคน|token|cookie|คุกกี้)/iu', $line) === 1;
    }

    private function hasKeywordWithLongDigitRun(string $line): bool
    {
        // placeholders inserted by the value rules contain "รหัส" themselves and
        // must not re-trigger this rule on values that were already masked
        $analysis = (string) preg_replace('/\[[^\]]+\]/u', ' ', $line);

        if (preg_match('/(?:รหัส|บัญชี)/u', $analysis) !== 1) {
            return false;
        }

        // QA (seq 1464): a >=5 digit run only counts as credential-like when it
        // is NOT part of dashed bank-transfer formats, a #order number, a
        // postal code, or an amount followed by บาท
        if (preg_match_all('/(?<![-#0-9])([0-9]{5,})(?![-0-9])/', $analysis, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return false;
        }

        foreach ($matches[1] as [$run, $offset]) {
            $after = $offset + strlen($run);
            $charOffset = mb_strlen(substr($analysis, 0, $offset));
            $before = mb_substr($analysis, max(0, $charOffset - 24), min(24, $charOffset));

            if (preg_match('/ไปรษณีย์\s*$/u', $before) === 1) {
                continue; // postal code
            }

            if (preg_match('/^\s?บาท/u', substr($analysis, $after)) === 1) {
                continue; // amount
            }

            return true;
        }

        return false;
    }

    /**
     * @throws RuntimeException when preg_replace fails (never leaks the text)
     */
    public function scrub(string $text, bool $isCustomerMessage = true): string
    {
        if ($text === '') {
            return $text;
        }

        foreach ($this->valueSteps() as [$pattern, $replacement]) {
            $text = is_callable($replacement)
                ? preg_replace_callback($pattern, $replacement, $text)
                : preg_replace($pattern, $replacement, $text);

            if ($text === null) {
                throw new RuntimeException('Luna payload scrub failed: '.preg_last_error_msg());
            }
        }

        // QA (seq 1464): the line-level rules are too blunt for bot context
        // lines (bank transfer formats, postal codes, amounts, order numbers),
        // so they apply to CUSTOMER messages only.
        if ($isCustomerMessage) {
            $text = $this->scrubLines($text);
        }

        return $text;
    }
}
