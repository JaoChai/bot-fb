<?php

namespace App\Services;

use App\Models\ProductStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Post-generation guard that validates LLM responses against current stock status.
 *
 * If the LLM tries to sell an out-of-stock product (despite prompt instructions),
 * this guard replaces the response with a stock-out message.
 */
class StockGuardService
{
    /** ราคาที่ห่างจากชื่อสินค้าไม่เกินนี้ (ตัวอักษร) ถือว่าพูดถึงคู่กัน */
    private const PRICE_PROXIMITY = 60;

    public function __construct(
        protected StockInjectionService $stockInjection
    ) {}

    /**
     * Validate a bot response against current stock status.
     *
     * @return array{content: string, blocked: bool, blocked_products: array}
     */
    public function validate(string $response, string $userMessage = ''): array
    {
        if (! config('rag.stock_guard.enabled', true)) {
            return ['content' => $response, 'blocked' => false, 'blocked_products' => []];
        }

        $outOfStock = $this->stockInjection->getOutOfStockProducts();
        if ($outOfStock->isEmpty()) {
            return ['content' => $response, 'blocked' => false, 'blocked_products' => []];
        }

        // Cap response length for regex to prevent ReDoS on very long LLM output.
        // Selling keywords (price, cart, payment) always appear in the first portion.
        $responseToCheck = mb_substr($response, 0, 2000);

        // map: ชื่อสินค้า => ปิดการขายจริงไหม (true = ตะกร้า/สรุปยอด/โอนเงิน)
        $violations = $this->detectViolations($responseToCheck, $outOfStock);

        if (empty($violations)) {
            return ['content' => $response, 'blocked' => false, 'blocked_products' => []];
        }

        $products = array_keys($violations);

        // Check if ALL violations are upsell-only (not main product being sold)
        $allUpsell = $this->areAllViolationsUpsell($responseToCheck, $products, $outOfStock);

        if ($allUpsell) {
            Log::info('StockGuard: stripped upsell for out-of-stock products', [
                'violations' => $products,
                'user_message' => mb_substr(str_replace(["\n", "\r"], ' ', $userMessage), 0, 200),
            ]);

            $stripped = $this->stripUpsellBlock($response, $products, $outOfStock);

            return ['content' => $stripped, 'blocked' => false, 'blocked_products' => []];
        }

        // เอ่ยชื่อ/ราคาเฉยๆ ไม่ได้ปิดการขาย → ห้ามทิ้งคำตอบของลูกค้า แค่ต่อท้ายว่าหมด
        // (prompt อนุญาตให้ตอบราคา/รายละเอียดได้ ขอแค่แจ้งว่าหมดชั่วคราวด้วย)
        if (! in_array(true, $violations, true)) {
            Log::info('StockGuard: appended out-of-stock notice', [
                'violations' => $products,
                'user_message' => mb_substr(str_replace(["\n", "\r"], ' ', $userMessage), 0, 200),
            ]);

            return [
                'content' => $this->appendStockNotice($response, $products),
                'blocked' => false,
                'blocked_products' => [],
            ];
        }

        Log::warning('StockGuard: blocked out-of-stock sale', [
            'violations' => $products,
            'original_response_preview' => mb_substr($response, 0, 300),
            'user_message' => mb_substr(str_replace(["\n", "\r"], ' ', $userMessage), 0, 200),
        ]);

        return [
            'content' => $this->buildReplacementResponse($products),
            'blocked' => true,
            'blocked_products' => $products,
        ];
    }

    /**
     * Detect if response is SELLING (not just mentioning) out-of-stock products.
     *
     * ตัดสินใจ "ต่อประโยค (clause)" ไม่ใช่ต่อหน้าต่าง 40-60 ตัวอักษรทั้งข้อความ —
     * คำขายของสินค้าอื่นหรือคำโอน/ชำระของลูกค้าเองที่อยู่ไกลๆ ต้องไม่ทำให้
     * ประโยคที่แค่เอ่ยถึง/ปฏิเสธสินค้าหมดถูกบล็อกทั้งก้อน (false positive 8 เคสจริงใน prod)
     *
     * @return array<string, bool> ชื่อสินค้า => ปิดการขายจริงไหม (false = แค่เอ่ยชื่อ/ราคา)
     */
    protected function detectViolations(string $response, Collection $outOfStock): array
    {
        $violations = [];
        $clauses = $this->splitClauses($response);

        // Payment instructions (STEP 4) list products as order line items,
        // not as selling recommendations. Skip guard for those.
        $isPayment = $this->isPaymentInstruction($response);

        foreach ($outOfStock as $product) {
            $names = array_merge([$product->name], $product->aliases ?? []);
            $strong = false;
            $strongIsPriceOnly = true;
            $mentioned = false;

            foreach ($names as $name) {
                if (mb_strlen($name) < 2) {
                    continue;
                }

                foreach ($clauses as $clause) {
                    $text = $clause['text'];

                    if (mb_stripos($text, $name) === false) {
                        continue;
                    }

                    $kind = $this->strongSellingKind($text, $clause['offset'], $response, $name, $product->name);

                    // ประโยคที่เอ่ยชื่อในบริบทปฏิเสธ/ไม่เอา/ยกเลิก/บัญชีลูกค้าเอง ไม่นับเป็นการขาย —
                    // ยกเว้น "ตะกร้า" ซึ่งเป็นการกระทำตรงๆ (bot ใส่ของหมดลงตะกร้า = ต้องบล็อก)
                    if ($kind !== 'cart' && $this->isExcludedClause($text, $name)) {
                        continue;
                    }

                    // ใน payment instruction ชื่อสินค้าคือ line item ไม่ใช่การขาย
                    if ($kind !== null && $isPayment && $this->isOrderLineItem($response, $name)) {
                        continue;
                    }

                    if ($kind !== null) {
                        $strong = true;
                        if ($kind !== 'price') {
                            $strongIsPriceOnly = false;
                        }
                    } else {
                        // เอ่ยชื่อในประโยคที่ไม่มีสัญญาณขายชัดเจน (เช่น แค่คำว่าโอน/ชำระเงิน)
                        $mentioned = true;
                    }
                }
            }

            if (! $strong && ! $mentioned) {
                continue;
            }

            // บอกราคาอย่างเดียว (ไม่มีจำนวน/ตะกร้า/สรุปยอด/เสนอขาย) = การตอบแบบให้ข้อมูล:
            // ถ้ามีการบอกว่าหมดที่ไหนสักแห่งในข้อความ → ปล่อยผ่านทั้งข้อความ
            // ถ้าไม่มี → คงคำตอบไว้แล้วเติมแจ้งเตือนท้ายข้อความ
            if ($strong && $strongIsPriceOnly) {
                if ($this->isRefusedSomewhere($response, $names)) {
                    continue;
                }

                $violations[$product->name] = false;

                continue;
            }

            $violations[$product->name] = $strong;
        }

        return $violations;
    }

    /**
     * แบ่งข้อความเป็นประโยค (clause): ขึ้นบรรทัดใหม่, . ! ?,
     * หรือ ครับ/ค่ะ/คะ ตามด้วยช่องว่างหรือจบข้อความ
     *
     * @return array<int, array{text: string, offset: int}> offset = ตำแหน่งตัวอักษร (mb) ในข้อความเดิม
     */
    protected function splitClauses(string $response): array
    {
        $parts = preg_split(
            '/\n+|\s*[.!?]+\s*|(?<=ครับ)\s+|(?<=ค่ะ)\s+|(?<=คะ)\s+/u',
            $response,
            -1,
            PREG_SPLIT_OFFSET_CAPTURE
        );

        if ($parts === false) {
            return [['text' => $response, 'offset' => 0]];
        }

        $clauses = [];
        foreach ($parts as [$text, $byteOffset]) {
            $text = trim($text);
            if ($text !== '') {
                $clauses[] = [
                    'text' => $text,
                    'offset' => mb_strlen(substr($response, 0, $byteOffset)),
                ];
            }
        }

        return $clauses === [] ? [['text' => $response, 'offset' => 0]] : $clauses;
    }

    /**
     * ประโยคที่เอ่ยชื่อสินค้าในบริบท "ไม่ได้ขาย" — คำปฏิเสธ/ไม่เอา/ยกเลิก
     * ต้องอยู่ใกล้ชื่อจริงๆ จึงนับว่าหมายถึงสินค้าตัวนี้:
     * ชื่อ → คำปฏิเสธ ห่างกันไม่เกิน ~12 ตัวอักษร ("BM ตอนนี้หมด"),
     * คำปฏิเสธ → ชื่อ ห่างกันไม่เกิน ~16 ("ไม่จำเป็นต้องมี BM",
     * "ยกเลิกรายการ BM", "ไม่ใช่บัญชียิงแอดแบบ BM")
     * — หน้าต่างกว้างกว่านี้จะกินคำปฏิเสธของสิ่งอื่นในประโยคเดียวกัน
     * เช่น "BM 2 ตัว รวม 2,200 บาท ไม่มีค่าส่ง" (ไม่มี = ค่าส่ง ไม่ใช่ BM)
     * (ประโยคเดียวกันอาจมีทั้ง "BM หมด" และการขาย Page — ต้องไม่ปิด Page ด้วย)
     */
    protected function isExcludedClause(string $clause, string $name): bool
    {
        $quoted = preg_quote($name, '/');
        // "ทั้งหมด" means total/all, not a refusal to sell.
        $refusal = '(?<!ทั้ง)หมด|ไม่มี|ไม่สามารถ|ปิดการขาย|ยกเลิก|ไม่ต้อง|ไม่จำเป็น|ไม่ใช่|out\s*of\s*stock';

        $patterns = [
            // บอกว่าหมด/ไม่มีขาย/ไม่เอา/ยกเลิก ใกล้ชื่อสินค้าเท่านั้น
            "/{$quoted}.{0,12}(?:{$refusal})/iu",
            "/(?:{$refusal}).{0,16}{$quoted}/iu",
            // ชื่อสินค้า = บัญชี/ID ของลูกค้าเอง (เข้า BM, BM ของพี่, BM ID, BM =156…)
            "/เข้า\s*{$quoted}/iu",
            "/{$quoted}\s*ของ\s*(?:พี่|คุณ|ผม|เรา|ลูกค้า)/iu",
            "/{$quoted}\s*(?:ID|ไอดี)/iu",
            "/{$quoted}\s*[=:]\s*\d/iu",
            // ตัวเลขยาว >= 10 หลักใกล้ชื่อ = ID/เลขบัญชี ไม่ใช่ราคาหรือจำนวน
            "/{$quoted}.{0,30}\d{10,}/iu",
            "/\d{10,}.{0,30}{$quoted}/iu",
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $clause)) {
                return true;
            }
        }

        return false;
    }

    /**
     * สัญญาณ "กำลังขายจริง" ในประโยคเดียวกับชื่อสินค้า — คืนชนิดของสัญญาณ
     * ('summary'|'qty'|'cart'|'offer'|'price') หรือ null ถ้าไม่มี
     *
     * คำโอน/ชำระ/จ่าย/QR/พร้อมเพย์เฉยๆ ไม่ใช่สัญญาณขาย — ขายจริงต้องมี
     * จำนวน/ราคาของสินค้าตัวนี้, ตะกร้า, สรุปยอด, หรือการเสนอขาย ในประโยคเดียวกัน
     *
     * @param  string  $clauseText  ข้อความของประโยค
     * @param  int  $clauseOffset  ตำแหน่ง (mb) เริ่มประโยคใน $response
     */
    protected function strongSellingKind(string $clauseText, int $clauseOffset, string $response, string $name, string $ownerName): ?string
    {
        $quoted = preg_quote($name, '/');

        // สรุป/ยอดรวมที่มีชื่อสินค้า นับเป็นการปิดการขายเมื่อมีตัวเลข (จำนวน/ราคา) ด้วย
        if ((preg_match("/(?:สรุป|รวม|ยอด).{0,40}{$quoted}/iu", $clauseText)
                || preg_match("/{$quoted}.{0,40}(?:สรุป|รวม[:=])/iu", $clauseText))
            && preg_match('/\d/u', $clauseText) === 1) {
            return 'summary';
        }

        $checks = [
            'qty' => [
                // จำนวนของสินค้าตัวนี้เอง ต้องอยู่ติดกับชื่อ (ห่างไม่เกิน ~3 ตัวอักษร):
                // "BM 2 ตัว", "BM x2", "BM x 1" — ห่างกว่านี้คือจำนวนของอย่างอื่น
                // เช่น "BM สร้างเพจได้ 50 เพจ" (นับจำนวนเพจ ไม่ใช่จำนวน BM)
                "/{$quoted}.{0,3}(?:x\s*\d+|\d+\s*(?:ตัว|เพจ|ชุด|อัน|บิล|ไลน์|หน้า))/iu",
                "/{$quoted}.{0,3}(?:x\d|จำนวน)/iu",
            ],
            'cart' => [
                '/(?:เพิ่ม|ลง).{0,20}(?:ตะกร้า|cart|รถเข็น)/iu',
            ],
            'offer' => [
                "/(?:แนะนำ|เสนอ|สนใจ).{0,30}{$quoted}/iu",
                "/รับ.{0,10}{$quoted}.{0,10}(?:ไหม|มั้ย|ด้วย|กี่)/iu",
                "/{$quoted}.{0,30}(?:ด้วยไหม|ดีไหม|สนใจไหม|เพิ่มไหม)/iu",
            ],
        ];

        foreach ($checks as $kind => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $clauseText)) {
                    return $kind;
                }
            }
        }

        $hasOwnPrice = $this->hasOwnPriceInClause($clauseText, $clauseOffset, $response, $name, $ownerName);

        // คำเชียร์ขาย (พร้อมส่ง/ส่งทันที/โอนแล้วส่ง) นับเป็นการเสนอขายเฉพาะเมื่อ
        // ราคาในประโยคเป็นราคาของสินค้าตัวนี้เอง — ไม่งั้นมันเป็นแค่ pitch
        // ของสินค้าอื่นในประโยคเดียวกัน เช่น "Page ราคา 199 บาท ใช้กับ BM ได้ พร้อมส่ง"
        if (preg_match('/(?:พร้อมส่ง|ส่งทันที|โอนแล้วส่ง)/iu', $clauseText) === 1 && $hasOwnPrice) {
            return 'offer';
        }

        // ราคาในประโยคเดียวกับชื่อ (ห่างไม่เกิน 60 ตัวอักษร) — แต่ "ราคานี้เป็นของใคร"
        // ยังตัดสินจากทั้งข้อความ เพื่อไม่เอาราคาของสินค้าอื่นที่อยู่ประโยคข้างๆ มาให้ตัวนี้
        if ($hasOwnPrice) {
            return 'price';
        }

        return null;
    }

    /**
     * ราคาในประโยคนี้เป็นราคาของสินค้าตัวนี้เองหรือไม่ — ชื่ออยู่ใกล้ราคา
     * (≤ 60 ตัวอักษร) และจุดราคานั้นอยู่ใกล้ชื่อสินค้าตัวนี้ที่สุดเมื่อเทียบ
     * ทุกสินค้าในระบบ (กันราคาของสินค้าอื่นในประโยคเดียวกัน)
     */
    protected function hasOwnPriceInClause(string $clauseText, int $clauseOffset, string $response, string $name, string $ownerName): bool
    {
        $namePositions = $this->positionsOf($clauseText, $name);
        $nameLength = mb_strlen($name);

        foreach ($this->pricePositions($clauseText) as $pricePos) {
            foreach ($namePositions as $namePos) {
                if ($this->gap($namePos, $nameLength, $pricePos) > self::PRICE_PROXIMITY) {
                    continue;
                }

                if ($this->nearestProductAt($response, $clauseOffset + $pricePos) === $ownerName) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * สินค้านี้ถูกบอกว่าหมด/ปฏิเสธที่ไหนสักแห่งในข้อความหรือไม่ (ดูทั้งข้อความ —
     * "บอกราคาแล้วบอกหมดในประโยคถัดไป" เป็นการตอบแบบให้ข้อมูล ไม่ใช่การขาย)
     *
     * @param  array<int, string>  $names
     */
    protected function isRefusedSomewhere(string $response, array $names): bool
    {
        foreach ($names as $name) {
            if (mb_strlen($name) >= 2 && $this->isRefusingContext($response, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the response is correctly REFUSING to sell a SPECIFIC product.
     *
     * Refusal keywords must appear within proximity of the product name,
     * not just anywhere in the response — prevents bypass when LLM refuses
     * one product but sells another in the same message.
     */
    protected function isRefusingContext(string $response, string $productName): bool
    {
        $quotedName = preg_quote($productName, '/');

        $refusalPatterns = [
            // Refusal keyword near product name (within 40 chars)
            "/{$quotedName}.{0,40}((?<!ทั้ง)หมด|ไม่มี|ไม่สามารถ|ปิดการขาย|out.of.stock)/iu",
            "/((?<!ทั้ง)หมด|ไม่มี|ไม่สามารถ|ปิดการขาย|out.of.stock).{0,40}{$quotedName}/iu",
            // "สินค้าXXXหมด" pattern
            "/สินค้า.{0,10}{$quotedName}.{0,10}((?<!ทั้ง)หมด|ไม่มี)/iu",
            "/{$quotedName}.{0,10}(?<!ทั้ง)หมด(ชั่วคราว|stock|สต็อก|แล้ว)/iu",
        ];

        foreach ($refusalPatterns as $pattern) {
            if (preg_match($pattern, $response)) {
                return true;
            }
        }

        return false;
    }

    /** ตำแหน่ง (นับเป็นตัวอักษร) ของทุกครั้งที่เจอ $needle */
    protected function positionsOf(string $haystack, string $needle): array
    {
        $positions = [];
        $offset = 0;

        while (($pos = mb_stripos($haystack, $needle, $offset)) !== false) {
            $positions[] = $pos;
            $offset = $pos + 1;
        }

        return $positions;
    }

    /** ตำแหน่งของราคาทุกจุดในข้อความ (ตัวเลข 3 หลักขึ้นไป หรือหน่วยเงิน) */
    protected function pricePositions(string $response): array
    {
        if (! preg_match_all('/\d{3,}|บาท|฿/u', $response, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        return array_map(
            fn ($hit) => mb_strlen(substr($response, 0, $hit[1])),
            $matches[0]
        );
    }

    /** ระยะห่างระหว่างชื่อสินค้ากับราคา (0 = ราคาอยู่ในชื่อ) */
    protected function gap(int $namePos, int $nameLength, int $pricePos): int
    {
        if ($pricePos >= $namePos && $pricePos <= $namePos + $nameLength) {
            return 0;
        }

        return $pricePos > $namePos
            ? $pricePos - ($namePos + $nameLength)
            : $namePos - $pricePos;
    }

    /** สินค้าที่ชื่อ/alias อยู่ใกล้ตำแหน่งนี้ที่สุด (ดูสินค้าทุกตัว ไม่ใช่แค่ตัวที่หมด) */
    protected function nearestProductAt(string $response, int $position): ?string
    {
        $nearest = null;
        $shortest = PHP_INT_MAX;

        foreach ($this->stockInjection->getStockStatus() as $product) {
            foreach (array_merge([$product->name], $product->aliases ?? []) as $name) {
                if (mb_strlen($name) < 2) {
                    continue;
                }

                foreach ($this->positionsOf($response, $name) as $namePos) {
                    $distance = $this->gap($namePos, mb_strlen($name), $position);
                    if ($distance < $shortest) {
                        $shortest = $distance;
                        $nearest = $product->name;
                    }
                }
            }
        }

        return $nearest;
    }

    /**
     * Check if the response is a payment instruction (STEP 4).
     *
     * Payment instructions contain bank account details and are finalizing
     * an already-confirmed order — product names here are line items, not sales.
     */
    protected function isPaymentInstruction(string $response): bool
    {
        return mb_strpos($response, '223-3-24880-3') !== false
            || mb_strpos($response, '2233248803') !== false;
    }

    /**
     * Check if the product name appears as an order line item,
     * not as a standalone selling recommendation.
     */
    protected function isOrderLineItem(string $response, string $productName): bool
    {
        $quotedName = preg_quote($productName, '/');

        $lineItemPatterns = [
            // Numbered list: "2. บริการเสริม Page 199 บาท"
            '/(?:^|\n)\s*\d+[\.\)]\s*(?:.*?)'.$quotedName.'/imu',
            // Bulleted list: "- Page 199 บาท"
            '/(?:^|\n)\s*[-•]\s*(?:.*?)'.$quotedName.'/imu',
            // Zero-price: "Page = 0 บาท"
            '/'.$quotedName.'\s*[=:]\s*0\s*บาท/iu',
        ];

        foreach ($lineItemPatterns as $pattern) {
            if (preg_match($pattern, $response)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if ALL violations are in upsell-only context (not main cart items).
     */
    protected function areAllViolationsUpsell(string $response, array $violations, Collection $outOfStock): bool
    {
        foreach ($violations as $productName) {
            $product = $outOfStock->firstWhere('name', $productName);
            if (! $product || ! $this->isUpsellContext($response, $product)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if an out-of-stock product appears ONLY in an upsell question,
     * not as a main cart item or direct sale.
     */
    protected function isUpsellContext(string $response, ProductStock $product): bool
    {
        $names = array_merge([$product->name], $product->aliases ?? []);

        foreach ($names as $name) {
            if (mb_strlen($name) < 2 || mb_stripos($response, $name) === false) {
                continue;
            }

            $quotedName = preg_quote($name, '/');

            // Must match upsell question patterns
            $upsellPatterns = [
                "/รับ.{0,20}{$quotedName}.{0,30}(?:เพิ่ม|ด้วย)/iu",
                "/{$quotedName}.{0,20}(?:เพิ่มไหม|ด้วยไหม|สนใจไหม)/iu",
            ];

            $hasUpsell = false;
            foreach ($upsellPatterns as $pattern) {
                if (preg_match($pattern, $response)) {
                    $hasUpsell = true;
                    break;
                }
            }

            if (! $hasUpsell) {
                return false;
            }

            // Must NOT appear as a main cart line item
            $cartPattern = '/(?:^|\n)\s*[-•]\s*'.$quotedName.'\s*x?\d/imu';
            if (preg_match($cartPattern, $response)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Strip upsell block for out-of-stock products from response,
     * preserving the main cart content.
     */
    protected function stripUpsellBlock(string $response, array $violations, Collection $outOfStock): string
    {
        $stripped = $response;

        foreach ($violations as $productName) {
            $product = $outOfStock->firstWhere('name', $productName);
            $names = array_merge([$productName], $product->aliases ?? []);
            $quotedNames = array_map(fn ($n) => preg_quote($n, '/'), array_filter($names, fn ($n) => mb_strlen($n) >= 2));
            $namePattern = implode('|', $quotedNames);

            if (empty($namePattern)) {
                continue;
            }

            // Remove "รับ [product] เพิ่มไหม..." line(s) through end or "พอแล้ว" line
            $stripped = preg_replace(
                '/\n*รับ.{0,20}(?:'.$namePattern.').+(?:\n(?!(?:ตะกร้า|เพิ่ม\s|สรุป|รวม|📌|\d+\.)).+)*/iu',
                '',
                $stripped
            ) ?? $stripped;
        }

        // Remove leftover "ถ้าไม่รับพิมพ์ 'พอแล้ว'" line
        $stripped = preg_replace('/\n*.*?(?:ถ้าไม่รับ|ไม่รับพิมพ์).*?พอแล้ว.*$/imu', '', $stripped) ?? $stripped;

        $stripped = rtrim($stripped);

        // Append a closing prompt if response now ends abruptly (no question/period)
        if (! preg_match('/(ครับ|ค่ะ|เลย|ได้|ไหม|มั้ย|[?？])\s*$/u', $stripped)) {
            $stripped .= "\n\nถูกต้องไหมครับ? พิมพ์ 'ยืนยัน' ได้เลยครับ";
        }

        return $stripped;
    }

    /** ต่อท้ายว่าสินค้าหมด โดยไม่ทิ้งคำตอบเดิมที่ลูกค้าถามมา */
    protected function appendStockNotice(string $response, array $products): string
    {
        return rtrim($response)."\n\nตอนนี้ ".implode(', ', $products).' หมดสต็อกชั่วคราวครับ';
    }

    /**
     * Build a replacement response when violation is detected.
     *
     * ไม่เสนอสินค้าทดแทนเอง — สินค้าตัวไหนใช้แทนกันได้เป็นเรื่องที่ prompt รู้ ไม่ใช่ guard
     * (เคยเสนอ "ทุกตัวที่มีของ" แล้วไปเชียร์ G3D ให้ลูกค้าที่จะยิงแอด ซึ่ง G3D ยิงแอดไม่ได้)
     */
    protected function buildReplacementResponse(array $violations): string
    {
        $violationList = implode(', ', $violations);

        return "ขออภัยครับ ขณะนี้ {$violationList} หมด stock ชั่วคราว ไม่สามารถสั่งซื้อได้ครับ"
            ."\n\nหากสนใจสินค้าอื่น หรือต้องการให้แจ้งเมื่อสินค้ากลับมา สามารถบอกได้เลยครับ";
    }
}
