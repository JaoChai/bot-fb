<?php

namespace App\Http\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standardized API response methods for controllers.
 *
 * Provides consistent JSON response formatting with timestamps
 * and proper status codes across all API endpoints.
 */
trait ApiResponseTrait
{
    /**
     * Return a success response with data.
     *
     * @param  mixed  $data  The data to return (can be Resource, array, or any serializable value)
     * @param  string|null  $message  Optional success message
     * @param  int  $status  HTTP status code (default: 200)
     */
    protected function success(mixed $data, ?string $message = null, int $status = 200): JsonResponse
    {
        $response = [
            'data' => $data,
            'meta' => ['timestamp' => now()->toIso8601String()],
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        return response()->json($response, $status);
    }

    /**
     * Return a created response (201).
     *
     * @param  mixed  $data  The created resource data
     * @param  string  $message  Success message (default: 'Created successfully')
     */
    protected function created(mixed $data, string $message = 'Created successfully'): JsonResponse
    {
        return $this->success($data, $message, 201);
    }

    /**
     * Return a no content response (204).
     */
    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Return an error response.
     *
     * @param  string  $message  Error message
     * @param  int  $status  HTTP status code (default: 400)
     * @param  array  $additional  Additional data to include in response
     */
    protected function error(string $message, int $status = 400, array $additional = []): JsonResponse
    {
        $response = array_merge([
            'error' => $message,
            'meta' => ['timestamp' => now()->toIso8601String()],
        ], $additional);

        return response()->json($response, $status);
    }

    /**
     * Return a validation error response (422).
     *
     * @param  string  $message  Error message
     * @param  array  $errors  Validation errors array
     */
    protected function validationError(string $message, array $errors = []): JsonResponse
    {
        return $this->error($message, 422, ['errors' => $errors]);
    }

    /**
     * Return a not found error response (404).
     *
     * @param  string  $message  Error message (default: 'Resource not found')
     */
    protected function notFound(string $message = 'Resource not found'): JsonResponse
    {
        return $this->error($message, 404);
    }

    /**
     * Return a server error response (500).
     *
     * @param  string  $message  Error message (default: 'Internal server error')
     */
    protected function serverError(string $message = 'Internal server error'): JsonResponse
    {
        return $this->error($message, 500);
    }
}
