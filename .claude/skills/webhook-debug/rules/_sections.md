# Decision Trees for Webhook Debugging

## 1. Bot Not Responding Decision Tree

```
Bot not responding to messages
├── Webhook reaching server?
│   ├── NO → Check webhook URL in platform console
│   │   ├── URL correct? → Platform API issue
│   │   └── URL wrong? → Update webhook URL
│   └── YES → Continue...
├── Signature validation passing?
│   ├── NO → Verify channel secret
│   │   ├── Secret matches console? → Check request body encoding
│   │   └── Secret mismatch? → Update .env
│   └── YES → Continue...
├── Job dispatched to queue?
│   ├── NO → Check controller exception
│   │   ├── Exception thrown? → Fix validation/parsing
│   │   └── No dispatch? → Check dispatch logic
│   └── YES → Continue...
├── Job processed successfully?
│   ├── NO → Check failed_jobs table
│   │   ├── Exception? → Fix job handler
│   │   └── Timeout? → Increase timeout
│   └── YES → Continue...
├── AI response received?
│   ├── NO → Check OpenRouter/model
│   │   ├── API error? → Check credentials
│   │   └── Model unavailable? → Use fallback
│   └── YES → Continue...
└── Reply sent successfully?
    ├── NO → Check platform API credentials
    │   ├── Token invalid? → Refresh token
    │   └── Rate limited? → Implement backoff
    └── YES → Issue elsewhere (check message content)
```

## 2. LINE-Specific Debug Flow

```
LINE webhook issue
├── X-Line-Signature present?
│   ├── NO → Platform not sending correctly
│   └── YES → Continue...
├── Signature valid?
│   ├── NO → Check channel secret
│   │   ├── hash_hmac('sha256', $body, $secret)
│   │   └── Must be raw body, not parsed
│   └── YES → Continue...
├── Event type recognized?
│   ├── NO → Update event handler
│   │   ├── message, follow, unfollow, postback...
│   │   └── Check LINE Messaging API docs
│   └── YES → Continue...
└── Reply token valid?
    ├── NO → Token expired (30 seconds)
    │   ├── Process faster
    │   └── Use push message instead
    └── YES → Check reply content format
```

## 3. Telegram-Specific Debug Flow

```
Telegram webhook issue
├── Webhook registered?
│   ├── NO → Call setWebhook API
│   └── YES → Continue...
├── Webhook URL accessible?
│   ├── NO → Check HTTPS, SSL cert
│   └── YES → Continue...
├── Update received?
│   ├── NO → Check pending_update_count
│   │   └── High count? → Clear with getUpdates
│   └── YES → Continue...
└── Response sent?
    ├── NO → Check bot token
    │   ├── 401? → Invalid token
    │   └── 400? → Invalid message format
    └── YES → Check message delivery
```

## 4. WebSocket Debug Flow

```
WebSocket not working
├── Reverb server running?
│   ├── NO → Start with php artisan reverb:start
│   └── YES → Continue...
├── Client connected?
│   ├── NO → Check Echo configuration
│   │   ├── Host/port correct?
│   │   ├── Auth endpoint working?
│   │   └── CORS configured?
│   └── YES → Continue...
├── Channel subscribed?
│   ├── NO → Check channel name format
│   │   ├── Private: private-bot.{id}
│   │   └── Presence: presence-bot.{id}
│   └── YES → Continue...
└── Events received?
    ├── NO → Check broadcast event class
    │   ├── Implements ShouldBroadcast?
    │   └── broadcastOn() returns correct channel?
    └── YES → Issue in event handling
```

## 5. Queue Debug Flow

```
Job not processing
├── Queue worker running?
│   ├── NO → Start worker
│   │   └── php artisan queue:work
│   └── YES → Continue...
├── Job dispatched?
│   ├── NO → Check dispatch code
│   │   └── dispatch() called?
│   └── YES → Continue...
├── Job in queue?
│   ├── NO → Check queue connection
│   │   └── QUEUE_CONNECTION in .env
│   └── YES → Continue...
├── Job failed?
│   ├── YES → Check failed_jobs table
│   │   ├── Exception message?
│   │   └── php artisan queue:retry {id}
│   └── NO → Continue...
└── Job successful but no result?
    └── Check job handler logic
```

## 6. Message Flow Trace

```
Tracing a single message
1. Platform → Webhook
   └── Log: "Webhook received: {platform} {event_type}"
2. Webhook → Controller
   └── Log: "Processing webhook for bot {bot_id}"
3. Controller → Validation
   └── Log: "Signature validation: {pass/fail}"
4. Validation → Job Dispatch
   └── Log: "Dispatched job {job_id}"
5. Job → Processing
   └── Log: "Processing message {message_id}"
6. Processing → AI
   └── Log: "AI request: {model} {tokens}"
7. AI → Response
   └── Log: "AI response: {chars} chars"
8. Response → Reply
   └── Log: "Reply sent: {platform_response}"
```

## 7. Common Error Patterns

| Error | Likely Cause | Quick Fix |
|-------|--------------|-----------|
| 401 Unauthorized | Invalid token | Check credentials |
| 403 Forbidden | IP not whitelisted | Add server IP |
| 429 Too Many Requests | Rate limited | Implement backoff |
| 500 Internal Error | Server exception | Check logs |
| Timeout | Slow processing | Optimize or async |
| Signature mismatch | Wrong secret | Verify config |
| Channel not found | Wrong channel name | Check format |
| Job timeout | Long processing | Increase timeout |
