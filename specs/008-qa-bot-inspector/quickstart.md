# Quickstart: QA Bot Inspector

**Feature**: 008-qa-bot-inspector
**Date**: 2026-01-13

## Prerequisites

- Laravel 12 backend running
- React 19 frontend running
- PostgreSQL database with migrations applied
- Redis for queue processing
- OpenRouter API key configured

## 1. Run Migrations

```bash
cd backend
php artisan migrate
```

This will:
- Add QA Inspector fields to `bots` table
- Create `qa_evaluation_logs` table
- Create `qa_weekly_reports` table

## 2. Start Queue Workers

```bash
# Dedicated queue for QA evaluations
php artisan queue:work redis --queue=qa-evaluation

# Or with Horizon
php artisan horizon
```

## 3. Configure Scheduled Tasks

Add to `routes/console.php` or scheduler:

```php
// Generate weekly reports every Monday at 00:00
Schedule::command('qa:generate-weekly-reports')
    ->weeklyOn(1, '00:00')
    ->withoutOverlapping();

// Cleanup old evaluation logs (90 days retention)
Schedule::command('qa:cleanup-old-logs')
    ->daily();
```

## 4. Enable QA Inspector for a Bot

### Via API:

```bash
curl -X PUT "https://api.botjao.com/api/v1/bots/1/qa-inspector/settings" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "qa_inspector_enabled": true,
    "qa_score_threshold": 0.70,
    "qa_sampling_rate": 100
  }'
```

### Via Frontend:

1. Go to Bot Settings page
2. Navigate to "QA Inspector" tab
3. Toggle "Enable QA Inspector" ON
4. Configure models and thresholds as needed
5. Click "Save Settings"

## 5. View Evaluation Results

### Dashboard:

Navigate to `/bots/{id}/qa-inspector` to see:
- Real-time evaluation stats
- Score trends
- Issue breakdown
- Recent evaluation logs

### API:

```bash
# Get dashboard stats
curl "https://api.botjao.com/api/v1/bots/1/qa-inspector/stats?period=7d" \
  -H "Authorization: Bearer {token}"

# Get evaluation logs
curl "https://api.botjao.com/api/v1/bots/1/qa-inspector/logs?is_flagged=true" \
  -H "Authorization: Bearer {token}"
```

## 6. View Weekly Reports

Reports are generated automatically on schedule. To view:

### Via Frontend:

1. Go to Bot QA Inspector page
2. Click "Weekly Reports" tab
3. Select a report to view details

### Via API:

```bash
# List reports
curl "https://api.botjao.com/api/v1/bots/1/qa-inspector/reports" \
  -H "Authorization: Bearer {token}"

# Get specific report
curl "https://api.botjao.com/api/v1/bots/1/qa-inspector/reports/5" \
  -H "Authorization: Bearer {token}"
```

## 7. Apply Prompt Suggestions

When a weekly report contains prompt suggestions:

### Via Frontend:

1. Open weekly report
2. Find "Prompt Suggestions" section
3. Review before/after text
4. Click "Apply to Flow"
5. Confirm the change

### Via API:

```bash
curl -X POST "https://api.botjao.com/api/v1/bots/1/qa-inspector/reports/5/suggestions/0/apply" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "flow_id": 1,
    "confirm": true
  }'
```

## Testing

### Run Unit Tests:

```bash
cd backend
php artisan test --filter QAInspector
```

### Manual Testing:

1. Enable QA Inspector for a test bot
2. Send a test message through the bot
3. Check that evaluation log is created within 30 seconds
4. Verify scores are calculated correctly
5. Test with low-quality responses to verify flagging

## Troubleshooting

### Evaluations not appearing?

1. Check QA Inspector is enabled: `Bot::find(1)->qa_inspector_enabled`
2. Check queue is processing: `php artisan queue:monitor qa-evaluation`
3. Check for job failures: `php artisan queue:failed`

### Report generation fails?

1. Check OpenRouter API key is valid
2. Check model quotas/rate limits
3. Review logs: `tail -f storage/logs/laravel.log | grep QA`

### High evaluation costs?

1. Reduce sampling rate: Set `qa_sampling_rate` to 50 or lower
2. Use cheaper models for Layer 1: Switch to free Gemini model
3. Increase threshold: Set `qa_score_threshold` higher to reduce Layer 2 analysis

## Cost Monitoring

Expected costs (200 conversations/day):
- Layer 1 (Gemini Flash): ~$0.002/conversation
- Layer 2 (Claude Sonnet): ~$0.05/flagged issue
- Layer 3 (Claude Opus): ~$3.50/weekly report

Monthly estimate: ~$62 (at 100% sampling, 12% issue rate)

## Next Steps

- Configure email notifications for weekly reports
- Set up Slack webhook for real-time alerts
- Review first weekly report and apply suggestions
- Adjust thresholds based on your quality standards
