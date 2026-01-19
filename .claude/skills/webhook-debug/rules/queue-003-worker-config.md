---
id: queue-003-worker-config
title: Queue Worker Configuration Issues
impact: HIGH
impactDescription: "Jobs not processed or processed incorrectly"
category: queue
tags: [queue, worker, configuration, supervisor]
relatedRules: [queue-001-failed-jobs, queue-002-job-timeout]
---

## Symptom

- Jobs stuck in queue not being processed
- Only some queues being processed
- Worker crashes repeatedly
- Jobs processed multiple times

## Root Cause

1. Worker not running
2. Wrong queue name specified
3. Memory limit too low
4. Worker not restarting after deploy
5. Multiple workers causing race conditions

## Diagnosis

### Quick Check

```bash
# Check if worker is running
ps aux | grep queue:work

# Check queue sizes
php artisan queue:monitor default,webhooks,broadcasts

# Check Redis queue length
redis-cli LLEN queues:default
```

### Detailed Analysis

```bash
# Check Supervisor status
supervisorctl status

# View Supervisor logs
tail -f /var/log/supervisor/queue-worker.log

# Check Railway process
railway logs --filter "queue"
```

## Solution

### Fix Steps

1. **Configure Supervisor**
```ini
; /etc/supervisor/conf.d/laravel-worker.conf
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work --sleep=3 --tries=3 --max-jobs=1000 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/queue-worker.log
stopwaitsecs=3600
```

2. **Configure for Railway/Docker**
```bash
# Procfile
web: php artisan serve --host=0.0.0.0 --port=$PORT
worker: php artisan queue:work --sleep=3 --tries=3 --max-jobs=1000

# Or use single process with horizon
web: php artisan horizon
```

3. **Restart Workers After Deploy**
```bash
# Queue restart signal
php artisan queue:restart

# Or in deploy script
php artisan config:cache
php artisan queue:restart
```

### Code Example

```php
// Good: Queue configuration
// config/queue.php

return [
    'default' => env('QUEUE_CONNECTION', 'redis'),

    'connections' => [
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 90,
            'block_for' => null,
            'after_commit' => false,
        ],
    ],

    // Separate failed jobs table
    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],
];

// Job with queue specification
class ProcessLINEWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public array $event)
    {
        // Specify queue
        $this->onQueue('webhooks');
    }
}

// Dispatch to specific queue
dispatch(new ProcessLINEWebhook($event))->onQueue('webhooks');
```

```bash
# Worker script with multiple queues (priority order)
#!/bin/bash
# worker.sh

php artisan queue:work redis \
    --queue=high,webhooks,default,broadcasts,low \
    --sleep=3 \
    --tries=3 \
    --max-jobs=1000 \
    --max-time=3600 \
    --memory=512
```

## Prevention

- Use Supervisor or systemd for worker management
- Monitor worker process health
- Set up alerts for queue depth
- Restart workers after deploys
- Use appropriate number of workers

## Debug Commands

```bash
# Check queue connection
php artisan tinker
>>> Queue::connection()->getConnectionName()

# Process single job
php artisan queue:work --once -v

# Check pending jobs
php artisan tinker
>>> Redis::lrange('queues:default', 0, -1)

# Clear all jobs from queue (careful!)
php artisan queue:clear redis --queue=default

# Monitor multiple queues
php artisan queue:monitor default,webhooks,broadcasts --max=100
```

## Project-Specific Notes

**BotFacebook Context:**
- Queues: `default`, `webhooks`, `broadcasts`, `ai`
- Railway runs separate worker dyno
- Config in `config/queue.php`
- Use `php artisan horizon` for dashboard in development
