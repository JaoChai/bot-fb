---
id: metrics-003-resource-usage
title: Resource Usage Monitoring
impact: MEDIUM
impactDescription: "Unmonitored resource usage leads to outages"
category: metrics
tags: [metrics, resources, memory, cpu]
relatedRules: [metrics-001-api-response-time, health-002-service-checks]
---

## Symptom

- App crashes unexpectedly
- Out of memory errors
- CPU spikes
- Queue backing up
- Service restarts

## Root Cause

1. Memory leaks
2. CPU-intensive operations
3. Queue not draining
4. Connection pool exhausted
5. Disk space full

## Diagnosis

### Quick Check

```bash
# Check Railway service status
mcp__railway__list-deployments(
  workspacePath='/path/to/project',
  json=true
)

# Check for OOM in logs
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='memory exhausted OR SIGKILL'
)
```

### Database Resource Check

```bash
# Connection count
mcp__neon__run_sql(
  projectId='your-project-id',
  sql='SELECT count(*) FROM pg_stat_activity'
)

# Table sizes
mcp__neon__run_sql(
  projectId='your-project-id',
  sql='SELECT relname, n_live_tup as rows, pg_size_pretty(pg_total_relation_size(relid)) as size FROM pg_stat_user_tables ORDER BY pg_total_relation_size(relid) DESC LIMIT 10'
)
```

## Solution

### Monitor Memory Usage

```php
// Log memory usage
Log::info('resource.memory', [
    'used' => memory_get_usage(true) / 1024 / 1024,
    'peak' => memory_get_peak_usage(true) / 1024 / 1024,
]);

// In job/worker
class ProcessMessage implements ShouldQueue
{
    public function handle()
    {
        // Log memory before
        $startMemory = memory_get_usage(true);

        // Process...

        // Log memory after
        Log::info('job.memory', [
            'job' => 'ProcessMessage',
            'memory_mb' => (memory_get_usage(true) - $startMemory) / 1024 / 1024,
        ]);
    }
}
```

### Monitor Queue Size

```php
// Check queue size
$queueSize = Queue::size('default');
if ($queueSize > 100) {
    Log::warning('queue.backlog', [
        'queue' => 'default',
        'size' => $queueSize,
    ]);
}
```

### Check in Logs

```bash
# Memory issues
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='memory'
)

# Queue issues
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='queue.backlog OR Job failed'
)
```

### Database Connections

```bash
# Check connection usage
mcp__neon__run_sql(
  projectId='your-project-id',
  sql='SELECT count(*), state, query FROM pg_stat_activity GROUP BY state, query'
)

# Check for idle connections
mcp__neon__run_sql(
  projectId='your-project-id',
  sql="SELECT count(*) FROM pg_stat_activity WHERE state = 'idle' AND query_start < NOW() - INTERVAL '5 minutes'"
)
```

### Resource Targets

| Resource | Target | Alert |
|----------|--------|-------|
| Memory | < 256MB | > 400MB |
| CPU | < 50% avg | > 80% |
| Queue size | < 100 | > 500 |
| DB connections | < 50 | > 80 |
| Disk (Neon) | < 80% | > 90% |

### Health Check with Resources

```php
Route::get('/health', function () {
    return [
        'status' => 'ok',
        'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        'queue_size' => Queue::size('default'),
        'db_connections' => DB::select('SELECT count(*) FROM pg_stat_activity')[0]->count,
    ];
});
```

## Verification

```bash
# Check resources are within limits
curl https://api.botjao.com/health

# Check logs for resource warnings
mcp__railway__get-logs(
  workspacePath='/path/to/project',
  logType='deploy',
  filter='resource OR memory OR queue'
)
```

## Prevention

- Set memory limits
- Monitor queue size
- Alert on resource thresholds
- Optimize memory-heavy operations
- Scale workers as needed

## Project-Specific Notes

**BotFacebook Context:**
- Railway: Default 512MB memory
- Neon: Check connection limits
- Queue: Redis-based, monitor size
- Heavy operations: AI processing, embedding generation
