<?php

namespace Tests\Unit\Config;

use App\Jobs\EvaluateVipStatusJob;
use App\Jobs\ExtractEntitiesJob;
use App\Jobs\MarkStockSold;
use App\Jobs\ProcessLeadRecovery;
use App\Jobs\ReserveAccountStock;
use App\Jobs\RetrySlipVerification;
use App\Jobs\SendDelayedBubbleJob;
use App\Jobs\SendDeliveryCard;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;

/**
 * กันบั๊ก "retry_after < $timeout ของ job → job ยังรันอยู่ แต่ถูก reserve ซ้ำ
 * → ตอบลูกค้าซ้ำ" ไม่ให้เกิดซ้ำ
 *
 * Laravel worker เรียก reserve ทุก retry_after วินาที ถ้า retry_after น้อยกว่า
 * $timeout ของ job ที่ยังรันอยู่ job เดียวกันจะถูกส่งให้ worker ตัวอื่นซ้ำ
 * config/queue.php จึงต้องตั้ง default retry_after ของทุก connection ที่มี worker
 * (database, redis) ให้มากกว่า $timeout ที่มากที่สุดของ job ที่ถูก dispatch
 * เข้าคิวทั้งหมดใน app/Jobs เสมอ
 *
 * ค่าจริงใน production มาจาก env (DB_QUEUE_RETRY_AFTER / REDIS_QUEUE_RETRY_AFTER)
 * เทสต์นี้อ่านแค่ค่า default ในไฟล์ config — safety net กรณี env หาย
 */
class QueueRetryAfterTest extends TestCase
{
    /**
     * Laravel's Worker::handleJobTimeout — job ที่ไม่ประกาศ $timeout ถูกบังคับที่ 60 วิ
     */
    private const LARAVEL_JOB_TIMEOUT_DEFAULT = 60;

    /**
     * job ที่มีอยู่ก่อนเทสต์นี้และยังไม่ประกาศ $timeout (ยังไม่ได้แก้ — อยู่นอก
     * scope ของการ์ดนี้) job ใหม่ห้ามเพิ่มในลิสต์นี้: ประกาศ $timeout ในคลาส
     * แล้วเทสต์จะพาผ่านเอง ถ้าลบชื่อออกจากลิสต์ทั้งที่คลาสยังไม่ประกาศ timeout
     * เทสต์จะแดงทันที
     *
     * @var array<int, class-string>
     */
    private const KNOWN_JOBS_WITHOUT_TIMEOUT = [
        EvaluateVipStatusJob::class,
        ExtractEntitiesJob::class,
        MarkStockSold::class,
        ProcessLeadRecovery::class,
        ReserveAccountStock::class,
        RetrySlipVerification::class,
        SendDelayedBubbleJob::class,
        SendDeliveryCard::class,
    ];

    /**
     * @return array<string, array{driver: string, retry_after: int}>
     */
    private function queueConnectionsWithRetryAfter(): array
    {
        $config = require __DIR__.'/../../../config/queue.php';

        $connections = [];

        foreach ($config['connections'] as $name => $connection) {
            if (isset($connection['retry_after'])) {
                $connections[$name] = [
                    'driver' => $connection['driver'],
                    'retry_after' => (int) $connection['retry_after'],
                ];
            }
        }

        return $connections;
    }

    /**
     * $timeout ที่มากที่สุดของ job ที่ถูก dispatch เข้าคิวจริง (อ่านผ่าน reflection
     * ไม่ hardcode ตัวเลข — ใหม่ job เพิ่ม timeout โดยไม่ปรับ config ต้องแดงทันที)
     *
     * job ที่ไม่ประกาศ $timeout เป็นบั๊กเงียบ ๆ: Laravel จะบังคับ timeout 60 วิ
     * อัตโนมัติ เราจึงผูกให้ทุก job ประกาศ timeout ชัดเจน และตัวที่ไม่ประกาศ
     * ถูกนับที่ค่า default 60 ใน max — ห้ามมี queued job หลุดจากการตรวจ
     *
     * @return array{max_timeout: int, jobs_without_timeout: array<int, string>}
     */
    private function largestQueuedJobTimeout(): array
    {
        $maxTimeout = self::LARAVEL_JOB_TIMEOUT_DEFAULT;
        $jobsWithoutTimeout = [];

        foreach (glob(__DIR__.'/../../../app/Jobs/*.php') ?: [] as $file) {
            $class = 'App\\Jobs\\'.basename($file, '.php');

            require_once $file;

            $reflection = new \ReflectionClass($class);

            if (! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            $timeout = $reflection->getDefaultProperties()['timeout'] ?? null;

            if (is_int($timeout)) {
                $maxTimeout = max($maxTimeout, $timeout);
            } else {
                $jobsWithoutTimeout[] = $class;
            }
        }

        return ['max_timeout' => $maxTimeout, 'jobs_without_timeout' => $jobsWithoutTimeout];
    }

    public function test_retry_after_defaults_exceed_largest_queued_job_timeout(): void
    {
        ['max_timeout' => $maxTimeout, 'jobs_without_timeout' => $jobsWithoutTimeout] = $this->largestQueuedJobTimeout();

        // job ที่ไม่ประกาศ $timeout ยังถูกนับใน max ที่ default 60 อยู่แล้ว
        // (ครอบคลุมเชิงตัวเลข) — assertion นี้กัน "job หลุดจากการตรวจ" เงียบ ๆ:
        // job ใหม่ที่ไม่ประกาศ timeout และไม่ได้อยู่ในรายชื่อ legacy ต้องแดง
        // จนกว่าจะประกาศ timeout (หรือขึ้นทะเบียนไว้ชัดเจนว่ายอมรับ 60 วิ)
        $unregistered = array_diff($jobsWithoutTimeout, self::KNOWN_JOBS_WITHOUT_TIMEOUT);

        $this->assertSame([], $unregistered, 'Queued job ใหม่ที่ไม่ประกาศ $timeout ต้องประกาศ timeout ในคลาส (หรือขึ้นทะเบียนใน KNOWN_JOBS_WITHOUT_TIMEOUT อย่างชัดเจน) — Laravel จะบังคับ default 60 วิให้ job ที่เงียบ ทำให้ตรวจ retry_after ไม่ครบ');

        foreach ($this->queueConnectionsWithRetryAfter() as $name => $connection) {
            $this->assertGreaterThan(
                $maxTimeout,
                $connection['retry_after'],
                "queue connection [{$name}] (driver {$connection['driver']}) ต้องมี default retry_after > {$maxTimeout} (largest queued \$timeout) ไม่งั้น job ที่ยังรันอยู่จะถูก reserve ซ้ำ"
            );
        }
    }

    public function test_known_jobs_without_timeout_list_stays_accurate(): void
    {
        ['jobs_without_timeout' => $jobsWithoutTimeout] = $this->largestQueuedJobTimeout();

        // ลบ/แก้คลาสใน KNOWN_JOBS_WITHOUT_TIMEOUT ทั้งที่ยังไม่ประกาศ $timeout → แดง
        $this->assertSame(
            [],
            array_diff(self::KNOWN_JOBS_WITHOUT_TIMEOUT, $jobsWithoutTimeout),
            'KNOWN_JOBS_WITHOUT_TIMEOUT มีชื่อ job ที่ประกาศ $timeout แล้ว — ลบออกจากลิสต์ได้'
        );

        // ประกาศ $timeout ครบทุกตัวแล้ว → ลบลิสต์ทิ้งได้ (แดงเพื่อเตือนให้เก็บกวาด)
        $this->assertNotSame(
            [],
            $jobsWithoutTimeout,
            'Queued job ทุกตัวประกาศ $timeout ครบแล้ว — ลบ KNOWN_JOBS_WITHOUT_TIMEOUT และ logic ของลิสต์ออกจากเทสต์นี้ได้'
        );
    }
}
