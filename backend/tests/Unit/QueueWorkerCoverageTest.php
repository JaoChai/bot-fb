<?php

namespace Tests\Unit;

use App\Support\QueueRouter;
use PHPUnit\Framework\TestCase;

/**
 * กันบั๊ก "job ถูกส่งเข้าคิวที่ไม่มี worker ฟัง" ไม่ให้เกิดซ้ำ
 * (ExtractEntitiesJob ส่งเข้าคิว `low` ตั้งแต่ 18 มี.ค. 2026 แต่ไม่มี worker ตัวไหน
 * ฟังคิวนี้เลย — ฟีเจอร์ตายเงียบ 5 เดือน)
 *
 * เทสต์เดิม (ExtractEntitiesJobTest::test_job_is_queued_on_low_queue) เช็คแค่ฝั่ง
 * "ส่งเข้าคิวถูกไหม" ไม่มีใครเช็คว่า "มีคนมารับงานจากคิวนั้นไหม" เทสต์นี้ปิดช่องนั้น
 * โดยเทียบสองฝั่ง: คิวที่โค้ดสั่งงานเข้าไปจริง vs คิวที่ worker ใน Dockerfile ฟังอยู่
 */
class QueueWorkerCoverageTest extends TestCase
{
    private const DOCKERFILE = __DIR__.'/../../Dockerfile';

    private const APP_DIR = __DIR__.'/../../app';

    /**
     * ดึง set ของคิวที่มี worker (`artisan queue:work --queue=...`) ฟังอยู่จริงใน Dockerfile
     */
    private function queuesWithListeners(): array
    {
        $contents = file_get_contents(self::DOCKERFILE);
        $this->assertNotFalse($contents, 'อ่าน backend/Dockerfile ไม่ได้');

        $queues = [];

        foreach (explode("\n", $contents) as $line) {
            // เฉพาะบรรทัด supervisor command จริง (ไม่ใช่คอมเมนต์ที่แค่พูดถึง queue:work)
            if (! str_contains($line, 'command=') || ! str_contains($line, 'queue:work')) {
                continue;
            }

            if (preg_match('/--queue=([a-zA-Z0-9_,-]+)/', $line, $matches)) {
                foreach (explode(',', $matches[1]) as $queue) {
                    $queues[$queue] = true;
                }
            }
        }

        return array_keys($queues);
    }

    /**
     * ดึง set ของคิวที่โค้ด dispatch เข้าไปจริง
     *
     * ครอบ 2 แหล่ง:
     * 1. `onQueue('literal')` — grep string literal ตรง ๆ ทั่ว backend/app
     * 2. QueueRouter::llmQueue() — เป็น dynamic ไม่ใช่ literal (คืนค่าได้ทั้ง 'llm' และ
     *    'webhooks' ขึ้นกับ config queue.llm_split_enabled) grep จะมองไม่เห็นค่าที่มันคืนได้
     *    เลยต้องดึงจาก const ของ QueueRouter ตรง ๆ แทนที่จะ hardcode สตริงซ้ำในเทสต์
     */
    private function queuesDispatchedByCode(): array
    {
        $queues = [];

        // (1) literal onQueue('...') calls ทั่ว app/
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::APP_DIR, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (preg_match_all('/onQueue\(\s*[\'"]([a-zA-Z0-9_-]+)[\'"]\s*\)/', $contents, $matches)) {
                foreach ($matches[1] as $queue) {
                    $queues[$queue] = true;
                }
            }
        }

        // (2) ค่าที่ QueueRouter::llmQueue() คืนได้จริง — dynamic, grep เห็นไม่ได้
        $queues[QueueRouter::QUEUE_LLM] = true;
        $queues[QueueRouter::QUEUE_WEBHOOKS] = true;

        return array_keys($queues);
    }

    public function test_every_queue_dispatched_by_code_has_a_worker_listening(): void
    {
        $dispatched = $this->queuesDispatchedByCode();
        $listened = $this->queuesWithListeners();

        $orphaned = array_values(array_diff($dispatched, $listened));

        $this->assertSame(
            [],
            $orphaned,
            sprintf(
                "พบคิวที่โค้ดส่งงานเข้าไปแต่ไม่มี worker ตัวไหนใน backend/Dockerfile ฟังอยู่: %s\n".
                "worker ที่ฟังอยู่ตอนนี้: %s\n".
                'แก้โดยเพิ่มคิวที่ขาดเข้า --queue= ของ supervisor block ใดก็ได้ใน backend/Dockerfile',
                implode(', ', $orphaned),
                implode(', ', $listened)
            )
        );
    }

    public function test_dockerfile_and_code_scan_actually_found_something(): void
    {
        // sanity check กันเทสต์เขียวลอย ๆ เพราะ parse พัง (path ผิด/regex ไม่ match อะไรเลย)
        $this->assertNotEmpty($this->queuesWithListeners(), 'parse Dockerfile ไม่เจอ worker เลย — เทสต์นี้อาจพังโดยไม่รู้ตัว');
        $this->assertNotEmpty($this->queuesDispatchedByCode(), 'grep onQueue() ไม่เจอ dispatch เลย — เทสต์นี้อาจพังโดยไม่รู้ตัว');
        $this->assertContains('low', $this->queuesDispatchedByCode(), 'ExtractEntitiesJob ควร dispatch เข้าคิว low — ถ้าไม่เจอแปลว่า scan พัง');
    }
}
