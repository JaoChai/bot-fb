<?php

namespace Tests\Unit\Http\Traits;

use App\Http\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

class ApiResponseTraitTest extends TestCase
{
    use ApiResponseTrait;

    public function test_success_returns_json_response_with_data_and_meta(): void
    {
        $data = ['id' => 1, 'name' => 'Test'];
        $response = $this->success($data);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals($data, $content['data']);
        $this->assertArrayHasKey('meta', $content);
        $this->assertArrayHasKey('timestamp', $content['meta']);
    }

    public function test_success_with_message(): void
    {
        $data = ['id' => 1];
        $message = 'Operation successful';
        $response = $this->success($data, $message);

        $content = json_decode($response->getContent(), true);
        $this->assertEquals($message, $content['message']);
        $this->assertEquals($data, $content['data']);
    }

    public function test_success_with_custom_status(): void
    {
        $response = $this->success(['test' => true], null, 202);

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function test_created_returns_201_with_message(): void
    {
        $data = ['id' => 1];
        $response = $this->created($data, 'Resource created');

        $this->assertEquals(201, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Resource created', $content['message']);
        $this->assertEquals($data, $content['data']);
    }

    public function test_created_uses_default_message(): void
    {
        $response = $this->created(['id' => 1]);

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Created successfully', $content['message']);
    }

    public function test_no_content_returns_204(): void
    {
        $response = $this->noContent();

        $this->assertEquals(204, $response->getStatusCode());
    }

    public function test_error_returns_json_with_error_message(): void
    {
        $message = 'Something went wrong';
        $response = $this->error($message);

        $this->assertEquals(400, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals($message, $content['error']);
        $this->assertArrayHasKey('meta', $content);
        $this->assertArrayHasKey('timestamp', $content['meta']);
    }

    public function test_error_with_custom_status(): void
    {
        $response = $this->error('Not found', 404);

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function test_error_with_additional_data(): void
    {
        $response = $this->error('Validation failed', 400, [
            'field' => 'email',
            'code' => 'INVALID_FORMAT',
        ]);

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('email', $content['field']);
        $this->assertEquals('INVALID_FORMAT', $content['code']);
    }

    public function test_validation_error_returns_422(): void
    {
        $errors = ['email' => ['The email field is required.']];
        $response = $this->validationError('Validation failed', $errors);

        $this->assertEquals(422, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Validation failed', $content['error']);
        $this->assertEquals($errors, $content['errors']);
    }

    public function test_not_found_returns_404(): void
    {
        $response = $this->notFound();

        $this->assertEquals(404, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Resource not found', $content['error']);
    }

    public function test_not_found_with_custom_message(): void
    {
        $response = $this->notFound('User not found');

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('User not found', $content['error']);
    }

    public function test_server_error_returns_500(): void
    {
        $response = $this->serverError();

        $this->assertEquals(500, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Internal server error', $content['error']);
    }

    public function test_server_error_with_custom_message(): void
    {
        $response = $this->serverError('Database connection failed');

        $content = json_decode($response->getContent(), true);
        $this->assertEquals('Database connection failed', $content['error']);
    }

    public function test_timestamp_is_iso8601_format(): void
    {
        $response = $this->success(['test' => true]);

        $content = json_decode($response->getContent(), true);
        $timestamp = $content['meta']['timestamp'];

        // Verify it's a valid ISO 8601 timestamp
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $timestamp
        );
    }
}
