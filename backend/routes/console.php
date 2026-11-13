<?php

use App\Jobs\ProcessLeadRecovery;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Scheduler mutexes live on the database store, NOT the default cache store:
// FailoverStore::lock() only hands back the FIRST store's lock object, so a
// Redis failure inside withoutOverlapping's lock acquire() is not failed
// over. The every-minute tasks below must keep their mutexes working while
// Redis is stalled (2026-11-09 incident).
Schedule::useCache('database');

// withoutOverlapping() with no argument expires the mutex after the framework
// default of 1440 minutes — and a scheduler killed by SIGTERM mid-tick
// (redeploy / container swap, 13 Nov 2026) never releases it, so the command
// is skipped for a full day. Every entry below therefore passes an explicit
// expiry in minutes: longer than the command's worst realistic run time, at
// most 5 for entries running every minute or every 5 minutes, at most 60 for
// the rest. The guard test tests/Feature/ScheduleLockExpiryTest.php fails on
// any entry (present or future) that falls back to the default.

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Auto-enable bot for conversations that have reached their timeout
// Runs every minute to check for conversations needing bot re-enabled
Schedule::command('conversations:auto-enable-bots')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();
// expiresAt 5: per-conversation update + broadcast finish in seconds, so a
// stale lock must never skip this every-minute task for long.

// Clean up expired RAG cache entries
// Runs every 6 hours as configured in rag.semantic_cache.cleanup_interval_hours
Schedule::command('rag:cleanup-cache')
    ->everyFourHours()
    ->withoutOverlapping(10)
    ->runInBackground();
// expiresAt 10: cleanup() is a single DELETE on rag_cache (SemanticCacheService
// l.256) — seconds even on a big table.

// Process lead recovery to re-engage inactive leads
// Runs hourly to check for leads needing follow-up
Schedule::job(new ProcessLeadRecovery)->hourly()->name('lead-recovery');

// Failed jobs cleanup - daily at 3:30am
// Deletes failed jobs older than 7 days (168 hours) to manage storage
Schedule::command('queue:prune-failed --hours=168')
    ->daily()
    ->at('03:30')
    ->withoutOverlapping(10)
    ->runInBackground();
// expiresAt 10: the framework prune is a single DELETE on failed_jobs
// (DatabaseFailedJobProvider::prune).

// LINE profile picture URL refresh - daily at 4am
// Refreshes expired LINE profile pictures for active users (30-day window)
// LINE CDN URLs expire periodically, this ensures we have valid URLs
Schedule::command('profiles:refresh-line-pictures')
    ->daily()
    ->at('04:00')
    ->withoutOverlapping(60)
    ->runInBackground();
// expiresAt 60: chunkById(50) with 1s inter-batch delay and, per profile, a
// LINE getProfile (30s HTTP cap, LINEService l.569) plus a picture download
// (10s cap, ProfilePictureService l.25) — a large run can take tens of
// minutes, so this needs the full 60-minute ceiling.

// Prune expired cache entries - daily at 02:00
Schedule::command('cache:prune-stale-tags')->daily()->at('02:00')
    ->withoutOverlapping(10)->runInBackground();
// expiresAt 10: framework flushStaleTags is a handful of DELETEs on the cache
// tables.

// Clean expired database cache rows (Neon storage optimization)
Schedule::call(function () {
    DB::table('cache')
        ->where('expiration', '<', now()->timestamp)
        ->delete();
})->dailyAt('02:30')
    ->name('cache-table-cleanup')
    ->withoutOverlapping(10);
// expiresAt 10: one DELETE on expired cache rows.

// Clean old activity logs (>90 days) - weekly Sunday 03:30
Schedule::call(function () {
    DB::table('activity_logs')
        ->where('created_at', '<', now()->subDays(90))
        ->delete();
})->weekly()->sundays()->at('03:30')
    ->name('activity-logs-cleanup')->withoutOverlapping(10);
// expiresAt 10: one DELETE of >90d rows, weekly.

// Clean expired idempotency keys (>24h) - hourly
Schedule::command('idempotency:clean')
    ->hourly()
    ->withoutOverlapping(10)
    ->runInBackground();
// expiresAt 10: one DELETE of >24h keys (CleanIdempotencyKeys l.16-18).

// Ping database every 4 minutes to prevent Neon cold starts
Schedule::command('db:ping')->everyFourMinutes();

// Auto Account Delivery — เตือนงานค้างกดยืนยัน
// withoutOverlapping(10) ไม่ใช่ค่าปริยาย 1440 (ดูคอมเมนต์ด้านบน): เตือนงานค้าง
// ต้องกลับมาทำงานได้เร็วหลัง process โดนฆ่ากลางรัน ไม่งั้นตาข่ายสุดท้ายดับตอนที่ต้องใช้พอดี
// runInBackground กัน schedule ก้อนนี้ถูกบล็อกเมื่อการ์ดหลายใบต้องรอ Telegram ทีละ ~60 วิ
Schedule::command('delivery:remind')->everyThirtyMinutes()->withoutOverlapping(10)->runInBackground();
// expiresAt 10: per pending delivery a Telegram send can take up to ~90s
// (30s timeout × 2 retries, TelegramAlertBotService l.227) — kept at 10, and
// a second copy starting after 10 min only risks one duplicate reminder ping.

// Auto Account Delivery — ตรวจของค้าง/limbo ระหว่าง bot-fb กับ mhha_acc_db
Schedule::command('delivery:reconcile')->hourly()->withoutOverlapping(15);
// expiresAt 15: a few indexed queries + one external stock DB query + at most
// one Telegram send (30s timeout × 2 retries) — 15 min also absorbs a slow
// TCP connect to the stock DB.

// Truthful realtime health — probe Reverb + sample queue backlog into the
// snapshot /api/health/realtime serves. Runs INLINE (never on the Redis
// queue): when Redis or Reverb is down this check must still execute and
// record the snapshot, so the outside watchdog can alert the owner.
Schedule::command('health:check-realtime')
    ->everyMinute()
    ->withoutOverlapping(5);
// expiresAt 5: probe has a hard 3s timeout (RealtimeHealthProbe l.25) + at
// most 4 Redis size samples (2.5s cap each, config/database.php l.170-171) +
// 2 indexed counts — and a stale lock would freeze /api/health/realtime
// exactly like on 13 Nov 2026.

// Outage recovery (การ์ด t_44675510) — บอทกลับมาตอบได้แล้ว: ขอให้ลูกค้าที่ค้าง
// ตอนล่ม (แถวติด model_error="auth") พิมพ์ใหม่ แล้วสรุปยอดให้เจ้าของต่อบอท
// ห้องถูกจอง recovery_notice="sending" ก่อนส่ง จึงปลอดภัยแม้รันชนกันเอง
Schedule::command('outage:send-recovery-notices')->everyFiveMinutes()->withoutOverlapping(5);
// expiresAt 5: bounded by 24h of auth-tagged rows; recovery_notice='sending'
// is reserved before each push, so an early second copy can never
// double-message a room.

// Auto Account Delivery — เปิด/ปิดสวิตช์ขายตามของจริงใน stock DB
Schedule::command('stock:sync-pool')->everyFiveMinutes()->withoutOverlapping(5);
// expiresAt 5: one pool count query + per-product transactions (0.46s in prod,
// 13 Nov deploy log); two copies converge on the same counts anyway.

// Handover — เตือนแอดมินเมื่อลูกค้ารอคำตอบเกิน handover.alert_after_minutes นาที
// OFF since 11 Nov 2026 (owner decision, card t_94f5988f): admins answer in LINE OA,
// outside this system, so replies are never recorded and every alert this produced
// was a false "customer waiting for admin" Telegram ping. The command class
// (app/Console/Commands/AlertUnansweredHandover.php), config/handover.php and its
// tests are kept untouched. To re-enable, restore:
// Schedule::command('handover:alert-unanswered')->everyMinute()->withoutOverlapping(10)->runInBackground();

// Wave 2 reply phase timings — keep only 30 days of rows (card retention rule)
Schedule::command('replies:prune-timings')
    ->daily()
    ->at('03:40')
    ->withoutOverlapping(10)
    ->runInBackground();
// expiresAt 10: one DELETE of >30d rows (RepliesPruneTimings l.23-25).
