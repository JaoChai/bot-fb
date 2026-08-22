# Purchase History Context Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ให้บอทรู้ว่าลูกค้าเคยซื้ออะไรไปเมื่อไหร่ (ออเดอร์ล่าสุด 1 ใบใน 90 วัน) โดยดึงจากตาราง `orders`
จริงตอนประกอบ prompt — ไม่พึ่ง LLM จด ไม่ต้อง backfill ไม่ต้องมีตัวล้างของหมดอายุ

**Architecture:** เพิ่มเมธอด `buildPurchaseHistoryBlock()` ใน `RAGService` ที่ query ออเดอร์ล่าสุด
แล้วคืนบล็อกข้อความ (หรือสตริงว่างถ้าไม่มี) · `generateResponse()` เรียกเมธอดนี้ **ครั้งเดียว** ที่ต้น
ฟังก์ชัน แล้วส่งผลลัพธ์เป็น argument ต่อให้ `shouldSkipCache()` (Step 0) และ `buildEnhancedPrompt()`
(Step 6) · ทุกช่องทาง (LINE/Telegram/Facebook, มี/ไม่มี history) วิ่งผ่าน `RAGService::generateResponse()`
จุดเดียว จึงแก้ไฟล์เดียวครอบคลุมหมด

**Tech Stack:** Laravel 13 · PHP 8.3 · PHPUnit (`Tests\TestCase` + `RefreshDatabase`) · PostgreSQL (Neon)

## Global Constraints

- ข้อมูลที่จำ: สินค้า + จำนวน + วันที่ ของออเดอร์ล่าสุด **1 ใบเท่านั้น**
- ย้อนหลังได้ไม่เกิน **90 วัน**
- เกณฑ์ออเดอร์: `status = 'completed'` (เกณฑ์เดียวกับ `VipDetectionService::getTopItems()`)
- บอท **ห้ามเอ่ยถึงประวัติก่อนลูกค้าพูดถึงเอง** และห้ามใช้ทักทาย
- **กฎสต็อกชนะประวัติเสมอ** — ของที่เคยซื้อหมด ต้องเสนอตัวแทนตามกฎเดิม
- ความจำเป็นของเสริม: query ล้มเหลวห้ามทำให้บอทตอบลูกค้าไม่ได้ (จับ exception เสมอ)
- **ห้าม query ซ้ำ** — ดึงครั้งเดียวใน `generateResponse()` แล้วส่งต่อเป็น argument
- ห้ามแตะ `memory_notes` และห้ามแตะ `VipDetectionService`
- timezone แอปเป็น `Asia/Bangkok` ตรงกับที่ DB เก็บ ใช้ `now()->subDays(90)` ได้ตรงๆ
- signature ที่เพิ่มใหม่ **ต้องมี default value** เพื่อไม่ให้เทสต์เดิม 25 ตัวใน `RAGServiceTest` พัง

## File Structure

| ไฟล์ | หน้าที่ | สถานะ |
|---|---|---|
| `backend/app/Services/RAGService.php` | เพิ่ม `buildPurchaseHistoryBlock()` + ต่อสายเข้า 2 จุดเดิม | แก้ไข |
| `backend/tests/Unit/Services/RAGServiceTest.php` | เทสต์เมธอดใหม่ + การต่อสาย | แก้ไข |
| `backend/config/prompt-eval-cases.php` | 2 เคส regression กับบอทจริง | แก้ไข |

⚠️ **`RAGService` ถูก bind เป็น singleton** (`AppServiceProvider.php:62`) — ห้ามเก็บผลลัพธ์ประวัติลูกค้า
ไว้ใน property ของ service เด็ดขาด เพราะ queue worker รันหลาย job ต่อ process เดียว ข้อมูลจะรั่วข้ามลูกค้า
แผนนี้จึงส่งค่าเป็น argument ทุกจุด

---

### Task 1: เมธอดดึงประวัติการซื้อ

**Files:**
- Modify: `backend/app/Services/RAGService.php`
- Test: `backend/tests/Unit/Services/RAGServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\Order`, `App\Models\Conversation` (มีอยู่แล้ว)
- Produces: `protected function buildPurchaseHistoryBlock(?Conversation $conversation): string`
  คืนบล็อกข้อความพร้อมฉีด หรือ `''` เมื่อไม่มีข้อมูล/เกิดข้อผิดพลาด

- [ ] **Step 1: เขียนเทสต์ที่ต้องแดงก่อน**

เพิ่มใน `backend/tests/Unit/Services/RAGServiceTest.php` — เพิ่ม `use` ที่ยังไม่มีด้านบนไฟล์ด้วย
(`App\Models\CustomerProfile`, `App\Models\Order`, `App\Models\OrderItem`)

```php
    /**
     * Helper to call protected buildPurchaseHistoryBlock method.
     */
    private function callBuildPurchaseHistoryBlock(?Conversation $conversation): string
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('buildPurchaseHistoryBlock');
        $method->setAccessible(true);

        return $method->invoke($this->service, $conversation);
    }

    public function test_purchase_history_block_uses_latest_order_within_90_days(): void
    {
        $customer = CustomerProfile::factory()->create();
        $conversation = Conversation::factory()->create([
            'customer_profile_id' => $customer->id,
        ]);

        $older = Order::factory()->create([
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
            'created_at' => now()->subDays(40),
        ]);
        OrderItem::factory()->create([
            'order_id' => $older->id,
            'product_name' => 'Facebook ไก่ G3D',
            'variant' => null,
            'quantity' => 10,
        ]);

        $latest = Order::factory()->create([
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
            'created_at' => now()->subDays(7),
        ]);
        OrderItem::factory()->create([
            'order_id' => $latest->id,
            'product_name' => 'Nolimit Level Up+ Personal',
            'variant' => 'ผูกบัตร',
            'quantity' => 3,
        ]);

        $block = $this->callBuildPurchaseHistoryBlock($conversation);

        $this->assertStringContainsString('Nolimit Level Up+ Personal', $block);
        $this->assertStringContainsString('(ผูกบัตร)', $block);
        $this->assertStringContainsString('x3', $block);
        // ต้องเป็นออเดอร์ล่าสุดใบเดียว ห้ามมีของใบเก่าปน
        $this->assertStringNotContainsString('G3D', $block);
        // คำสั่งกำกับพฤติกรรมต้องติดไปกับบล็อกเสมอ
        $this->assertStringContainsString('ห้ามเอ่ยถึงประวัตินี้ก่อน', $block);
        $this->assertStringContainsString('หมดสต็อก', $block);
    }

    public function test_purchase_history_block_ignores_orders_older_than_90_days(): void
    {
        $customer = CustomerProfile::factory()->create();
        $conversation = Conversation::factory()->create([
            'customer_profile_id' => $customer->id,
        ]);

        $stale = Order::factory()->create([
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
            'created_at' => now()->subDays(91),
        ]);
        OrderItem::factory()->create([
            'order_id' => $stale->id,
            'product_name' => 'Nolimit Level Up+ BM',
            'quantity' => 1,
        ]);

        $this->assertSame('', $this->callBuildPurchaseHistoryBlock($conversation));
    }

    public function test_purchase_history_block_is_empty_without_customer_profile(): void
    {
        $conversation = Conversation::factory()->create([
            'customer_profile_id' => null,
        ]);

        $this->assertSame('', $this->callBuildPurchaseHistoryBlock($conversation));
        $this->assertSame('', $this->callBuildPurchaseHistoryBlock(null));
    }

    public function test_purchase_history_block_is_empty_when_order_has_no_items(): void
    {
        $customer = CustomerProfile::factory()->create();
        $conversation = Conversation::factory()->create([
            'customer_profile_id' => $customer->id,
        ]);

        Order::factory()->create([
            'customer_profile_id' => $customer->id,
            'status' => 'completed',
            'created_at' => now()->subDays(3),
        ]);

        $this->assertSame('', $this->callBuildPurchaseHistoryBlock($conversation));
    }
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่าแดงจริง**

```bash
cd backend && php artisan test --filter=purchase_history_block
```

Expected: FAIL — `ReflectionException: Method buildPurchaseHistoryBlock does not exist`

- [ ] **Step 3: เขียนเมธอดให้ผ่าน**

เพิ่มใน `backend/app/Services/RAGService.php` ต่อจากเมธอด `buildEnhancedPrompt()`
และเพิ่ม `use App\Models\Order;` กับ `use Throwable;` ด้านบนไฟล์ถ้ายังไม่มี

```php
    /**
     * บล็อกประวัติการซื้อล่าสุดของลูกค้า ดึงจากตาราง orders จริง (ไม่พึ่ง LLM จด)
     *
     * คืน '' เมื่อไม่มีข้อมูลหรือ query ล้มเหลว — ความจำเป็นของเสริม ห้ามทำให้บอทตอบลูกค้าไม่ได้
     */
    protected function buildPurchaseHistoryBlock(?Conversation $conversation): string
    {
        $profileId = $conversation?->customer_profile_id;

        if (! $profileId) {
            return '';
        }

        try {
            $order = Order::query()
                ->where('customer_profile_id', $profileId)
                ->where('status', 'completed')
                ->where('created_at', '>=', now()->subDays(90))
                // id เป็น tiebreaker กันกรณีลูกค้าซื้อหลายใบใน timestamp เดียวกัน
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->with('items')
                ->first();

            if (! $order || $order->items->isEmpty()) {
                return '';
            }

            $lines = $order->items
                ->map(function ($item) {
                    $variant = $item->variant ? " ({$item->variant})" : '';

                    return "- {$item->product_name}{$variant} x{$item->quantity}";
                })
                ->implode("\n");

            $date = $this->formatThaiDate($order->created_at);

            return "## ประวัติการซื้อล่าสุดของลูกค้ารายนี้ (ข้อมูลจากระบบออเดอร์จริง)\n"
                ."{$lines} — {$date}\n\n"
                ."วิธีใช้: ใช้เพื่อเข้าใจบริบทคำถามที่ต่อเนื่องจากของที่ลูกค้าถืออยู่เท่านั้น\n"
                ."⛔ ห้ามเอ่ยถึงประวัตินี้ก่อนที่ลูกค้าจะพูดถึงเอง ห้ามทักทายด้วยประวัติ\n"
                ."⛔ ตัวเลขจำนวนนี้เป็นของออเดอร์เก่า ห้ามนำไปรวมกับออเดอร์ใหม่\n"
                ."⛔ ถ้าสินค้าที่เคยซื้อหมดสต็อก ให้ทำตามกฎสต็อกและเสนอตัวแทน ห้ามยึดประวัติ\n"
                ."---\n";
        } catch (Throwable $e) {
            Log::warning('RAGService: buildPurchaseHistoryBlock failed', [
                'conversation_id' => $conversation?->id,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * แปลงวันที่เป็นไทยย่อ เช่น "15 ส.ค. 2026" — map เองไม่พึ่ง locale package
     * เพื่อให้ผลลัพธ์เหมือนกันทุกเครื่องและเทสต์ได้แน่นอน
     */
    private function formatThaiDate(\DateTimeInterface $date): string
    {
        $months = [
            1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.',
            7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
        ];

        return (int) $date->format('j').' '.$months[(int) $date->format('n')].' '.$date->format('Y');
    }
```

- [ ] **Step 4: รันเทสต์ให้ผ่าน**

```bash
cd backend && php artisan test --filter=purchase_history_block
```

Expected: PASS ทั้ง 4 เทสต์

**หมายเหตุความครอบคลุมของเทสต์:** กรณี "query throw exception" มี `try/catch` ครอบไว้ในโค้ดแล้ว
แต่ไม่มี unit test เฉพาะ เพราะต้อง mock ให้ DB ล้มซึ่งซับซ้อนเกินคุณค่าที่ได้ — ถ้าใครแก้เมธอดนี้
ภายหลัง ให้ระวังอย่าถอด `try/catch` ออก

- [ ] **Step 5: รันเทสต์เดิมทั้งไฟล์ ยืนยันไม่มีอะไรพัง**

```bash
cd backend && php artisan test --filter=RAGServiceTest
```

Expected: PASS ทุกตัว (เดิม 25 + ใหม่ 4)

- [ ] **Step 6: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add backend/app/Services/RAGService.php backend/tests/Unit/Services/RAGServiceTest.php
git commit -m "feat(rag): เมธอดดึงประวัติการซื้อล่าสุดของลูกค้า (90 วัน)"
```

---

### Task 2: ต่อสายเข้า prompt และ cache

**Files:**
- Modify: `backend/app/Services/RAGService.php` (`generateResponse()`, `shouldSkipCache()`, `buildEnhancedPrompt()`)
- Test: `backend/tests/Unit/Services/RAGServiceTest.php`

**Interfaces:**
- Consumes: `buildPurchaseHistoryBlock()` จาก Task 1
- Produces: signature ใหม่ 2 ตัว (พารามิเตอร์ใหม่มี default เสมอ)
  - `shouldSkipCache(string $userMessage, ?Conversation $conversation, array $conversationHistory, bool $hasPurchaseHistory = false): bool`
  - `buildEnhancedPrompt(string $basePrompt, string $kbContext, ?Bot $bot = null, array $memoryNotes = [], string $purchaseHistoryBlock = ''): string`

- [ ] **Step 1: เขียนเทสต์ที่ต้องแดงก่อน**

เพิ่มใน `backend/tests/Unit/Services/RAGServiceTest.php` และ**แก้ helper เดิมสองตัว**ให้รับพารามิเตอร์
ใหม่ (ค่า default ทำให้เทสต์เดิมที่เรียก helper แบบเดิมยังทำงานได้)

```php
    // แก้ helper เดิม: เพิ่มพารามิเตอร์ท้ายสุด
    private function callShouldSkipCache(
        string $userMessage,
        ?Conversation $conversation = null,
        array $conversationHistory = [],
        bool $hasPurchaseHistory = false
    ): bool {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('shouldSkipCache');
        $method->setAccessible(true);

        return $method->invoke($this->service, $userMessage, $conversation, $conversationHistory, $hasPurchaseHistory);
    }

    // แก้ helper เดิม: เพิ่มพารามิเตอร์ท้ายสุด
    private function callBuildEnhancedPrompt(
        string $basePrompt,
        string $kbContext,
        ?Bot $bot = null,
        array $memoryNotes = [],
        string $purchaseHistoryBlock = ''
    ): string {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('buildEnhancedPrompt');
        $method->setAccessible(true);

        return $method->invoke($this->service, $basePrompt, $kbContext, $bot, $memoryNotes, $purchaseHistoryBlock);
    }

    public function test_enhanced_prompt_places_purchase_history_before_stock_block(): void
    {
        // ฟิลด์ตามของจริงใน ProductStock (name/slug/in_stock/...) — ดูตัวอย่างเทสต์เดิมบรรทัด ~477
        ProductStock::create([
            'name' => 'Nolimit Level Up+ BM', 'slug' => 'bm', 'aliases' => [], 'in_stock' => false,
            'display_order' => 1, 'stock_code' => 'BM', 'delivery_method' => 'stock',
        ]);
        Cache::forget(ProductStock::STOCK_CACHE_KEY);

        $block = "## ประวัติการซื้อล่าสุดของลูกค้ารายนี้ (ข้อมูลจากระบบออเดอร์จริง)\n"
            ."- Nolimit Level Up+ BM x1 — 15 ส.ค. 2026\n---\n";

        $result = $this->callBuildEnhancedPrompt('BASE', '', $this->bot, [], $block);

        $historyPos = mb_strpos($result, 'ประวัติการซื้อล่าสุดของลูกค้ารายนี้');
        $this->assertNotFalse($historyPos, 'บล็อกประวัติต้องอยู่ใน prompt');

        // กฎสต็อกต้องอยู่หลังบล็อกประวัติ เพื่อให้ LLM ให้น้ำหนักสต็อกมากกว่า
        // ข้อความจริงใน prompt คือ 'STOCK STATUS' และ 'STOCK REMINDER' (ไม่ใช่คำไทยว่า "สต็อก")
        $stockPos = mb_strpos($result, 'STOCK STATUS', $historyPos);
        $this->assertNotFalse($stockPos, 'บล็อก STOCK STATUS ต้องอยู่หลังบล็อกประวัติ');

        // STOCK REMINDER ถูกวางท้าย prompt เสมอ — ยืนยันว่าประวัติไม่ได้ไปแทรกหลังมัน
        $this->assertGreaterThan($historyPos, mb_strpos($result, 'STOCK REMINDER'));
    }

    public function test_enhanced_prompt_omits_history_section_when_block_is_empty(): void
    {
        $result = $this->callBuildEnhancedPrompt('BASE', '', null, [], '');

        $this->assertStringNotContainsString('ประวัติการซื้อล่าสุด', $result);
    }

    public function test_cache_is_skipped_when_customer_has_purchase_history(): void
    {
        // ข้อความยาวเกิน 20 ตัวอักษร ไม่มี history ไม่ใช่ชื่อสินค้า → ปกติจะ "ไม่ skip"
        $message = 'อยากทราบว่าตอนนี้ยังพอมีของเหลือให้สั่งเพิ่มอีกไหมครับผม';

        $this->assertFalse($this->callShouldSkipCache($message, null, [], false));
        $this->assertTrue($this->callShouldSkipCache($message, null, [], true));
    }
```

- [ ] **Step 2: รันเทสต์ให้เห็นว่าแดงจริง**

```bash
cd backend && php artisan test --filter="purchase_history_before_stock|omits_history_section|cache_is_skipped_when_customer"
```

Expected: FAIL — `ArgumentCountError` หรือ assertion ล้มเพราะ prompt ยังไม่มีบล็อกประวัติ

- [ ] **Step 3: แก้ `shouldSkipCache()` รับพารามิเตอร์ใหม่**

ใน `backend/app/Services/RAGService.php` แก้ signature และเพิ่มเงื่อนไข **ก่อน** เช็คข้อ 1 เดิม

```php
    protected function shouldSkipCache(
        string $userMessage,
        ?Conversation $conversation,
        array $conversationHistory,
        bool $hasPurchaseHistory = false
    ): bool {
        // Checks ordered cheapest → most expensive for early return optimization

        // 0. ลูกค้ามีประวัติการซื้อ → คำตอบถูกปรุงเฉพาะตัว ห้ามเสิร์ฟให้คนอื่น
        //    (rag_cache แชร์ทั้งบอทผ่าน RagCache::forBot() ไม่ได้แยกตามลูกค้า)
        if ($hasPurchaseHistory) {
            return true;
        }
```

(ที่เหลือของเมธอดคงเดิมทุกบรรทัด)

- [ ] **Step 4: แก้ `buildEnhancedPrompt()` รับบล็อกประวัติ**

แก้ signature และเพิ่มการต่อบล็อก **หลังบล็อก Memory เดิม แต่ก่อน stock injection**

```php
    protected function buildEnhancedPrompt(
        string $basePrompt,
        string $kbContext,
        ?Bot $bot = null,
        array $memoryNotes = [],
        string $purchaseHistoryBlock = ''
    ): string {
```

แล้วแทรกต่อจากบล็อก `if (! empty($memoryNotes)) { ... }` ทันที:

```php
        // ประวัติการซื้อจากตาราง orders จริง — วางก่อน stock เสมอ เพราะ stock reminder อยู่ท้าย
        // prompt (ใกล้ข้อความลูกค้าที่สุด = LLM ให้น้ำหนักสูงสุด) กฎสต็อกจึงทับประวัติได้ตามตำแหน่ง
        if ($purchaseHistoryBlock !== '') {
            $prompt .= "\n\n".$purchaseHistoryBlock;
        }
```

- [ ] **Step 5: ต่อสายใน `generateResponse()` — ดึงครั้งเดียว**

ใน `generateResponse()` เพิ่มบรรทัดดึงประวัติ **ก่อน** `$skipCache = ...` (Step 0) แล้วส่งต่อทั้งสองจุด

```php
        // ดึงประวัติการซื้อครั้งเดียวต่อการตอบ 1 ครั้ง แล้วส่งต่อทั้ง Step 0 และ Step 6
        // ⚠️ ห้ามเก็บไว้ใน property ของ service — RAGService เป็น singleton (AppServiceProvider:62)
        //    queue worker รันหลาย job ต่อ process ข้อมูลลูกค้าจะรั่วข้ามกัน
        $purchaseHistoryBlock = $this->buildPurchaseHistoryBlock($conversation);

        // Step 0: Check Semantic Cache first (fastest path)
        $skipCache = $this->shouldSkipCache(
            $userMessage,
            $conversation,
            $conversationHistory,
            $purchaseHistoryBlock !== ''
        );
```

และที่ Step 6 ส่ง argument ตัวที่ 5:

```php
        $systemPrompt = $this->buildEnhancedPrompt(
            $this->getSystemPromptForBot($bot),
            $kbContext,
            $bot,
            $memoryNotes,
            $purchaseHistoryBlock
        );
```

- [ ] **Step 6: รันเทสต์ใหม่ให้ผ่าน**

```bash
cd backend && php artisan test --filter="purchase_history_before_stock|omits_history_section|cache_is_skipped_when_customer"
```

Expected: PASS ทั้ง 3

- [ ] **Step 7: รันเทสต์ทั้ง suite ยืนยันไม่มีอะไรพัง**

```bash
cd backend && php artisan test
```

Expected: PASS ทั้งหมด — โดยเฉพาะเทสต์เดิมของ `RAGServiceTest` และ `PromptEvalRunnerTest`
ถ้าเจอ `ArgumentCountError` แปลว่ามีจุดเรียก `shouldSkipCache`/`buildEnhancedPrompt` ที่พลาดไป
ให้ค้นด้วย `grep -rn "shouldSkipCache\|buildEnhancedPrompt" backend/app backend/tests`

- [ ] **Step 8: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add backend/app/Services/RAGService.php backend/tests/Unit/Services/RAGServiceTest.php
git commit -m "feat(rag): ฉีดประวัติการซื้อเข้า prompt + ข้าม semantic cache เมื่อมีประวัติ"
```

---

### Task 3: เคส regression กับบอทจริง

**Files:**
- Modify: `backend/config/prompt-eval-cases.php`

**Interfaces:**
- Consumes: พฤติกรรมที่ Task 2 ทำให้เกิดขึ้นจริงบน prod
- Produces: เคส id `returning_customer_context` และ `purchase_history_respects_stock`

⚠️ เคสในชุดนี้ยิงเข้าบอทจริงผ่าน LLM (มีค่าใช้จ่ายจริง ~$0.013/รอบ) และ **ต้อง deploy โค้ดจาก
Task 2 ขึ้น prod ก่อน** ถึงจะทดสอบพฤติกรรมนี้ได้ เพราะ runner ยิงผ่าน `RAGService` บน container

- [ ] **Step 1: อ่านคอมเมนต์ schema ที่หัวไฟล์และเคสที่มีอยู่**

```bash
cd backend && head -35 config/prompt-eval-cases.php
```

ทำความเข้าใจ: `must_contain` เป็น AND ของ OR-group · needle ที่ขึ้นและลงท้ายด้วย `/` คือ regex ·
เคสที่มี `history` เดิน `RAGService` ส่วนเคสที่ไม่มีเดิน `AIService`

- [ ] **Step 2: เพิ่ม 2 เคสต่อท้ายอาร์เรย์**

```php
    // ลูกค้าเก่ากลับมาถามต่อเนื่องหลัง context ถูกล้าง (conv #1530, 21 ส.ค.)
    [
        'id' => 'returning_customer_context',
        'label' => 'ลูกค้าเก่าถามต่อเนื่อง ห้ามทักเหมือนลูกค้าใหม่ (conv #1530)',
        'history' => [
            ['sender' => 'user', 'content' => 'บัญชีที่ซื้อไปใช้ยิงแอดได้ปกติไหมครับ'],
            ['sender' => 'bot', 'content' => 'ได้ปกติครับพี่ ถ้ามีปัญหาแจ้งทีม Support ได้เลยครับ'],
        ],
        'message' => 'ตัวที่ผมถืออยู่เพิ่มลิมิตเองได้เลยไหมครับ',
        // บอทต้องตอบต่อเนื่องกับของที่ลูกค้าถืออยู่ ห้ามรีเซ็ตเป็นบทเปิดการขาย
        'must_not_contain' => [
            'สนใจตัวไหนดี',
            'สนใจสินค้าตัวไหน',
            // ห้ามเอ่ยประวัติขึ้นมาเองแบบทักทาย
            '/พี่เคยซื้อ/u',
        ],
    ],

    // ประวัติต้องไม่ทับกฎสต็อก (spec 2026-08-22)
    [
        'id' => 'purchase_history_respects_stock',
        'label' => 'เคยซื้อ BM แต่ BM หมด ต้องเสนอตัวแทน ไม่ยึดประวัติ',
        'message' => 'ขอ BM เพิ่มอีก 2 ตัวครับ',
        // เคสนี้มีความหมายเฉพาะตอน BM หมดสต็อกจริงเท่านั้น — ถ้า BM มีของ เคสนี้จะผ่านแบบ
        // ไม่ได้ทดสอบอะไร ให้เช็คสต็อกจริงก่อนเชื่อผลรัน (ดู <priority_0_stock> ใน prompt)
        'must_contain' => [
            ['Personal', 'หมด', 'ทีมงาน'],
        ],
    ],
```

- [ ] **Step 3: ตรวจไฟล์ยังใช้ได้และ id ไม่ซ้ำ**

```bash
cd backend && php -l config/prompt-eval-cases.php && php -r '
$c = require "config/prompt-eval-cases.php";
$ids = array_column($c, "id");
echo count($c), " เคส | id ซ้ำ: ", count($ids) - count(array_unique($ids)), PHP_EOL;'
```

Expected: `No syntax errors` · 25 เคส · id ซ้ำ 0

- [ ] **Step 4: ทดสอบ needle กับคำตอบตัวอย่างก่อนยิงจริง**

```bash
cd backend && php -r '
$wrong = "สวัสดีครับพี่ สนใจตัวไหนดีครับ? มี Nolimit ทั้ง BM และ Personal ครับ";
$right = "ลิมิตจะขยับเองตามการใช้งานครับพี่ ถ้าอยากให้ขึ้นเร็วแนะนำผูกบัตรครับ";
foreach (["สนใจตัวไหนดี", "สนใจสินค้าตัวไหน"] as $n) {
  printf("%-22s wrong=%s right=%s\n", $n, str_contains($wrong,$n)?"จับได้":"ไม่จับ", str_contains($right,$n)?"จับ(ผิด!)":"ไม่จับ");
}'
```

Expected: needle จับคำตอบผิดได้ และไม่จับคำตอบถูก — ถ้าไม่เป็นแบบนี้ needle ใช้ไม่ได้ ต้องแก้ก่อนไปต่อ

- [ ] **Step 5: Commit**

```bash
cd /Users/jaochai/Code/bot-fb
git add backend/config/prompt-eval-cases.php
git commit -m "test(prompt-eval): เคสลูกค้าเก่ากลับมา + ประวัติต้องไม่ทับกฎสต็อก"
```

- [ ] **Step 6: หลัง deploy ขึ้น prod แล้ว ยิงเคสจริงยืนยัน**

```bash
railway ssh "php /var/www/html/artisan prompt:eval --runs=3"
```

Expected: ผ่านทุกเคส (23 เดิม + 2 ใหม่ = 25) — ถ้า `purchase_history_respects_stock` ผ่านทั้งที่ BM
ยังมีของในสต็อก ให้ถือว่ายังไม่ได้ทดสอบจริง ต้องรอจังหวะที่ BM หมด หรือตั้งสต็อกทดสอบก่อน

---

## หลัง implement เสร็จ

- ยืนยันกับเคสจริง: เปิดดู conv #1530 ว่าถ้าลูกค้ารายนี้ทักมาอีก บอทตอบต่อเนื่องโดยไม่ทักเหมือนคนใหม่
- ไม่ต้อง backfill อะไรทั้งสิ้น ลูกค้าเก่าทุกคนได้ผลทันทีที่ deploy
- ตัวเลขอ้างอิงตอนเขียนแผน: ลูกค้าที่มีออเดอร์ใน 90 วันล่าสุด = 182 คน (จาก 1,795 ออเดอร์ทั้งหมด)
