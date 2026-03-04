---
description: Payment flow and order management specialist for LINE Flex messages, order tracking, and VIP detection
triggers: ['payment', 'order', 'flex message', 'VIP', 'ชำระเงิน', 'สั่งซื้อ', 'คำสั่งซื้อ']
---

# Payment & Order Workflows

LINE Flex messages, payment verification, order tracking for Line Adsvance.

## Payment Flow (5 Steps)

```
Step 2: CONFIRM (Orange #FF6B00)
  ├─ Trigger: "รวม...บาท" + "ยืนยัน"
  ├─ Shows: order summary with confirm button
  └─ Action: user types "ยืนยัน"

Step 3: TERMS (Blue #0367D3)
  ├─ Trigger: "ยอมรับ" + "ข้อตกลง"
  ├─ Shows: terms link (Canva site)
  └─ Action: user types "ยอมรับ"

Step 4: PAYMENT (Green/Gold)
  ├─ Trigger: bank account "223-3-24880-3" + payment keyword
  ├─ Shows: bank transfer details, copy button, warnings
  └─ Warning: no TrueWallet, bank app screenshot only

Step 5: VERIFY SUCCESS (Green/Gold)
  ├─ Trigger: "[ยืนยันชำระเงิน]" tag + "เงินเข้าแล้ว {amount}"
  ├─ Shows: success confirmation with amount
  └─ Plugin: triggers Telegram notification + order creation
```

## Detection Priority (in code order)

1. Step 4 (Payment) - highest specificity, checked first
2. Step 3 (Terms) - excludes bank account + verify tags
3. Step 5 (Verify) - requires `[ยืนยันชำระเงิน]` tag
4. Step 2 (Confirm) - most likely false-positive, checked last

## Key Files

| File | Purpose |
|------|---------|
| `app/Services/PaymentFlexService.php` | Flex message builder (957 lines) |
| `app/Services/OrderService.php` | Order creation & product normalization |
| `app/Models/Order.php` | Order model with relationships |
| `app/Models/OrderItem.php` | Order item with product/variant |
| `app/Http/Controllers/Api/OrderController.php` | Dashboard API (346 lines) |
| `app/Console/Commands/BackfillOrdersFromMessages.php` | Retroactive order creation |
| `app/Console/Commands/NormalizeOrderItems.php` | Product name standardization |

## Database Schema

### Orders
```
id, bot_id (FK), conversation_id (FK), customer_profile_id (FK),
message_id (FK), total_amount (decimal 12,2), payment_method,
status (default: completed), channel_type, raw_extraction (jsonb),
notes, timestamps
```

### Order Items
```
id, order_id (FK), product_name, category (default: nolimit),
variant (nullable), quantity (default: 1), unit_price, subtotal,
timestamps
```

## Product Categories

| Input Keywords | Product Name | Category |
|---------------|-------------|----------|
| ไก่, เฟสไก่, g3d | G3D | g3d |
| เพจ, Page, Fanpage | Page | page |
| BM, บีเอ็ม, บัญชีธุรกิจ | Nolimit BM | nolimit |
| Personal, ส่วนตัว | Nolimit Personal | nolimit |
| nolimit, โนลิมิต | Nolimit | nolimit |

**G3D Variants**: ผูกบัตร, เติมเงิน

## Multi-Product Order Processing

Orders can contain multiple products in a single transaction. The `product` variable from plugin extraction is a comma-separated string.

**Parsing flow** (`OrderService::createFromPluginExtraction`):
```php
// Split comma-separated products: "Page, G3D x2, BM"
$productParts = array_filter(array_map('trim', preg_split('/,\s*/', $rawProduct)));
```

**Quantity patterns** (checked per product part):
| Pattern | Example | Regex |
|---------|---------|-------|
| `x{N}` suffix | `G3D x2` | `/^(.+?)\s*x(\d+)\s*$/u` |
| `{N} ตัว/เพจ/ชุด/รายการ` | `เพจ 5 เพจ` | `/^(.+?)\s+(\d+)\s*(?:ตัว\|เพจ\|ชุด\|รายการ)\s*$/u` (backfill only) |

**Per-product OrderItem creation**:
- Each part is normalized via `OrderService::normalizeProductName()` then inserted as a separate `OrderItem`
- **unit_price logic**: only calculated when `count($items) === 1` (single product) — set to `total_amount / quantity`
- **Multi-product**: `unit_price` and `subtotal` are both `null` (cannot split total across products without per-item pricing)

## Product Normalization Pipeline

**`extractProductLines()`** (in `BackfillOrdersFromMessages`):
1. Strip markdown bold markers (`**`)
2. Match order block: `/ออเดอร์[:\s]*\n([\s\S]*?)(?=\n\n|\n\[|\nส่งใน|$)/u`
3. Split into lines, strip leading bullets (`-` or `•`)
4. Parse quantity from each line (x2 or Thai unit patterns)
5. Normalize each product name via `OrderService::normalizeProductName()`

**`extractProduct()`** (in `FlowPluginService` regex fallback):
- Same regex to match `ออเดอร์:` block from bot message
- Joins extracted lines with `, ` for comma-separated format
- Used when AI variable extraction misses the `product` field

**Conversation lookback**: `FlowPluginService::evaluateAndExecute()` fetches last **5 messages** for AI trigger evaluation context. The `extractProductLines()` method in backfill works on the single bot confirmation message (no lookback needed since all product info is in the confirmation).

**Resolution sources**: Product info comes from one of these paths:
1. **AI extraction** — LLM extracts `product` variable from conversation context (primary)
2. **Regex fallback** — `extractProduct()` parses `ออเดอร์:` block from bot message when AI misses it
3. **Backfill** — `extractProductLines()` parses historical confirmation messages retroactively

## VIP Detection

```php
// PaymentFlexService::isVipConversation()
// Checks conversation.memory_notes for \bVIP\b regex
// Notes can be string OR {type: "memory", content: "..."} object
```

**VIP Styling**: Gold header (#D4A017) instead of green (#1DB446)

## Bank Constants

**Receiving account** (in `PaymentFlexService`):
```php
BANK_ACCOUNT   = '223-3-24880-3'
BANK_NAME      = 'ธนาคารกสิกรไทย (KBANK)'
ACCOUNT_NAME   = 'หจก. มั่งมีทรัพย์ขายของออนไลน์'
CLIPBOARD_TEXT = '2233248803'
TERMS_URL      = 'https://mhhacoursecontent.my.canva.site/ads-vance'
SUPPORT_LINE_ID = '@743ddeqy'
```

**Source bank detection** (in `FlowPluginService::extractBank` and `BackfillOrdersFromMessages::extractBank`):

| Keyword(s) | Normalized Label |
|-------------|-----------------|
| กสิกร, KBANK, K PLUS | กสิกรไทย (KBANK) |
| ไทยพาณิชย์, SCB | ไทยพาณิชย์ (SCB) |
| กรุงเทพ, BBL | กรุงเทพ (BBL) |
| กรุงไทย, KTB | กรุงไทย (KTB) |
| กรุงศรี, BAY | กรุงศรี (BAY) |
| ทหารไทยธนชาต, ttb, TMB | ทหารไทยธนชาต (ttb) |
| ออมสิน, GSB | ออมสิน (GSB) |
| PromptPay, พร้อมเพย์ | PromptPay |

Total: **8 banks** mapped from **17 keyword variants**. Detection uses `mb_stripos` (case-insensitive, multibyte-safe).

## Integration Flow

```
User Message → ProcessLINEWebhook → AI generates response
  → PaymentFlexService.tryConvertToFlex()
    ├─ Flex detected → Send as LINE Flex message
    └─ Not Flex → MultipleBubblesService or plain text
  → FlowPluginService.executePlugins()
    └─ Telegram notification + OrderService.createFromPluginExtraction()
```

## Decimal Amount Parsing

Amount extraction uses a 3-tier regex cascade (in both `FlowPluginService::extractAmount` and `BackfillOrdersFromMessages::extractAmount`):

```php
// 1. Highest priority: "เงินเข้าแล้ว 1,600.50 บาท"
'/(?:เงินเข้าแล้ว\s*)([\d,]+\.?\d*)\s*บาท/u'

// 2. Amount with checkmark: "1,600.50 บาท ✅"
'/([\d,]+\.?\d*)\s*บาท\s*✅/u'

// 3. Generic fallback: any "N บาท"
'/([\d,]+\.?\d*)\s*บาท/u'
```

- Supports comma-separated thousands: `1,600`
- Supports optional decimals: `1,600.50`
- Backfill additionally strips `**` bold markers before matching
- Amount is then cleaned: `str_replace([',', ' '], '', $raw)` and cast to float

## raw_extraction Field

The `orders.raw_extraction` column (jsonb) stores the original extraction data for debugging and audit.

**From plugin extraction** (`OrderService::createFromPluginExtraction`):
```json
{
  "amount": "1,600",
  "product": "Page, G3D x2",
  "source_bank": "กสิกรไทย (KBANK)",
  "customer_name": "สมชาย",
  "datetime": "04/03/2026 15:30"
}
```
Stored as-is from the merged AI + regex fallback variables.

**From backfill** (`BackfillOrdersFromMessages`):
```json
{
  "source": "backfill",
  "amount": 1600.00,
  "bank": "กสิกรไทย (KBANK)",
  "products": [
    {"name": "Page", "quantity": 1, "category": "page", "variant": null},
    {"name": "G3D", "quantity": 2, "category": "g3d", "variant": "ผูกบัตร"}
  ]
}
```
Note: backfill uses `json_encode()` explicitly and stores parsed (not raw) values. The `"source": "backfill"` flag distinguishes retroactive orders from live ones.

## Plugin Variable Handling

`OrderService::createFromPluginExtraction` supports **dual field names** for product:

```php
$rawProduct = $variables['product'] ?? $variables['product_category'] ?? null;
```

- `product` — primary field, extracted by AI from conversation context
- `product_category` — fallback field, used by some older plugin message templates

Both fields contain the same format: a string that may be comma-separated for multi-product orders (e.g., `"Page, G3D x2, BM"`).

**Variable flow**: Plugin template `{product}` placeholder --> AI extracts value --> regex fallback if AI misses it --> merged into `$variables` array --> passed to `createFromPluginExtraction()`.

## Order Deduplication

- **With message_id**: check existing order with same (conversation_id, message_id)
- **Without message_id**: check last 2 minutes for same amount + conversation
- **Non-blocking**: returns null on failure (logs warning, doesn't throw)

## Flex Size Limit

- Max: 30,000 bytes JSON
- `safeBuildFlex()` checks size, falls back to plain text if exceeded
- Encoding: `json_encode()` (no special flags)

## Critical Gotchas

| Issue | Solution |
|-------|----------|
| VIP notes format | Can be string OR {type, content} object - check both |
| Detection order | Step 4 before Step 5 (both have amounts) |
| Decimal amounts | RegEx must support "1,600.50 บาท" not just "1,600 บาท" |
| Product ambiguity | "เฟส" can mean Page or G3D - use conversation lookback |
| Flex fallback | Returns original text if JSON encoding fails or size > 30KB |
| Default category | Always 'nolimit' if product unrecognized |

## Order API Endpoints

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/api/orders` | Paginated list with filters |
| GET | `/api/orders/summary` | Aggregate stats (5-min cache) |
| GET | `/api/orders/by-customer` | Customer breakdown top 100 |
| GET | `/api/orders/by-product` | Product breakdown top 100 |
| GET | `/api/orders/{order}` | Single order details |
| PUT | `/api/orders/{order}` | Update status/notes |

## Artisan Commands

```bash
# Backfill orders from historical messages
php artisan orders:backfill-from-messages --bot-id=26 --dry-run

# Normalize product names across all items
php artisan orders:normalize-items --dry-run
```

## Tests

```bash
php artisan test --filter=PaymentFlexServiceTest
```
