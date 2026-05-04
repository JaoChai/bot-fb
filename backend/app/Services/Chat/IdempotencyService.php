<?php

namespace App\Services\Chat;

use App\Exceptions\IdempotencyConflictException;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    public function check(string $key, string $endpoint, array $body): ?array
    {
        $bodyHash = $this->hashBody($body);

        $record = DB::table('idempotency_keys')
            ->where('id', $key)
            ->first();

        if (! $record) {
            return null;
        }

        if ($record->body_hash !== $bodyHash || $record->endpoint !== $endpoint) {
            throw new IdempotencyConflictException(
                'Idempotency key reused with different payload'
            );
        }

        return json_decode($record->response_payload, true);
    }

    public function store(string $key, string $endpoint, array $body, array $response): void
    {
        DB::table('idempotency_keys')->insertOrIgnore([
            'id' => $key,
            'endpoint' => $endpoint,
            'body_hash' => $this->hashBody($body),
            'response_payload' => json_encode($response),
            'created_at' => now(),
        ]);
    }

    private function hashBody(array $body): string
    {
        ksort($body);

        return hash('sha256', json_encode($body));
    }
}
