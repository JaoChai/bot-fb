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
| `app/Http/Controllers/Api/OrderController.php` | Dashboard API (347 lines) |
| `app/Commands/BackfillOrdersFromMessages.php` | Retroactive order creation |
| `app/Commands/NormalizeOrderItems.php` | Product name standardization |

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

## VIP Detection

```php
// PaymentFlexService::isVipConversation()
// Checks conversation.memory_notes for \bVIP\b regex
// Notes can be string OR {type: "memory", content: "..."} object
```

**VIP Styling**: Gold header (#D4A017) instead of green (#1DB446)

## Bank Constants

```php
BANK_ACCOUNT   = '223-3-24880-3'
BANK_NAME      = 'ธนาคารกสิกรไทย (KBANK)'
ACCOUNT_NAME   = 'หจก. มั่งมีทรัพย์ขายของออนไลน์'
CLIPBOARD_TEXT = '2233248803'
TERMS_URL      = 'https://mhhacoursecontent.my.canva.site/ads-vance'
SUPPORT_LINE_ID = '@743ddeqy'
```

## Integration Flow

```
User Message → ProcessLINEWebhook → AI generates response
  → PaymentFlexService.tryConvertToFlex()
    ├─ Flex detected → Send as LINE Flex message
    └─ Not Flex → MultipleBubblesService or plain text
  → FlowPluginService.executePlugins()
    └─ Telegram notification + OrderService.createFromPluginExtraction()
```

## Order Deduplication

- **With message_id**: check existing order with same (conversation_id, message_id)
- **Without message_id**: check last 2 minutes for same amount + conversation
- **Non-blocking**: returns null on failure (logs warning, doesn't throw)

## Flex Size Limit

- Max: 30,000 bytes JSON
- `safeBuildFlex()` checks size, falls back to plain text if exceeded
- Encoding: `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`

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
