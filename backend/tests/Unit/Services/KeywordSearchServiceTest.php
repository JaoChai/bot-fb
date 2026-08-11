<?php

namespace Tests\Unit\Services;

use App\Services\KeywordSearchService;
use Illuminate\Database\Eloquent\Builder;
use ReflectionMethod;
use Tests\TestCase;

class KeywordSearchServiceTest extends TestCase
{
    /**
     * ทุก `?` ใน SQL ต้องมี binding คู่กันเสมอ ไม่งั้น PDO โยน SQLSTATE[HY093]
     * ตอนรันบน PostgreSQL (test suite ใช้ sqlite เลยไม่เห็น error นี้ตอน get())
     */
    public function test_every_placeholder_has_a_binding(): void
    {
        $query = $this->buildQuery();

        $this->assertSame(
            substr_count($query->toSql(), '?'),
            count($query->getBindings()),
            'จำนวน placeholder กับ binding ไม่ตรงกัน'
        );
    }

    public function test_bindings_are_in_sql_order(): void
    {
        // ลำดับตามที่ `?` โผล่ใน SQL: rank_score → knowledge_base_id → status → whereRaw
        $this->assertSame(['บัญชี bm', 7, 'completed', 'บัญชี bm'], $this->buildQuery()->getBindings());
    }

    protected function buildQuery(): Builder
    {
        $method = new ReflectionMethod(KeywordSearchService::class, 'buildSearchQuery');

        return $method->invoke(new KeywordSearchService, 7, 'บัญชี bm', 10);
    }
}
