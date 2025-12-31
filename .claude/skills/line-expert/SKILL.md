---
name: line-expert
description: Debug และสร้าง LINE integrations - ใช้เมื่อต้องการสร้าง Flex Message, debug webhook, แก้ปัญหา LINE API errors, หรือตรวจสอบ rate limits
---

# LINE SDK Expert

ใช้ skill นี้เมื่อต้องการ debug หรือสร้าง LINE integrations

## Flex Message Quick Start

### Basic Bubble
```json
{
  "type": "bubble",
  "body": {
    "type": "box",
    "layout": "vertical",
    "contents": [
      {
        "type": "text",
        "text": "Hello, World!",
        "weight": "bold",
        "size": "xl"
      }
    ]
  }
}
```

### Button with Action
```json
{
  "type": "bubble",
  "footer": {
    "type": "box",
    "layout": "vertical",
    "contents": [
      {
        "type": "button",
        "action": {
          "type": "uri",
          "label": "เยี่ยมชมเว็บไซต์",
          "uri": "https://example.com"
        },
        "style": "primary"
      }
    ]
  }
}
```

### Carousel (Multiple Bubbles)
```json
{
  "type": "carousel",
  "contents": [
    { "type": "bubble", "body": { ... } },
    { "type": "bubble", "body": { ... } }
  ]
}
```

ดู templates เพิ่มเติมใน `data/flex-templates.json`

---

## Webhook Debugging

### Webhook URL Format
```
https://[BACKEND_URL]/api/v1/webhook/line/[BOT_UUID]
```

### Debugging Steps

1. **ตรวจสอบ URL ใน LINE Console**
   - เข้า LINE Developers Console
   - ไปที่ Messaging API settings
   - ตรวจสอบ Webhook URL

2. **Verify Webhook**
   - กด "Verify" ใน LINE Console
   - ถ้า fail ให้ดู error message

3. **ตรวจสอบ Laravel Logs**
   ```bash
   cd backend && tail -f storage/logs/laravel.log | grep -i webhook
   ```

4. **Check Bot UUID**
   ```sql
   SELECT id, uuid, name, line_channel_access_token IS NOT NULL as has_token
   FROM bots WHERE id = ?;
   ```

### Common Webhook Issues

| Problem | Cause | Solution |
|---------|-------|----------|
| 403 Forbidden | Invalid signature | ตรวจสอบ Channel Secret |
| 500 Error | Code exception | ดู Laravel logs |
| Timeout | Processing too long | ใช้ async/queue |
| Not receiving | URL wrong | ตรวจสอบ webhook URL |

---

## LINE API Rate Limits

| Endpoint | Rate Limit | Notes |
|----------|------------|-------|
| Push Message | 100,000/month (free) | ขึ้นกับ plan |
| Reply Message | Unlimited | ต้อง reply ภายใน replyToken timeout |
| Multicast | 500 users/request | แยก batch ถ้าเกิน |
| Broadcast | 100,000/month (free) | ขึ้นกับ plan |
| Get Profile | 60,000/hour | Cache profile data |

### Rate Limit Headers
```
X-Line-Request-Id: request ID
X-Line-Accepted-Request-Id: accepted request ID
```

---

## Error Codes Reference

ดู `data/error-codes.csv` สำหรับรายการทั้งหมด

### Common Errors

| Code | Message | Solution |
|------|---------|----------|
| 400 | Invalid reply token | replyToken หมดอายุ (ใช้ได้ ~30s) |
| 401 | Authentication failed | ตรวจสอบ Channel Access Token |
| 403 | Not authorized | ตรวจสอบ permissions |
| 429 | Too many requests | Rate limit exceeded |

---

## Flex Message Best Practices

### Do's
- ใช้ `altText` ที่มีความหมาย (แสดงใน notification)
- ทดสอบใน LINE Flex Message Simulator
- ใช้ HTTPS สำหรับ images
- Limit carousel ไม่เกิน 12 bubbles

### Don'ts
- อย่าใช้ text ยาวเกินไป (จะถูกตัด)
- อย่าใช้ images ที่ใหญ่เกินไป
- อย่าลืม handle error cases

### Flex Message Simulator
```
https://developers.line.biz/flex-simulator/
```

---

## Rich Menu Configuration

### Create Rich Menu via API
```bash
curl -X POST https://api.line.me/v2/bot/richmenu \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer {CHANNEL_ACCESS_TOKEN}' \
  -d '{
    "size": { "width": 2500, "height": 1686 },
    "selected": true,
    "name": "Main Menu",
    "chatBarText": "เมนู",
    "areas": [
      {
        "bounds": { "x": 0, "y": 0, "width": 1250, "height": 843 },
        "action": { "type": "message", "text": "สินค้า" }
      }
    ]
  }'
```

### Rich Menu Size Options
| Size | Width | Height |
|------|-------|--------|
| Full | 2500 | 1686 |
| Half | 2500 | 843 |
| Compact | 800 | 270 |

---

## Key Files Reference

| File | Purpose |
|------|---------|
| `backend/app/Services/LINEService.php` | LINE API integration |
| `backend/app/Http/Controllers/WebhookController.php` | Webhook handling |
| `backend/config/services.php` | LINE credentials |

---

## Quick Debug Commands

### Test Webhook Locally (ngrok)
```bash
ngrok http 8000
# Copy the HTTPS URL and set as webhook in LINE Console
```

### Verify Channel Access Token
```bash
curl -X GET https://api.line.me/v2/bot/info \
  -H 'Authorization: Bearer {CHANNEL_ACCESS_TOKEN}'
```

### Get User Profile
```bash
curl -X GET https://api.line.me/v2/bot/profile/{userId} \
  -H 'Authorization: Bearer {CHANNEL_ACCESS_TOKEN}'
```
