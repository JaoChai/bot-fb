<?php

namespace Tests\Feature;

use App\Services\Chat\IdempotencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private IdempotencyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new IdempotencyService();
    }

    public function test_first_request_returns_null(): void
    {
        $result = $this->service->check('test-uuid-1', '/api/test', ['content' => 'hello']);
        $this->assertNull($result);
    }

    public function test_stores_and_retrieves_response(): void
    {
        $key = 'test-uuid-2';
        $endpoint = '/api/test';
        $body = ['content' => 'hello'];
        $response = ['id' => 1, 'content' => 'hello'];

        $this->service->store($key, $endpoint, $body, $response);
        $cached = $this->service->check($key, $endpoint, $body);

        $this->assertNotNull($cached);
        $this->assertEquals($response, $cached);
    }

    public function test_same_key_different_body_returns_conflict(): void
    {
        $key = 'test-uuid-3';
        $endpoint = '/api/test';

        $this->service->store($key, $endpoint, ['content' => 'hello'], ['id' => 1]);

        $this->expectException(\App\Exceptions\IdempotencyConflictException::class);
        $this->service->check($key, $endpoint, ['content' => 'different']);
    }
}
