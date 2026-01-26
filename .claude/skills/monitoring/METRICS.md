# Key Metrics Reference

## Application Metrics

| Metric | Target | Alert Threshold |
|--------|--------|-----------------|
| API Response Time | < 500ms | > 2s |
| Error Rate | < 1% | > 5% |
| Queue Size | < 100 | > 500 |
| Memory Usage | < 256MB | > 400MB |

## Business Metrics

| Metric | Track |
|--------|-------|
| Messages Processed | Count per bot per hour |
| AI Response Time | Average and p95 |
| User Sessions | Active users |
| Bot Conversations | New vs returning |

## Database Metrics

| Metric | Target | Alert Threshold |
|--------|--------|-----------------|
| Query Time | < 100ms | > 500ms |
| Connections | < 50 | > 80 |
| Table Size Growth | Stable | >10%/day |

## Alerting Patterns

### Sentry Alerts

| Alert Type | Trigger | Action |
|------------|---------|--------|
| New Issue | First occurrence | Slack notification |
| Regression | Issue reappears | Email + Slack |
| High Volume | >100 events/hour | PagerDuty |

## Custom Metrics Logging

```php
// Log custom metric
Log::info('metric.api_response_time', [
    'endpoint' => $request->path(),
    'duration_ms' => $duration,
    'status' => $response->status(),
]);

// Log business metric
Log::info('metric.message_processed', [
    'bot_id' => $bot->id,
    'platform' => $bot->platform,
    'response_time_ms' => $responseTime,
]);
```
