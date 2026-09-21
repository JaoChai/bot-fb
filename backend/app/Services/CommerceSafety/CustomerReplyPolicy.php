<?php

namespace App\Services\CommerceSafety;

use App\Models\Bot;

/** Pure reply evaluation: configuration is the only destination authority; no I/O. */
final class CustomerReplyPolicy
{
    public const FALLBACK = 'ขอเช็กข้อมูลล่าสุดให้ในแชทนี้ครับ';

    public function mode(Bot $bot): string
    {
        if ((int) $bot->getKey() !== 26) {
            return 'off';
        }
        $mode = config('commerce_safety.bots.26.mode', 'off');

        return in_array($mode, ['off', 'shadow', 'enforce', 'hold'], true) ? $mode : 'hold';
    }

    public function enforced(Bot $bot): bool
    {
        return in_array($this->mode($bot), ['enforce', 'hold'], true);
    }

    public function allowTruthfulAiIdentity(Bot $bot): bool
    {
        return $this->enforced($bot)
            && config("commerce_safety.bots.{$bot->getKey()}.allow_truthful_ai_identity", false) === true;
    }

    /** @return array{urls:list<string>,handles:list<string>} */
    public function allowedContacts(Bot $bot): array
    {
        $contacts = config("commerce_safety.bots.{$bot->getKey()}.reply_contacts", []);

        return [
            'urls' => array_values(array_filter((array) ($contacts['urls'] ?? []), 'is_string')),
            'handles' => array_values(array_filter((array) ($contacts['handles'] ?? []), 'is_string')),
        ];
    }

    /** @return array{content:string,corrected:bool,reasons:list<string>} */
    public function apply(Bot $bot, string $content): array
    {
        $reason = $this->mode($bot) === 'off' ? null : $this->rejectionReason($bot, $content);
        $corrected = $reason !== null && $this->enforced($bot);

        return [
            'content' => $corrected ? self::FALLBACK : $content,
            'corrected' => $corrected,
            'reasons' => $reason === null ? [] : [$reason],
        ];
    }

    private function rejectionReason(Bot $bot, string $content): ?string
    {
        // Do not decode or repair an obfuscated destination into a trusted one.
        if (preg_match('/(?:%[0-9a-f]{2}|&#(?:x[0-9a-f]+|[0-9]+);|&(?:commat|period|colon|sol);)/i', $content) === 1) {
            return 'contact_obfuscation';
        }
        if (preg_match('/[\\\\\p{Cf}\x00-\x09\x0B-\x1F\x7F＠﹫：／∕⁄。．｡․]/u', $content) !== 0) {
            return 'contact_obfuscation';
        }
        if (preg_match('/(?:\bline[\s_-]*id\b|ไลน์\s*(?:ไอ)?ดี|ไลน์\s*ไอดี)\s*[:=：]?\s*\S/iu', $content) === 1) {
            return 'contact_line_id';
        }
        // Email is never an allowed handle, even if its suffix is allowlisted.
        if (preg_match('/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}]/u', $content) === 1) {
            return 'contact_email';
        }

        // Bubble separators are inspection boundaries; preserve original reply bytes.
        $content = str_replace('|||', ' ', $content);
        $contacts = $this->allowedContacts($bot);
        $allowedUrls = array_filter(array_map($this->normalizeUrl(...), $contacts['urls']));
        $remaining = $content;
        preg_match_all('~[a-z][a-z0-9+.-]*://[^\s<>\[\]"`]+~iu', $content, $urls, PREG_OFFSET_CAPTURE);
        foreach ($urls[0] as [$url, $offset]) {
            // Only a Markdown link's closing parenthesis is syntax. Parentheses
            // and apostrophes in bare URLs (and nested Markdown paths) are data.
            if (substr($content, max(0, $offset - 2), 2) === '](') {
                $depth = 0;
                for ($i = 0, $length = strlen($url); $i < $length; $i++) {
                    if ($url[$i] === '(') {
                        $depth++;
                    } elseif ($url[$i] === ')') {
                        if ($depth === 0) {
                            $url = substr($url, 0, $i);
                            break;
                        }
                        $depth--;
                    }
                }
            }
            $normalized = $this->normalizeUrl($url);
            if ($normalized === null || ! in_array($normalized, $allowedUrls, true)) {
                return 'contact_url';
            }
            $remaining = str_replace($url, '', $remaining);
        }

        // No IP address is an approved destination, including schemeless IP links.
        if (preg_match('/(?:[0-9]{1,3}\.){3}[0-9]{1,3}|\[[0-9a-f]*:[0-9a-f:]+\]/i', $remaining) === 1) {
            return 'contact_schemeless';
        }
        // Includes schemeless domains, protocol-relative URLs and Unicode lookalikes.
        if (preg_match('~(?:[\p{L}\p{N}_-]+\.)+[\p{L}][\p{L}\p{N}-]*|://|\b(?:https?|mailto):~iu', $remaining) === 1) {
            return 'contact_schemeless';
        }
        preg_match_all('/@[^\s<>\[\]()"\'`,!]+/u', $remaining, $handles);
        foreach ($handles[0] as $handle) {
            if (! in_array($handle, $contacts['handles'], true)) {
                return 'contact_handle';
            }
        }
        if (str_contains(str_replace($handles[0], '', $remaining), '@')) {
            return 'contact_handle';
        }

        return null;
    }

    private function normalizeUrl(string $url): ?string
    {
        if (preg_match('/[%\\\\\x00-\x20\x7F]/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host'])
            || array_intersect(['user', 'pass', 'port', 'query', 'fragment'], array_keys($parts)) !== []) {
            return null;
        }

        return 'https://'.strtolower($parts['host']).($parts['path'] ?? '');
    }
}
